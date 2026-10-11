<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and edit it. config.php is gitignored.
 * The database file must stay outside public/.
 */
return [
    'db_path' => __DIR__ . '/data/dictionary.sqlite',
    'sources_dir' => __DIR__ . '/data/sources',
    'base_url' => 'https://YOURDOMAIN',
    'site_name' => 'Dictionary WW',
    'suggest_per_minute' => 60,
    'search_per_minute' => 30,
];
