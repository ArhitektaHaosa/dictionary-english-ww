<?php

declare(strict_types=1);

final class MobyImporter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(string $path): void
    {
        SourceClean::relations($this->pdo, 'moby');
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read Moby Thesaurus: ' . $path);
        }
        $find = $this->pdo->prepare('SELECT e.id FROM entries e JOIN words w ON w.id = e.word_id WHERE w.slug = ? ORDER BY e.id');
        $insert = $this->pdo->prepare(
            "INSERT OR IGNORE INTO relations (entry_id, type, target, source) VALUES (?, 'synonym', ?, 'moby')"
        );
        $this->pdo->beginTransaction();
        $lines = 0;
        $added = 0;
        while (($line = stream_get_line($fh, 2_000_000, "\n")) !== false) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ',')) {
                continue;
            }
            $parts = explode(',', $line);
            $root = Slug::fromHeadword((string) array_shift($parts));
            if ($root === null) {
                continue;
            }
            $find->execute([$root]);
            $ids = $find->fetchAll(PDO::FETCH_COLUMN);
            if ($ids === []) {
                continue;
            }
            $seen = [];
            $count = 0;
            foreach ($parts as $part) {
                if ($count >= 12) {
                    break;
                }
                $target = Slug::plain(trim($part), 40);
                $key = mb_strtolower($target, 'UTF-8');
                if ($target === '' || isset($seen[$key]) || $key === $root) {
                    continue;
                }
                if (preg_match("/^[\\p{L}][\\p{L} '\\-]{0,36}$/u", $target) !== 1) {
                    continue;
                }
                $seen[$key] = true;
                foreach ($ids as $id) {
                    $insert->execute([(int) $id, $target]);
                    $added++;
                }
                $count++;
            }
            $lines++;
            if ($lines % 500 === 0) {
                $this->pdo->commit();
                fwrite(STDERR, "\rmoby         roots {$lines}");
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
        fclose($fh);
        fwrite(STDERR, "\n");
        fwrite(STDOUT, "moby: synonym links {$added}\n");
    }
}

