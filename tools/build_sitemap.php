<?php

declare(strict_types=1);

/** Write public/sitemap.xml (an index) plus public/sitemap-N.xml parts of at most 50,000 URLs. */

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/src/Slug.php';
require_once $root . '/src/Database.php';

if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "error: copy config.sample.php to config.php and set base_url\n");
    exit(1);
}
$config = require $root . '/config.php';
if (!is_array($config)) {
    fwrite(STDERR, "error: config.php must return an array\n");
    exit(1);
}
$base = rtrim((string) ($config['base_url'] ?? ''), '/');
if ($base === '' || str_contains($base, 'YOURDOMAIN')) {
    fwrite(STDERR, "error: set base_url in config.php to the real https origin\n");
    exit(1);
}
$db = (string) ($config['db_path'] ?? $root . '/data/dictionary.sqlite');
if (!is_file($db)) {
    fwrite(STDERR, "error: unable to open database at {$db}\n");
    exit(1);
}

try {
    $pdo = Database::openReadOnly($db);
    $public = $root . '/public';
    foreach (glob($public . '/sitemap*.xml') ?: [] as $old) {
        unlink($old);
    }
    $static = ['/', '/browse'];
    foreach (array_merge(['0'], range('a', 'z')) as $letter) {
        $static[] = '/browse/' . $letter;
    }
    $part = 1;
    $count = 0;
    $handle = open_part($public, $part);
    foreach ($static as $path) {
        write_url($handle, $base . $path);
        $count++;
    }
    $stmt = $pdo->query('SELECT slug FROM words ORDER BY slug');
    if ($stmt === false) {
        throw new RuntimeException('could not list headwords');
    }
    $words = 0;
    foreach ($stmt as $row) {
        if ($count >= 50000) {
            close_part($handle);
            $part++;
            $handle = open_part($public, $part);
            $count = 0;
        }
        $slug = (string) $row['slug'];
        write_url($handle, $base . '/dictionary/english/' . rawurlencode($slug));
        $count++;
        $words++;
    }
    close_part($handle);
    $index = fopen($public . '/sitemap.xml', 'wb');
    if ($index === false) {
        throw new RuntimeException('cannot write sitemap.xml');
    }
    fwrite($index, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($index, "<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
    for ($i = 1; $i <= $part; $i++) {
        $loc = htmlspecialchars($base . '/sitemap-' . $i . '.xml', ENT_XML1);
        fwrite($index, "  <sitemap><loc>{$loc}</loc></sitemap>\n");
    }
    fwrite($index, "</sitemapindex>\n");
    fclose($index);
    $robots = "User-agent: *\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n";
    file_put_contents($public . '/robots.txt', $robots);
    fwrite(STDOUT, "sitemap: {$words} headwords in {$part} file(s)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'error: ' . $error->getMessage() . "\n");
    exit(1);
}

/** @return resource */
function open_part(string $public, int $part)
{
    $handle = fopen($public . '/sitemap-' . $part . '.xml', 'wb');
    if ($handle === false) {
        throw new RuntimeException('cannot write sitemap part ' . $part);
    }
    fwrite($handle, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
    fwrite($handle, "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n");
    return $handle;
}

/** @param resource $handle */
function close_part($handle): void
{
    fwrite($handle, "</urlset>\n");
    fclose($handle);
}

/** @param resource $handle */
function write_url($handle, string $loc): void
{
    $escaped = htmlspecialchars($loc, ENT_XML1);
    fwrite($handle, "  <url><loc>{$escaped}</loc></url>\n");
}
