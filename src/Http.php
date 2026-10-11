<?php

declare(strict_types=1);

final class Http
{
    public static function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function securityHeaders(string $nonce): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self' 'nonce-{$nonce}'; "
            . "style-src 'self'; img-src 'self' data:; font-src 'self'; "
            . "media-src 'self' https://upload.wikimedia.org https://commons.wikimedia.org; "
            . "connect-src 'self'; object-src 'none'; base-uri 'self'; "
            . "frame-ancestors 'none'; form-action 'self'"
        );
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return preg_match('/^[0-9a-fA-F:.]+$/', $ip) === 1 ? $ip : '0.0.0.0';
    }

    public static function path(): string
    {
        $path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    public static function wantsHtml(): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return $method === 'GET' || $method === 'HEAD';
    }
}
