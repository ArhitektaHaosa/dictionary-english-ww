<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/src/web.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'none'");
header('Cache-Control: no-store');

try {
    $config = dictionary_config($root);
    if (empty($config['configured']) || !is_file((string) $config['db_path'])) {
        http_response_code(503);
        echo '{"error":"unavailable"}';
        exit(0);
    }
    $limit = new RateLimit($root . '/data/ratelimit', (int) $config['suggest_per_minute']);
    if (!$limit->allow('suggest')) {
        http_response_code(429);
        header('Retry-After: 60');
        echo '{"error":"rate"}';
        exit(0);
    }
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '' || mb_strlen($query) > 40) {
        echo '[]';
        exit(0);
    }
    $repo = new DictionaryRepository(Database::openReadOnly((string) $config['db_path']));
    $rows = $repo->suggest($query, 8);
    echo (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $error) {
    error_log('dictionary-ww suggest: ' . $error->getMessage());
    http_response_code(500);
    echo '{"error":"failed"}';
}
