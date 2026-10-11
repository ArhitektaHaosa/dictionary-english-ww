<?php

declare(strict_types=1);

/**
 * Download the open dictionary sources. Resumable. Pass --refresh to start over.
 * URLs live in this one array so a moved file is a one-line fix.
 */

const DICTIONARY_SOURCES = [
    [
        'key' => 'kaikki',
        'url' => 'https://kaikki.org/dictionary/English/kaikki.org-dictionary-English.jsonl.gz',
        'filename' => 'kaikki-en.jsonl.gz',
        'min_bytes' => 100000000,
        'note' => 'Wiktionary via Kaikki.org, English JSONL, gzip. CC BY-SA 4.0.',
    ],
    [
        'key' => 'wordnet',
        'url' => 'https://en-word.net/downloads/english-wordnet-2025.zip',
        'filename' => 'english-wordnet-2025.zip',
        'min_bytes' => 1000000,
        'note' => 'Open English WordNet 2025, WNDB zip. CC BY 4.0.',
    ],
    [
        'key' => 'moby',
        'url' => 'https://www.gutenberg.org/files/3202/files/mthesaur.txt',
        'filename' => 'mthesaur.txt',
        'min_bytes' => 5000000,
        'note' => 'Moby Thesaurus II, public domain (Grady Ward / Project Gutenberg 3202).',
    ],
    [
        'key' => 'tatoeba',
        'url' => 'https://downloads.tatoeba.org/exports/per_language/eng/eng_sentences.tsv.bz2',
        'filename' => 'eng_sentences.tsv.bz2',
        'min_bytes' => 5000000,
        'note' => 'Tatoeba English sentences. CC BY 2.0 FR.',
    ],
    [
        'key' => 'cmudict',
        'url' => 'https://raw.githubusercontent.com/cmusphinx/cmudict/master/cmudict.dict',
        'filename' => 'cmudict.dict',
        'min_bytes' => 1000000,
        'note' => 'CMU Pronouncing Dictionary. BSD-style.',
    ],
    [
        'key' => 'frequency',
        'url' => 'https://raw.githubusercontent.com/hermitdave/FrequencyWords/master/content/2018/en/en_50k.txt',
        'filename' => 'en_50k.txt',
        'min_bytes' => 100000,
        'note' => 'FrequencyWords English 50k from OpenSubtitles 2018. CC BY-SA 4.0.',
    ],
];

$root = dirname(__DIR__);
chdir($root);
$sourcesDir = $root . '/data/sources';
if (is_file($root . '/config.php')) {
    $config = require $root . '/config.php';
    if (is_array($config) && !empty($config['sources_dir'])) {
        $sourcesDir = (string) $config['sources_dir'];
    }
}
$refresh = in_array('--refresh', $argv, true);
if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php tools/download_sources.php [--refresh]\n");
    exit(0);
}
foreach ($argv as $index => $arg) {
    if ($index === 0 || $arg === '--refresh' || $arg === '--help') {
        continue;
    }
    fwrite(STDERR, "error: unknown argument {$arg}\n");
    exit(1);
}
if (!is_dir($sourcesDir) && !mkdir($sourcesDir, 0750, true) && !is_dir($sourcesDir)) {
    fwrite(STDERR, "error: cannot create {$sourcesDir}\n");
    exit(1);
}
if (!command_exists('curl')) {
    fwrite(STDERR, "error: curl is required on PATH\n");
    exit(1);
}

$failed = 0;
foreach (DICTIONARY_SOURCES as $source) {
    fwrite(STDOUT, $source['key'] . ': ' . $source['note'] . "\n");
    try {
        download_one($source, $sourcesDir, $refresh);
    } catch (Throwable $error) {
        fwrite(STDERR, 'error: ' . $source['key'] . ': ' . $error->getMessage() . "\n");
        $failed++;
    }
}
if ($failed > 0) {
    fwrite(STDERR, "error: {$failed} download(s) failed\n");
    exit(1);
}
fwrite(STDOUT, "downloads finished\n");
exit(0);

/** @param array{key:string,url:string,filename:string,min_bytes:int,note:string} $source */
function download_one(array $source, string $directory, bool $refresh): void
{
    $dest = $directory . '/' . $source['filename'];
    $partial = $dest . '.partial';
    if ($refresh) {
        @unlink($dest);
        @unlink($partial);
    }
    $remote = remote_length($source['url']);
    if (is_file($dest) && $remote > 0 && filesize($dest) === $remote) {
        fwrite(STDOUT, "  already complete (" . filesize($dest) . " bytes)\n");
        return;
    }
    if (is_file($dest) && $remote > 0 && filesize($dest) > $remote) {
        unlink($dest);
    }
    $target = is_file($dest) ? $dest : $partial;
    if ($refresh || !is_file($target)) {
        // Start a fresh partial when nothing resumable is on disk.
        if (!is_file($dest)) {
            $target = $partial;
        }
    }
    $cmd = 'curl --fail --location --retry 5 --retry-delay 2 --continue-at - --user-agent '
        . escapeshellarg('dictionary-ww/1.0 (open educational dictionary)')
        . ' --output ' . escapeshellarg($target)
        . ' ' . escapeshellarg($source['url']);
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('could not start curl');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = microtime(true);
    while (true) {
        $status = proc_get_status($proc);
        $size = is_file($target) ? (int) filesize($target) : 0;
        $pct = $remote > 0 ? number_format(min(100, ($size / $remote) * 100), 1) : '?';
        $rate = $size / max(0.001, microtime(true) - $started);
        fwrite(STDERR, sprintf("\r  %-12s %6s%%  %d bytes  %.1f MB/s   ", $source['key'], $pct, $size, $rate / 1048576));
        if (!$status['running']) {
            break;
        }
        usleep(400000);
    }
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    fwrite(STDERR, "\n");
    if ($code !== 0) {
        throw new RuntimeException('curl exited ' . $code . ($err !== '' ? ': ' . trim($err) : ''));
    }
    clearstatcache(true, $target);
    $size = (int) filesize($target);
    if ($remote > 0 && $size !== $remote) {
        throw new RuntimeException("size mismatch: got {$size}, expected {$remote}");
    }
    if ($size < $source['min_bytes']) {
        throw new RuntimeException("file is only {$size} bytes, expected at least {$source['min_bytes']}");
    }
    if ($target !== $dest && !rename($target, $dest)) {
        throw new RuntimeException('could not move download into place');
    }
    fwrite(STDOUT, "  saved {$dest} ({$size} bytes)\n");
}

function remote_length(string $url): int
{
    $cmd = 'curl --silent --location --head --fail --retry 3 --user-agent '
        . escapeshellarg('dictionary-ww/1.0 (open educational dictionary)')
        . ' ' . escapeshellarg($url);
    $headers = shell_exec($cmd);
    if (!is_string($headers)) {
        return 0;
    }
    if (preg_match_all('/^content-length:\s*(\d+)/mi', $headers, $matches) < 1) {
        return 0;
    }
    $lengths = $matches[1];
    return (int) $lengths[count($lengths) - 1];
}

function command_exists(string $name): bool
{
    $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return $path !== '';
}
