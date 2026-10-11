<?php

declare(strict_types=1);

/** Read-only queries. Every value bound to SQL goes through a prepared statement. */
final class DictionaryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function entry(string $slug): ?array
    {
        $word = $this->pdo->prepare(
            'SELECT id, slug, headword, is_common, freq_rank FROM words WHERE slug = ?'
        );
        $word->execute([$slug]);
        $row = $word->fetch();
        if ($row === false) {
            return null;
        }
        $wordId = (int) $row['id'];
        $rank = $row['freq_rank'] === null ? null : (int) $row['freq_rank'];
        $entries = $this->pdo->prepare(
            "SELECT id, pos, etymology, source
             FROM entries
             WHERE word_id = ?
             ORDER BY CASE source WHEN 'wiktionary' THEN 0 WHEN 'wordnet' THEN 1 WHEN 'moby' THEN 2 ELSE 3 END, id"
        );
        $entries->execute([$wordId]);
        $blocks = [];
        $sources = [];
        $ipa = null;
        $audio = null;
        $senseStmt = $this->pdo->prepare(
            'SELECT gloss, example, example_source, sense_order
             FROM senses WHERE entry_id = ? ORDER BY sense_order, id'
        );
        $pronStmt = $this->pdo->prepare(
            'SELECT ipa, audio_url, source FROM pronunciations WHERE entry_id = ? ORDER BY id'
        );
        $relStmt = $this->pdo->prepare(
            "SELECT type, target, source FROM relations
             WHERE entry_id = ? AND type IN ('synonym','antonym','hypernym','related')
             ORDER BY CASE source WHEN 'wiktionary' THEN 0 WHEN 'wordnet' THEN 1 ELSE 2 END, id"
        );
        foreach ($entries as $entry) {
            $entryId = (int) $entry['id'];
            $sources[(string) $entry['source']] = true;
            $senseStmt->execute([$entryId]);
            $senses = [];
            foreach ($senseStmt as $sense) {
                $senses[] = [
                    'n' => count($senses) + 1,
                    'gloss' => (string) $sense['gloss'],
                    'example' => $sense['example'] !== null && $sense['example'] !== '' ? (string) $sense['example'] : null,
                    'example_source' => $sense['example_source'] !== null ? (string) $sense['example_source'] : null,
                ];
            }
            if ($senses === []) {
                continue;
            }
            $pronStmt->execute([$entryId]);
            foreach ($pronStmt as $pron) {
                if ($ipa === null && is_string($pron['ipa']) && $pron['ipa'] !== '') {
                    $ipa = (string) $pron['ipa'];
                }
                if ($audio === null) {
                    $clean = Slug::httpsAudio(is_string($pron['audio_url']) ? $pron['audio_url'] : null);
                    if ($clean !== null) {
                        $audio = $clean;
                    }
                }
                if (!empty($pron['source'])) {
                    $sources[(string) $pron['source']] = true;
                }
            }
            $relStmt->execute([$entryId]);
            $relations = ['synonym' => [], 'antonym' => [], 'hypernym' => [], 'related' => []];
            foreach ($relStmt as $rel) {
                $type = (string) $rel['type'];
                if (!isset($relations[$type]) || count($relations[$type]) >= 16) {
                    continue;
                }
                $target = (string) $rel['target'];
                $targetSlug = Slug::fromHeadword($target);
                $relations[$type][] = [
                    'target' => $target,
                    'slug' => $targetSlug !== null && $this->exists($targetSlug) ? $targetSlug : null,
                ];
                $sources[(string) $rel['source']] = true;
            }
            $blocks[] = [
                'pos' => (string) $entry['pos'],
                'etymology' => $entry['etymology'] !== null && $entry['etymology'] !== '' ? (string) $entry['etymology'] : null,
                'source' => (string) $entry['source'],
                'senses' => $senses,
                'relations' => $relations,
            ];
        }
        if ($blocks === []) {
            return null;
        }
        $nearby = $this->nearby($slug, 5);
        return [
            'slug' => (string) $row['slug'],
            'headword' => (string) $row['headword'],
            'is_common' => (int) $row['is_common'] === 1,
            'freq_rank' => $rank,
            'band' => Slug::frequencyBand($rank),
            'ipa' => $ipa,
            'audio' => $audio,
            'entries' => $blocks,
            'nearby_before' => $nearby['before'],
            'nearby_after' => $nearby['after'],
            'sources' => array_keys($sources),
        ];
    }

    /** @return list<array{slug:string,headword:string}> */
    public function suggest(string $prefix, int $limit = 8): array
    {
        $prefix = mb_strtolower(trim($prefix), 'UTF-8');
        if ($prefix === '' || mb_strlen($prefix) > 40) {
            return [];
        }
        $upper = Slug::prefixUpperBound($prefix);
        if ($upper === null) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT slug, headword FROM words
             WHERE slug >= ? AND slug < ?
             ORDER BY freq_rank IS NULL, freq_rank, slug
             LIMIT ?'
        );
        $stmt->bindValue(1, $prefix);
        $stmt->bindValue(2, $upper);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $this->pairs($stmt);
    }

    /**
     * 404 suggestions: full-text match, then a headword prefix, then the longest token.
     *
     * @return list<array{slug:string,headword:string}>
     */
    public function missingSuggestions(string $attempt, int $limit = 8): array
    {
        $found = $this->search($attempt, $limit);
        if ($found !== []) {
            return $found;
        }
        $found = $this->suggest($attempt, $limit);
        if ($found !== []) {
            return $found;
        }
        $tokens = Slug::tokens($attempt);
        usort($tokens, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_slice($tokens, 0, 3) as $token) {
            if ($token === $attempt) {
                continue;
            }
            $found = $this->suggest($token, $limit);
            if ($found === []) {
                $found = $this->search($token, $limit);
            }
            if ($found !== []) {
                return $found;
            }
        }
        return [];
    }

    /** @return list<array{slug:string,headword:string}> */
    public function search(string $query, int $limit = 20): array
    {
        $tokens = Slug::tokens($query);
        if ($tokens === []) {
            return [];
        }
        $parts = [];
        foreach ($tokens as $token) {
            $parts[] = '"' . str_replace('"', '""', $token) . '"*';
        }
        $stmt = $this->pdo->prepare(
            'SELECT slug, headword, MIN(rank) AS r
             FROM glossary_fts
             WHERE glossary_fts MATCH ?
             GROUP BY slug
             ORDER BY r
             LIMIT ?'
        );
        $stmt->bindValue(1, implode(' AND ', $parts));
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $this->pairs($stmt);
    }

    /** @return array{before:list<array{slug:string,headword:string}>,after:list<array{slug:string,headword:string}>} */
    public function nearby(string $slug, int $count = 5): array
    {
        $before = $this->pdo->prepare(
            'SELECT slug, headword FROM words WHERE slug < ? ORDER BY slug DESC LIMIT ?'
        );
        $before->bindValue(1, $slug);
        $before->bindValue(2, $count, PDO::PARAM_INT);
        $before->execute();
        $beforeRows = array_reverse($this->pairs($before));
        $after = $this->pdo->prepare(
            'SELECT slug, headword FROM words WHERE slug > ? ORDER BY slug ASC LIMIT ?'
        );
        $after->bindValue(1, $slug);
        $after->bindValue(2, $count, PDO::PARAM_INT);
        $after->execute();
        return ['before' => $beforeRows, 'after' => $this->pairs($after)];
    }

    /** @return array{slug:string,headword:string,gloss:string}|null */
    public function wordOfTheDay(string $ymd): ?array
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM words WHERE is_common = 1')->fetchColumn();
        $common = true;
        if ($count < 1) {
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM words')->fetchColumn();
            $common = false;
            if ($count < 1) {
                return null;
            }
        }
        $offset = hexdec(substr(hash('sha256', $ymd), 0, 8)) % $count;
        $sql = $common
            ? 'SELECT slug, headword FROM words WHERE is_common = 1 ORDER BY slug LIMIT 1 OFFSET ?'
            : 'SELECT slug, headword FROM words ORDER BY slug LIMIT 1 OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $gloss = $this->pdo->prepare(
            'SELECT s.gloss FROM senses s
             JOIN entries e ON e.id = s.entry_id
             JOIN words w ON w.id = e.word_id
             WHERE w.slug = ?
             ORDER BY s.sense_order, s.id
             LIMIT 1'
        );
        $gloss->execute([$row['slug']]);
        $text = $gloss->fetchColumn();
        return [
            'slug' => (string) $row['slug'],
            'headword' => (string) $row['headword'],
            'gloss' => is_string($text) ? $text : '',
        ];
    }

    /**
     * @return array{rows:list<array{slug:string,headword:string}>,next:?string}
     */
    public function browse(string $letter, string $after, int $limit = 120): array
    {
        [$start, $end] = $this->letterRange($letter);
        if ($after !== '' && (!Slug::isValid($after) || $after < $start || $after >= $end)) {
            $after = '';
        }
        if ($after !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT slug, headword FROM words
                 WHERE slug > ? AND slug < ?
                 ORDER BY slug
                 LIMIT ?'
            );
            $stmt->bindValue(1, $after);
            $stmt->bindValue(2, $end);
            $stmt->bindValue(3, $limit + 1, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT slug, headword FROM words
                 WHERE slug >= ? AND slug < ?
                 ORDER BY slug
                 LIMIT ?'
            );
            $stmt->bindValue(1, $start);
            $stmt->bindValue(2, $end);
            $stmt->bindValue(3, $limit + 1, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $this->pairs($stmt);
        $next = null;
        if (count($rows) > $limit) {
            $next = $rows[$limit - 1]['slug'];
            $rows = array_slice($rows, 0, $limit);
        }
        return ['rows' => $rows, 'next' => $next];
    }

    public function exists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM words WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array{0:string,1:string} */
    private function letterRange(string $letter): array
    {
        if ($letter === '0') {
            return ['0', 'a'];
        }
        $end = Slug::prefixUpperBound($letter) ?? '{';
        return [$letter, $end];
    }

    /** @return list<array{slug:string,headword:string}> */
    private function pairs(PDOStatement $stmt): array
    {
        $rows = [];
        foreach ($stmt as $row) {
            $rows[] = [
                'slug' => (string) $row['slug'],
                'headword' => (string) $row['headword'],
            ];
        }
        return $rows;
    }
}
