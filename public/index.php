<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/web.php';

try {
    (new App($root, dictionary_config($root)))->run();
} catch (Throwable $error) {
    error_log('dictionary-ww: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Something went wrong.';
}
