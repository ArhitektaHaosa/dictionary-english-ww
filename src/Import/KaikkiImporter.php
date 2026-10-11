<?php

declare(strict_types=1);

/** Streams Kaikki.org English JSONL. One object per line, low memory, resumable by byte offset. */
final class KaikkiImporter
{
    private const SOURCE = 'wiktionary';
    private const BATCH = 250;

    private PDOStatement $findWord;
    private PDOStatement $insertWord;
    private PDOStatement $findEntry;
    private PDOStatement $insertEntry;
    private PDOStatement $maxSense;
    private PDOStatement $insertSense;
    private PDOStatement $findPron;
    private PDOStatement $insertPron;
    private PDOStatement $countRel;
    private PDOStatement $insertRel;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Meta $meta,
    ) {
        $this->findWord = $pdo->prepare('SELECT id FROM words WHERE slug = ?');
        $this->insertWord = $pdo->prepare(
            'INSERT INTO words (slug, headword, is_common, freq_rank) VALUES (?, ?, 0, NULL)'
        );
        $this->findEntry = $pdo->prepare(
            'SELECT id FROM entries WHERE word_id = ? AND pos = ? AND source = ? AND etymology_number = ?'
        );
        $this->insertEntry = $pdo->prepare(
            'INSERT INTO entries (word_id, pos, etymology, source, etymology_number) VALUES (?, ?, ?, ?, ?)'
        );
        $this->maxSense = $pdo->prepare('SELECT COALESCE(MAX(sense_order), 0) FROM senses WHERE entry_id = ?');
        $this->insertSense = $pdo->prepare(
            'INSERT OR IGNORE INTO senses (entry_id, gloss, example, example_source, sense_order) VALUES (?, ?, ?, ?, ?)'
        );
        $this->findPron = $pdo->prepare(
            "SELECT 1 FROM pronunciations WHERE entry_id = ? AND IFNULL(ipa, '') = ? AND IFNULL(audio_url, '') = ? LIMIT 1"
        );
        $this->insertPron = $pdo->prepare(
            'INSERT INTO pronunciations (entry_id, ipa, audio_url, source) VALUES (?, ?, ?, ?)'
        );
        $this->countRel = $pdo->prepare('SELECT COUNT(*) FROM relations WHERE entry_id = ? AND type = ?');
        $this->insertRel = $pdo->prepare(
            'INSERT OR IGNORE INTO relations (entry_id, type, target, source) VALUES (?, ?, ?, ?)'
        );
    }

    public function unpack(string $downloadPath, string $jsonlPath): string
    {
        $handle = fopen($downloadPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read ' . $downloadPath);
        }
        $magic = fread($handle, 2);
        fclose($handle);
        if ($magic !== "\x1f\x8b") {
            return $downloadPath;
        }
        $remoteSize = (string) filesize($downloadPath);
        if (is_file($jsonlPath) && $this->meta->get('kaikki_unpacked_from') === $remoteSize && filesize($jsonlPath) > 0) {
            fwrite(STDOUT, "unpack: using existing JSONL\n");
            return $jsonlPath;
        }
        fwrite(STDOUT, "unpack: decompressing Kaikki JSONL\n");
        $in = fopen('compress.zlib://' . $downloadPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('gzip open failed for ' . $downloadPath);
        }
        $out = fopen($jsonlPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Cannot write ' . $jsonlPath);
        }
        $written = 0;
        $started = microtime(true);
        while (!feof($in)) {
            $chunk = fread($in, 1024 * 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $n = fwrite($out, $chunk);
            if ($n === false) {
                throw new RuntimeException('Write failed while unpacking Kaikki');
            }
            $written += $n;
            if ($written % (32 * 1024 * 1024) < 1024 * 1024) {
                $rate = $written / max(0.001, microtime(true) - $started);
                fwrite(STDERR, sprintf("\runpack        %d MB  %.1f MB/s   ", (int) ($written / 1048576), $rate / 1048576));
            }
        }
        fclose($in);
        fclose($out);
        fwrite(STDERR, "\n");
        $this->meta->set('kaikki_unpacked_from', $remoteSize);
        $this->meta->set('kaikki_offset', '0');
        fwrite(STDOUT, "unpack: wrote {$jsonlPath}\n");
        return $jsonlPath;
    }

    public function run(string $jsonlPath): void
    {
        $size = filesize($jsonlPath);
        if ($size === false || $size < 1) {
            throw new RuntimeException('Kaikki JSONL is missing or empty: ' . $jsonlPath);
        }
        $known = $this->meta->get('kaikki_bytes');
        if ($known !== null && $known !== (string) $size) {
            fwrite(STDOUT, "Kaikki file changed; clearing previous Wiktionary rows.\n");
            SourceClean::entries($this->pdo, self::SOURCE);
            $this->meta->set('kaikki_offset', '0');
            foreach (['phase_wordnet', 'phase_moby', 'phase_tatoeba', 'phase_cmu', 'phase_frequency', 'phase_fts'] as $flag) {
                $this->meta->set($flag, 'reset');
            }
        }
        $this->meta->set('kaikki_bytes', (string) $size);
        $offset = (int) ($this->meta->get('kaikki_offset') ?? '0');
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }
        if ($offset === $size) {
            fwrite(STDOUT, "kaikki: already complete\n");
            return;
        }
        $fh = fopen($jsonlPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open ' . $jsonlPath);
        }
        if ($offset > 0 && fseek($fh, $offset) !== 0) {
            fclose($fh);
            throw new RuntimeException('Cannot resume Kaikki import at byte ' . $offset);
        }
        $progress = new Progress('kaikki', $size);
        $this->pdo->beginTransaction();
        $batch = 0;
        $kept = 0;
        while (true) {
            $line = $this->readLine($fh);
            if ($line === false) {
                break;
            }
            if ($line !== '' && $this->ingest($line)) {
                $kept++;
            }
            $batch++;
            if ($batch >= self::BATCH) {
                $pos = (int) ftell($fh);
                $this->meta->set('kaikki_offset', (string) $pos);
                $this->pdo->commit();
                $progress->tick($pos, 'kept ' . $kept);
                $this->pdo->beginTransaction();
                $batch = 0;
            }
        }
        $pos = (int) ftell($fh);
        $this->meta->set('kaikki_offset', (string) $pos);
        $this->pdo->commit();
        fclose($fh);
        $progress->tick($size, 'kept ' . $kept);
        $progress->finish('finished, rows touched ' . $kept);
    }

    private function readLine($fh): string|false
    {
        $line = stream_get_line($fh, 4_000_000, "\n");
        if ($line === false) {
            return false;
        }
        if (strlen($line) === 4_000_000) {
            stream_get_line($fh, 16_000_000, "\n");
            return '';
        }
        return $line;
    }

    private function ingest(string $line): bool
    {
        $obj = json_decode($line, true);
        if (!is_array($obj) || ($obj['lang_code'] ?? '') !== 'en') {
            return false;
        }
        $headword = $obj['word'] ?? '';
        if (!is_string($headword)) {
            return false;
        }
        $slug = Slug::fromHeadword($headword);
        if ($slug === null) {
            return false;
        }
        $pos = strtolower(trim((string) ($obj['pos'] ?? '')));
        if ($pos === '' || strlen($pos) > 32) {
            return false;
        }
        $senses = $this->senses($obj['senses'] ?? []);
        if ($senses === []) {
            return false;
        }
        $wordId = $this->wordId($slug, Slug::plain($headword, 80));
        $etymNo = (int) ($obj['etymology_number'] ?? 0);
        $etym = '';
        if (is_string($obj['etymology_text'] ?? null)) {
            $etym = Slug::plain($obj['etymology_text'], 2000);
        }
        $entryId = $this->entryId($wordId, $pos, $etym !== '' ? $etym : null, $etymNo);
        $this->maxSense->execute([$entryId]);
        $order = (int) $this->maxSense->fetchColumn();
        $added = 0;
        foreach ($senses as $sense) {
            if ($order >= 20) {
                break;
            }
            $order++;
            $this->insertSense->execute([
                $entryId,
                $sense['gloss'],
                $sense['example'],
                $sense['example'] !== null ? 'wiktionary' : null,
                $order,
            ]);
            $added++;
        }
        $this->storeSounds($entryId, $obj['sounds'] ?? []);
        $this->storeRelations($entryId, $obj);
        return $added > 0;
    }

    /** @param mixed $raw @return list<array{gloss:string,example:?string}> */
    private function senses(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $sense) {
            if (!is_array($sense)) {
                continue;
            }
            $glosses = [];
            foreach ($sense['glosses'] ?? [] as $gloss) {
                if (is_string($gloss) && trim($gloss) !== '') {
                    $glosses[] = Slug::plain($gloss, 500);
                }
            }
            $glosses = array_values(array_unique($glosses));
            if ($glosses === []) {
                continue;
            }
            $example = null;
            foreach ($sense['examples'] ?? [] as $item) {
                $text = is_array($item) ? ($item['text'] ?? null) : $item;
                if (is_string($text) && trim($text) !== '') {
                    $example = Slug::plain($text, 400);
                    break;
                }
            }
            $out[] = ['gloss' => Slug::plain(implode('; ', $glosses), 800), 'example' => $example];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    /** @param mixed $sounds */
    private function storeSounds(int $entryId, mixed $sounds): void
    {
        if (!is_array($sounds)) {
            return;
        }
        $ipa = null;
        $ipaLocked = false;
        $audio = null;
        foreach ($sounds as $sound) {
            if (!is_array($sound)) {
                continue;
            }
            if (!$ipaLocked && is_string($sound['ipa'] ?? null) && trim($sound['ipa']) !== '') {
                $tags = strtolower(implode(' ', is_array($sound['tags'] ?? null) ? $sound['tags'] : []));
                $ipa = Slug::plain($sound['ipa'], 80);
                if (str_contains($tags, 'general-american') || str_contains($tags, 'received-pronunciation')) {
                    $ipaLocked = true;
                }
            }
            if ($audio === null) {
                foreach (['mp3_url', 'ogg_url'] as $key) {
                    $clean = Slug::httpsAudio(is_string($sound[$key] ?? null) ? $sound[$key] : null);
                    if ($clean !== null) {
                        $audio = $clean;
                        break;
                    }
                }
            }
        }
        if ($ipa === null && $audio === null) {
            return;
        }
        $this->findPron->execute([$entryId, $ipa ?? '', $audio ?? '']);
        if ($this->findPron->fetchColumn() === false) {
            $this->insertPron->execute([$entryId, $ipa, $audio, self::SOURCE]);
        }
    }

    /** @param array<string, mixed> $obj */
    private function storeRelations(int $entryId, array $obj): void
    {
        $buckets = [
            'synonym' => $obj['synonyms'] ?? [],
            'antonym' => $obj['antonyms'] ?? [],
            'hypernym' => $obj['hypernyms'] ?? [],
            'related' => $obj['related'] ?? [],
        ];
        foreach ($obj['senses'] ?? [] as $sense) {
            if (!is_array($sense)) {
                continue;
            }
            foreach (['synonyms' => 'synonym', 'antonyms' => 'antonym', 'hypernyms' => 'hypernym', 'related' => 'related'] as $key => $type) {
                if (isset($sense[$key]) && is_array($sense[$key])) {
                    foreach ($sense[$key] as $item) {
                        $buckets[$type][] = $item;
                    }
                }
            }
        }
        foreach ($buckets as $type => $items) {
            if (!is_array($items)) {
                continue;
            }
            $this->countRel->execute([$entryId, $type]);
            $have = (int) $this->countRel->fetchColumn();
            $seen = [];
            foreach ($items as $item) {
                if ($have >= 20) {
                    break;
                }
                $word = is_array($item) ? ($item['word'] ?? '') : $item;
                if (!is_string($word)) {
                    continue;
                }
                $word = Slug::plain($word, 60);
                $key = mb_strtolower($word, 'UTF-8');
                if ($word === '' || isset($seen[$key]) || !preg_match("/^[\\p{L}\\p{N}][\\p{L}\\p{N} '\\-]{0,48}$/u", $word)) {
                    continue;
                }
                $seen[$key] = true;
                $this->insertRel->execute([$entryId, $type, $word, self::SOURCE]);
                $have++;
            }
        }
    }

    private function wordId(string $slug, string $headword): int
    {
        $this->findWord->execute([$slug]);
        $id = $this->findWord->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->insertWord->execute([$slug, $headword]);
        return (int) $this->pdo->lastInsertId();
    }

    private function entryId(int $wordId, string $pos, ?string $etymology, int $etymNo): int
    {
        $this->findEntry->execute([$wordId, $pos, self::SOURCE, $etymNo]);
        $id = $this->findEntry->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->insertEntry->execute([$wordId, $pos, $etymology, self::SOURCE, $etymNo]);
        return (int) $this->pdo->lastInsertId();
    }
}
