<?php

declare(strict_types=1);

/** Confirm this machine can build and serve the dictionary. Exit 0 only when every check passes. */

$failed = 0;

function line(string $label, bool $ok, string $detail): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    fwrite(STDOUT, sprintf("%-16s %s  %s\n", $label, $ok ? 'OK' : 'FAIL', $detail));
}

$version = PHP_VERSION;
line('PHP', PHP_VERSION_ID >= 80400, $version . (PHP_VERSION_ID >= 80400 ? '' : ' (need >= 8.4)'));

line('pdo_sqlite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'loaded' : 'missing');
line('zlib', extension_loaded('zlib'), extension_loaded('zlib') ? 'loaded' : 'missing (Kaikki .gz)');
line('bz2', extension_loaded('bz2'), extension_loaded('bz2') ? 'loaded' : 'missing (Tatoeba)');
line('zip', class_exists(ZipArchive::class), class_exists(ZipArchive::class) ? 'loaded' : 'missing (WordNet)');
line('mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'loaded' : 'missing');

$ftsOk = false;
$ftsDetail = 'unavailable';
if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE VIRTUAL TABLE env_fts USING fts5(body)');
        $pdo->exec("INSERT INTO env_fts(body) VALUES ('dictionary')");
        $hit = $pdo->query("SELECT body FROM env_fts WHERE env_fts MATCH 'dictionary'")->fetchColumn();
        $ftsOk = $hit === 'dictionary';
        $ftsDetail = $ftsOk ? 'MATCH works' : 'MATCH returned nothing';
    } catch (Throwable $error) {
        $ftsDetail = $error->getMessage();
    }
}
line('FTS5', $ftsOk, $ftsDetail);

$root = dirname(__DIR__);
$free = disk_free_space($root);
$freeOk = is_int($free) || is_float($free);
$freeBytes = $freeOk ? (int) $free : 0;
$freeGb = $freeBytes / 1073741824;
$diskOk = $freeBytes >= 10 * 1073741824;
$diskDetail = $freeOk ? number_format($freeGb, 1) . ' GB free' : 'unknown';
if ($freeOk && $freeGb < 15 && $diskOk) {
    $diskDetail .= ' (15 GB is more comfortable)';
}
if (!$diskOk && $freeOk) {
    $diskDetail .= ' (need about 10-15 GB)';
}
line('disk', $diskOk, $diskDetail);

$memory = ini_get('memory_limit') ?: 'unknown';
$memoryOk = memory_bytes($memory) < 0 || memory_bytes($memory) >= 128 * 1048576;
$memoryDetail = $memory;
if ($memoryOk && memory_bytes($memory) > 0 && memory_bytes($memory) < 512 * 1048576) {
    $memoryDetail .= ' (import is started with php -d memory_limit=512M)';
}
line('memory_limit', $memoryOk, $memoryDetail);

if ($failed > 0) {
    fwrite(STDERR, "{$failed} check(s) failed\n");
    fwrite(STDERR, "On CloudPanel, install the missing PHP 8.4 pieces as root, then restart FPM:\n");
    fwrite(STDERR, "  apt-get install php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-zip php8.4-bz2\n");
    fwrite(STDERR, "  systemctl restart php8.4-fpm\n");
    exit(1);
}
fwrite(STDOUT, "all OK\n");
exit(0);

function memory_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '-1') {
        return -1;
    }
    if (preg_match('/^(\d+)([KMG])?$/i', $value, $match) !== 1) {
        return 0;
    }
    $number = (int) $match[1];
    $unit = strtoupper($match[2] ?? '');
    return match ($unit) {
        'G' => $number * 1073741824,
        'M' => $number * 1048576,
        'K' => $number * 1024,
        default => $number,
    };
}