final class TatoebaImporter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(string $path): void
    {
        $need = [];
        $stmt = $this->pdo->query(
            "SELECT w.id, w.slug FROM words w
             WHERE EXISTS (
               SELECT 1 FROM senses s
               JOIN entries e ON e.id = s.entry_id
               WHERE e.word_id = w.id AND (s.example IS NULL OR s.example = '')
             )"
        );
        if ($stmt === false) {
            return;
        }
        foreach ($stmt as $row) {
            $need[(string) $row['slug']] = (int) $row['id'];
        }
        fwrite(STDOUT, 'tatoeba: words missing an example: ' . count($need) . "\n");
        if ($need === []) {
            return;
        }
        $fh = fopen('compress.bzip2://' . $path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open Tatoeba sentences (bz2 extension required): ' . $path);
        }
        $update = $this->pdo->prepare(
            "UPDATE senses SET example = ?, example_source = ?
             WHERE id = (
               SELECT s.id FROM senses s
               JOIN entries e ON e.id = s.entry_id
               WHERE e.word_id = ? AND (s.example IS NULL OR s.example = '')
               ORDER BY s.sense_order, s.id
               LIMIT 1
             )"
        );
        $this->pdo->beginTransaction();
        $filled = 0;
        $seen = 0;
        while (($line = stream_get_line($fh, 20000, "\n")) !== false) {
            $seen++;
            if ($need === []) {
                break;
            }
            $cols = explode("\t", $line);
            if (count($cols) < 3) {
                continue;
            }
            [$id, $lang, $text] = [$cols[0], $cols[1], $cols[2]];
            if ($lang !== 'eng' || !ctype_digit($id)) {
                continue;
            }
            $text = Slug::plain($text, 220);
            if (strlen($text) < 20 || strlen($text) > 180 || preg_match('/https?:|@/i', $text) === 1) {
                continue;
            }
            if (preg_match_all("/[A-Za-z]+(?:'[A-Za-z]+)?/", $text, $tokens) !== 1 && count($tokens[0]) < 4) {
                continue;
            }
            $words = array_unique(array_map(static fn (string $w): string => strtolower($w), $tokens[0]));
            if (count($words) < 4 || count($words) > 24) {
                continue;
            }
            foreach ($words as $word) {
                if (!isset($need[$word])) {
                    continue;
                }
                $update->execute([$text, 'tatoeba:' . $id, $need[$word]]);
                unset($need[$word]);
                $filled++;
            }
            if ($filled > 0 && $filled % 400 === 0) {
                $this->pdo->commit();
                fwrite(STDERR, "\rtatoeba      filled {$filled}");
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
        fclose($fh);
        fwrite(STDERR, "\n");
        fwrite(STDOUT, "tatoeba: filled {$filled} examples from {$seen} lines\n");
    }
}

final class CmuImporter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(string $path): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read CMUdict: ' . $path);
        }
        $missing = $this->pdo->prepare(
            "SELECT e.id FROM entries e
             JOIN words w ON w.id = e.word_id
             WHERE w.slug = ?
             AND NOT EXISTS (
               SELECT 1 FROM pronunciations p
               WHERE p.entry_id = e.id AND p.ipa IS NOT NULL AND p.ipa != ''
             )"
        );
        $insert = $this->pdo->prepare(
            "INSERT INTO pronunciations (entry_id, ipa, audio_url, source) VALUES (?, ?, NULL, 'cmudict')"
        );
        $this->pdo->beginTransaction();
        $added = 0;
        while (($line = fgets($fh)) !== false) {
            if ($line === '' || str_starts_with($line, ';;;')) {
                continue;
            }
            $parts = preg_split('/\s+/', trim($line));
            if ($parts === false || count($parts) < 2) {
                continue;
            }
            $word = strtolower($parts[0]);
            if (str_contains($word, '(')) {
                continue;
            }
            $slug = Slug::fromHeadword($word);
            if ($slug === null) {
                continue;
            }
            $ipa = Arpabet::toIpa(array_slice($parts, 1));
            if ($ipa === null) {
                continue;
            }
            $missing->execute([$slug]);
            foreach ($missing->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $insert->execute([(int) $id, $ipa]);
                $added++;
            }
            if ($added > 0 && $added % 2000 === 0) {
                $this->pdo->commit();
                fwrite(STDERR, "\rcmudict      {$added}");
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
        fclose($fh);
        fwrite(STDERR, "\n");
        fwrite(STDOUT, "cmudict: pronunciations added {$added}\n");
    }
}

final class FrequencyImporter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(string $path): void
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot read frequency list: ' . $path);
        }
        $this->pdo->beginTransaction();
        $this->pdo->exec('UPDATE words SET freq_rank = NULL, is_common = 0');
        $update = $this->pdo->prepare('UPDATE words SET freq_rank = ?, is_common = ? WHERE slug = ?');
        $rank = 0;
        $matched = 0;
        while (($line = fgets($fh)) !== false) {
            $parts = preg_split('/\s+/', trim($line));
            if ($parts === false || $parts === [] || $parts[0] === '') {
                continue;
            }
            $slug = strtolower($parts[0]);
            if (preg_match('/^[a-z][a-z\'-]{0,40}$/', $slug) !== 1) {
                continue;
            }
            $rank++;
            $update->execute([$rank, $rank <= 20000 ? 1 : 0, $slug]);
            $matched += $update->rowCount();
        }
        $this->pdo->commit();
        fclose($fh);
        fwrite(STDOUT, "frequency: ranked {$rank} list rows, updated {$matched} headwords\n");
    }
}

final class FtsBuilder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function rebuild(): void
    {
        fwrite(STDOUT, "fts: rebuilding\n");
        $this->pdo->exec('DELETE FROM glossary_fts');
        $this->pdo->exec(
            'INSERT INTO glossary_fts (headword, gloss, slug)
             SELECT w.headword, s.gloss, w.slug
             FROM senses s
             JOIN entries e ON e.id = s.entry_id
             JOIN words w ON w.id = e.word_id'
        );
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM glossary_fts')->fetchColumn();
        fwrite(STDOUT, "fts: {$count} rows\n");
    }
}
