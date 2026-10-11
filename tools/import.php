<?php

declare(strict_types=1);

/**
 * Build data/dictionary.sqlite from the files in data/sources.
 * Resumable: run the same command again after a crash. Idempotent once finished.
 *
 *   php -d memory_limit=512M tools/import.php
 *   php -d memory_limit=512M tools/import.php --output data/dictionary.new.sqlite
 */

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/src/Slug.php';
require_once $root . '/src/Database.php';
require_once $root . '/src/Import/Schema.php';
require_once $root . '/src/Import/Arpabet.php';
require_once $root . '/src/Import/SourceClean.php';
require_once $root . '/src/Import/KaikkiImporter.php';
require_once $root . '/src/Import/WordNetImporter.php';
require_once $root . '/src/Import/LaterPasses.php';

$output = $root . '/data/dictionary.sqlite';
$sources = $root . '/data/sources';
if (is_file($root . '/config.php')) {
    $config = require $root . '/config.php';
    if (is_array($config)) {
        if (!empty($config['sources_dir'])) {
            $sources = (string) $config['sources_dir'];
        }
        if (!empty($config['db_path'])) {
            $output = (string) $config['db_path'];
        }
    }
}

for ($i = 1; $i < $argc; $i++) {
    $arg = (string) $argv[$i];
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php -d memory_limit=512M tools/import.php [--output data/dictionary.new.sqlite]\n");
        exit(0);
    }
    if ($arg === '--output') {
        $next = $argv[$i + 1] ?? null;
        if (!is_string($next) || $next === '') {
            fwrite(STDERR, "error: --output needs a file path\n");
            exit(1);
        }
        $i++;
        $output = str_starts_with($next, '/') ? $next : $root . '/' . $next;
        continue;
    }
    fwrite(STDERR, "error: unknown argument {$arg}\n");
    exit(1);
}

$expected = [
    'kaikki' => $sources . '/kaikki-en.jsonl.gz',
    'wordnet' => $sources . '/english-wordnet-2025.zip',
    'moby' => $sources . '/mthesaur.txt',
    'tatoeba' => $sources . '/eng_sentences.tsv.bz2',
    'cmudict' => $sources . '/cmudict.dict',
    'frequency' => $sources . '/en_50k.txt',
];
foreach ($expected as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "error: missing {$name} file {$path}\nRun php tools/download_sources.php first.\n");
        exit(1);
    }
}

try {
    $pdo = Database::openImport($output);
    Schema::ensure($pdo);
    $meta = new Meta($pdo);
    if ($meta->get('phase') === 'done') {
        fwrite(STDOUT, "Import already complete: {$output}\nTo rebuild, import into a new file with --output, then swap it into place.\n");
        exit(0);
    }

    $kaikki = new KaikkiImporter($pdo, $meta);
    $jsonl = $kaikki->unpack($expected['kaikki'], $sources . '/kaikki-en.jsonl');
    $meta->set('phase', 'kaikki');
    $kaikki->run($jsonl);

    if ($meta->get('phase_wordnet') !== 'done') {
        $meta->set('phase', 'wordnet');
        (new WordNetImporter($pdo, $meta))->run($expected['wordnet']);
    } else {
        fwrite(STDOUT, "wordnet: already complete\n");
    }

    if ($meta->get('phase_moby') !== 'done') {
        $meta->set('phase', 'moby');
        (new MobyImporter($pdo))->run($expected['moby']);
        $meta->set('phase_moby', 'done');
    }

    if ($meta->get('phase_tatoeba') !== 'done') {
        $meta->set('phase', 'tatoeba');
        (new TatoebaImporter($pdo))->run($expected['tatoeba']);
        $meta->set('phase_tatoeba', 'done');
    }

    if ($meta->get('phase_cmu') !== 'done') {
        $meta->set('phase', 'cmu');
        (new CmuImporter($pdo))->run($expected['cmudict']);
        $meta->set('phase_cmu', 'done');
    }

    if ($meta->get('phase_frequency') !== 'done') {
        $meta->set('phase', 'frequency');
        (new FrequencyImporter($pdo))->run($expected['frequency']);
        $meta->set('phase_frequency', 'done');
    }

    if ($meta->get('phase_fts') !== 'done') {
        $meta->set('phase', 'fts');
        (new FtsBuilder($pdo))->rebuild();
        $meta->set('phase_fts', 'done');
    }

    $meta->set('phase', 'vacuum');
    fwrite(STDOUT, "vacuum: checkpoint, VACUUM, ANALYZE\n");
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('VACUUM');
    $pdo->exec('ANALYZE');
    $meta->set('phase', 'done');
    $words = (int) $pdo->query('SELECT COUNT(*) FROM words')->fetchColumn();
    fwrite(STDOUT, "done: {$words} headwords in {$output}\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "\nerror: " . $error->getMessage() . "\n");
    exit(1);
}
