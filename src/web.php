<?php

declare(strict_types=1);

require_once __DIR__ . '/Slug.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DictionaryRepository.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/App.php';

/** @return array<string, mixed> */
function dictionary_config(string $root): array
{
    $config = [
        'db_path' => $root . '/data/dictionary.sqlite',
        'sources_dir' => $root . '/data/sources',
        'base_url' => 'https://YOURDOMAIN',
        'site_name' => 'Dictionary WW',
        'suggest_per_minute' => 60,
        'search_per_minute' => 30,
        'configured' => false,
    ];
    $file = $root . '/config.php';
    if (!is_file($file)) {
        return $config;
    }
    $loaded = require $file;
    if (!is_array($loaded)) {
        throw new RuntimeException('config.php must return an array');
    }
    $config = array_merge($config, $loaded);
    $config['configured'] = true;
    $config['base_url'] = rtrim((string) $config['base_url'], '/');
    return $config;
}
