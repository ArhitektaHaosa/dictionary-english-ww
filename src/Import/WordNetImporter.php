<?php

declare(strict_types=1);

/**
 * Open English WordNet in Princeton WNDB form.
 * Synsets are staged in SQLite, then attached to headwords. Wiktionary senses win;
 * WordNet adds glosses only when the headword has none, and always adds relations.
 */
final class WordNetImporter
{
    private const SOURCE = 'wordnet';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Meta $meta,
    ) {
    }

    public function run(string $zipPath): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to read Open English WordNet.');
        }
        if ($this->meta->get('phase_wordnet') === 'done') {
            fwrite(STDOUT, "wordnet: already complete\n");
            return;
        }
        $dir = dirname($zipPath) . '/oewn';
        if (!is_file($dir . '/oewn2025/data.noun')) {
            fwrite(STDOUT, "wordnet: extracting archive\n");
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create ' . $dir);
            }
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('Cannot open ' . $zipPath);
            }
            if (!$zip->extractTo($dir)) {
                $zip->close();
                throw new RuntimeException('Cannot extract ' . $zipPath);
            }
            $zip->close();
        }
        $base = $dir . '/oewn2025';
        SourceClean::entries($this->pdo, self::SOURCE);
        SourceClean::relations($this->pdo, self::SOURCE);
        $this->stage($base);
        $this->apply();
        $this->pdo->exec('DROP TABLE IF EXISTS wn_ptr');
        $this->pdo->exec('DROP TABLE IF EXISTS wn_lemma');
        $this->pdo->exec('DROP TABLE IF EXISTS wn_synset');
        $this->meta->set('phase_wordnet', 'done');
        fwrite(STDOUT, "wordnet: finished\n");
    }

    private function stage(string $base): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS wn_ptr');
        $this->pdo->exec('DROP TABLE IF EXISTS wn_lemma');
        $this->pdo->exec('DROP TABLE IF EXISTS wn_synset');
        $this->pdo->exec('CREATE TABLE wn_synset (pos TEXT NOT NULL, offset TEXT NOT NULL, gloss TEXT NOT NULL, example TEXT, PRIMARY KEY (pos, offset))');
        $this->pdo->exec('CREATE TABLE wn_lemma (pos TEXT NOT NULL, offset TEXT NOT NULL, lemma TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX wn_lemma_key ON wn_lemma(pos, offset)');
        $this->pdo->exec('CREATE TABLE wn_ptr (pos TEXT NOT NULL, offset TEXT NOT NULL, symbol TEXT NOT NULL, tpos TEXT NOT NULL, toffset TEXT NOT NULL)');
        $this->pdo->exec('CREATE INDEX wn_ptr_key ON wn_ptr(pos, offset)');
        $insertSyn = $this->pdo->prepare('INSERT OR REPLACE INTO wn_synset (pos, offset, gloss, example) VALUES (?, ?, ?, ?)');
        $insertLemma = $this->pdo->prepare('INSERT INTO wn_lemma (pos, offset, lemma) VALUES (?, ?, ?)');
        $insertPtr = $this->pdo->prepare('INSERT INTO wn_ptr (pos, offset, symbol, tpos, toffset) VALUES (?, ?, ?, ?, ?)');
        $files = ['data.noun', 'data.verb', 'data.adj', 'data.adv'];
        $this->pdo->beginTransaction();
        $count = 0;
        foreach ($files as $file) {
            $path = $base . '/' . $file;
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Missing WordNet file ' . $path);
            }
            while (($line = stream_get_line($fh, 1_000_000, "\n")) !== false) {
                if ($line === '' || $line[0] === ' ' || $line[0] === "\n") {
                    continue;
                }
                $parsed = $this->parseLine($line);
                if ($parsed === null) {
                    continue;
                }
                $insertSyn->execute([$parsed['pos'], $parsed['offset'], $parsed['gloss'], $parsed['example']]);
                foreach ($parsed['lemmas'] as $lemma) {
                    $insertLemma->execute([$parsed['pos'], $parsed['offset'], $lemma]);
                }
                foreach ($parsed['pointers'] as $pointer) {
                    $insertPtr->execute([$parsed['pos'], $parsed['offset'], $pointer[0], $pointer[2], $pointer[1]]);
                }
                $count++;
                if ($count % 2000 === 0) {
                    $this->pdo->commit();
                    fwrite(STDERR, "\rwordnet      staged {$count} synsets");
                    $this->pdo->beginTransaction();
                }
            }
            fclose($fh);
        }
        $this->pdo->commit();
        fwrite(STDERR, "\n");
        fwrite(STDOUT, "wordnet: staged {$count} synsets\n");
    }

    /** @return array{pos:string,offset:string,gloss:string,example:?string,lemmas:list<string>,pointers:list<array{0:string,1:string,2:string}>}|null */
    private function parseLine(string $line): ?array
    {
        $bar = strpos($line, '|');
        if ($bar === false) {
            return null;
        }
        $meta = trim(substr($line, 0, $bar));
        $glossRaw = trim(substr($line, $bar + 1));
        $tokens = preg_split('/\s+/', $meta);
        if ($tokens === false || count($tokens) < 5) {
            return null;
        }
        $i = 0;
        $offset = $tokens[$i++];
        $i++;
        $ss = $tokens[$i++] ?? '';
        $wCnt = hexdec($tokens[$i++] ?? '0');
        if ($wCnt < 1 || $wCnt > 40) {
            return null;
        }
        $lemmas = [];
        for ($w = 0; $w < $wCnt; $w++) {
            if (!isset($tokens[$i + 1])) {
                return null;
            }
            $lemmas[] = str_replace('_', ' ', $tokens[$i]);
            $i += 2;
        }
        $pCnt = hexdec($tokens[$i++] ?? '0');
        $pointers = [];
        for ($p = 0; $p < $pCnt; $p++) {
            if (!isset($tokens[$i + 3])) {
                break;
            }
            $pointers[] = [$tokens[$i], $tokens[$i + 1], $tokens[$i + 2]];
            $i += 4;
        }
        $example = null;
        if (preg_match('/"([^"]{1,300})"/', $glossRaw, $match) === 1) {
            $example = Slug::plain($match[1], 300);
        }
        $gloss = preg_replace('/\s*;\s*".*/', '', $glossRaw) ?? $glossRaw;
        $gloss = Slug::plain($gloss, 500);
        if ($gloss === '') {
            return null;
        }
        $pos = match ($ss) {
            'n' => 'n',
            'v' => 'v',
            'a', 's' => 'a',
            'r' => 'r',
            default => '',
        };
        if ($pos === '') {
            return null;
        }
        return [
            'pos' => $pos,
            'offset' => $offset,
            'gloss' => $gloss,
            'example' => $example,
            'lemmas' => $lemmas,
            'pointers' => $pointers,
        ];
    }

    private function apply(): void
    {
        $findWord = $this->pdo->prepare('SELECT id FROM words WHERE slug = ?');
        $insertWord = $this->pdo->prepare('INSERT INTO words (slug, headword, is_common, freq_rank) VALUES (?, ?, 0, NULL)');
        $listEntries = $this->pdo->prepare('SELECT id, pos, source FROM entries WHERE word_id = ?');
        $insertEntry = $this->pdo->prepare(
            "INSERT INTO entries (word_id, pos, etymology, source, etymology_number) VALUES (?, ?, NULL, 'wordnet', 0)"
        );
        $insertSense = $this->pdo->prepare(
            'INSERT OR IGNORE INTO senses (entry_id, gloss, example, example_source, sense_order) VALUES (?, ?, ?, ?, ?)'
        );
        $maxSense = $this->pdo->prepare('SELECT COALESCE(MAX(sense_order), 0) FROM senses WHERE entry_id = ?');
        $lemmasOf = $this->pdo->prepare('SELECT lemma FROM wn_lemma WHERE pos = ? AND offset = ?');
        $pointersOf = $this->pdo->prepare('SELECT symbol, tpos, toffset FROM wn_ptr WHERE pos = ? AND offset = ?');
        $insertRel = $this->pdo->prepare('INSERT OR IGNORE INTO relations (entry_id, type, target, source) VALUES (?, ?, ?, \'wordnet\')');
        $synsets = $this->pdo->query('SELECT pos, offset, gloss, example FROM wn_synset');
        if ($synsets === false) {
            throw new RuntimeException('WordNet stage table is empty');
        }
        $posName = ['n' => 'noun', 'v' => 'verb', 'a' => 'adj', 'r' => 'adv'];
        $this->pdo->beginTransaction();
        $n = 0;
        foreach ($synsets as $syn) {
            $lemmasOf->execute([$syn['pos'], $syn['offset']]);
            $lemmas = $lemmasOf->fetchAll(PDO::FETCH_COLUMN);
            $pointersOf->execute([$syn['pos'], $syn['offset']]);
            $pointers = $pointersOf->fetchAll();
            $hyper = [];
            $antonym = [];
            $related = [];
            foreach ($pointers as $pointer) {
                $type = $this->pointerType((string) $pointer['symbol']);
                if ($type === null) {
                    continue;
                }
                $lemmasOf->execute([$pointer['tpos'], $pointer['toffset']]);
                foreach ($lemmasOf->fetchAll(PDO::FETCH_COLUMN) as $target) {
                    $label = Slug::plain(str_replace('_', ' ', (string) $target), 60);
                    if ($label === '') {
                        continue;
                    }
                    if ($type === 'hypernym' && count($hyper) < 6) {
                        $hyper[] = $label;
                    } elseif ($type === 'antonym' && count($antonym) < 6) {
                        $antonym[] = $label;
                    } elseif ($type === 'related' && count($related) < 6) {
                        $related[] = $label;
                    }
                }
            }
            $displayPos = $posName[$syn['pos']] ?? 'other';
            foreach ($lemmas as $index => $lemma) {
                $display = Slug::plain(str_replace('_', ' ', (string) $lemma), 80);
                $slug = $display === '' ? null : Slug::fromHeadword($display);
                if ($slug === null) {
                    continue;
                }
                $findWord->execute([$slug]);
                $wordId = $findWord->fetchColumn();
                $created = false;
                if ($wordId === false) {
                    $insertWord->execute([$slug, $display]);
                    $wordId = (int) $this->pdo->lastInsertId();
                    $created = true;
                } else {
                    $wordId = (int) $wordId;
                }
                $listEntries->execute([$wordId]);
                $entries = $listEntries->fetchAll();
                $entryId = $this->matchingEntry($entries, $displayPos);
                if ($entryId === null) {
                    $insertEntry->execute([$wordId, $displayPos]);
                    $entryId = (int) $this->pdo->lastInsertId();
                    $created = true;
                }
                if ($created || $this->entrySource($entries, $entryId) === self::SOURCE) {
                    $maxSense->execute([$entryId]);
                    $order = (int) $maxSense->fetchColumn();
                    if ($order < 8) {
                        $exampleSource = $syn['example'] !== null && $syn['example'] !== '' ? self::SOURCE : null;
                        $insertSense->execute([$entryId, $syn['gloss'], $syn['example'], $exampleSource, $order + 1]);
                    }
                }
                $synonyms = [];
                foreach ($lemmas as $other) {
                    if ($other === $lemma) {
                        continue;
                    }
                    $label = Slug::plain(str_replace('_', ' ', (string) $other), 60);
                    if ($label !== '' && count($synonyms) < 12) {
                        $synonyms[] = $label;
                    }
                }
                foreach (['synonym' => $synonyms, 'antonym' => $antonym, 'hypernym' => $hyper, 'related' => $related] as $type => $targets) {
                    foreach ($targets as $target) {
                        if (mb_strtolower($target, 'UTF-8') === mb_strtolower($display, 'UTF-8')) {
                            continue;
                        }
                        $insertRel->execute([$entryId, $type, $target]);
                    }
                }
            }
            $n++;
            if ($n % 1000 === 0) {
                $this->pdo->commit();
                fwrite(STDERR, "\rwordnet      applied {$n} synsets");
                $this->pdo->beginTransaction();
            }
        }
        $this->pdo->commit();
        fwrite(STDERR, "\n");
    }

    /** @param list<array<string, mixed>> $entries */
    private function matchingEntry(array $entries, string $pos): ?int
    {
        foreach ($entries as $entry) {
            if ($this->bucket((string) $entry['pos']) === $pos && (string) $entry['source'] === 'wiktionary') {
                return (int) $entry['id'];
            }
        }
        foreach ($entries as $entry) {
            if ($this->bucket((string) $entry['pos']) === $pos && (string) $entry['source'] === self::SOURCE) {
                return (int) $entry['id'];
            }
        }
        return null;
    }

    /** @param list<array<string, mixed>> $entries */
    private function entrySource(array $entries, int $id): string
    {
        foreach ($entries as $entry) {
            if ((int) $entry['id'] === $id) {
                return (string) $entry['source'];
            }
        }
        return self::SOURCE;
    }

    private function bucket(string $pos): string
    {
        $pos = strtolower($pos);
        if ($pos === 'noun' || $pos === 'name' || str_starts_with($pos, 'noun')) {
            return 'noun';
        }
        if ($pos === 'verb' || str_starts_with($pos, 'verb')) {
            return 'verb';
        }
        if ($pos === 'adj' || $pos === 'adjective' || str_starts_with($pos, 'adj')) {
            return 'adj';
        }
        if ($pos === 'adv' || $pos === 'adverb' || str_starts_with($pos, 'adv')) {
            return 'adv';
        }
        return $pos;
    }

    private function pointerType(string $symbol): ?string
    {
        if ($symbol === '@' || $symbol === '@i') {
            return 'hypernym';
        }
        if ($symbol === '!') {
            return 'antonym';
        }
        if (in_array($symbol, ['&', '+', '^', '='], true)) {
            return 'related';
        }
        return null;
    }
}
