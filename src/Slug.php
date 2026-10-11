<?php

declare(strict_types=1);

/** Headword slugs and the small set of text rules shared by the web app and the importers. */
final class Slug
{
    public static function fromHeadword(string $headword): ?string
    {
        $slug = trim($headword);
        if ($slug === '' || mb_strlen($slug) > 80) {
            return null;
        }
        $slug = mb_strtolower($slug, 'UTF-8');
        $slug = str_replace(['’', '‘', '_'], ["'", "'", '-'], $slug);
        $slug = preg_replace('/\s+/u', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/u', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if (!self::isValid($slug)) {
            return null;
        }
        return $slug;
    }

    public static function isValid(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > 80) {
            return false;
        }
        return (bool) preg_match("/^[\\p{L}\\p{N}]+(?:['-][\\p{L}\\p{N}]+)*$/u", $slug);
    }

    /** Exclusive upper bound for an ASCII-or-BMP prefix range, or null if the prefix is unsafe. */
    public static function prefixUpperBound(string $prefix): ?string
    {
        if ($prefix === '' || !self::isValid($prefix) && !preg_match("/^[\\p{L}\\p{N}'-]+$/u", $prefix)) {
            return null;
        }
        if (preg_match('/[%_\\\\]/', $prefix)) {
            return null;
        }
        $chars = preg_split('//u', $prefix, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return null;
        }
        $last = array_pop($chars);
        $next = mb_chr(mb_ord($last, 'UTF-8') + 1, 'UTF-8');
        if ($next === false || $next === '') {
            return null;
        }
        return implode('', $chars) . $next;
    }

    public static function plain(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            $value = rtrim(mb_substr($value, 0, $max - 1)) . '…';
        }
        return $value;
    }

    public static function httpsAudio(?string $url): ?string
    {
        if ($url === null || !str_starts_with($url, 'https://')) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        $host = strtolower($host);
        if ($host !== 'upload.wikimedia.org' && $host !== 'commons.wikimedia.org') {
            return null;
        }
        if (preg_match('/[\s<>"\']/', $url)) {
            return null;
        }
        return $url;
    }

    public static function frequencyBand(?int $rank): int
    {
        if ($rank === null || $rank < 1) {
            return 0;
        }
        if ($rank <= 1000) {
            return 5;
        }
        if ($rank <= 3000) {
            return 4;
        }
        if ($rank <= 8000) {
            return 3;
        }
        if ($rank <= 20000) {
            return 2;
        }
        if ($rank <= 50000) {
            return 1;
        }
        return 0;
    }

    /** @return list<string> */
    public static function tokens(string $query, int $max = 4): array
    {
        if (!preg_match_all("/[\\p{L}\\p{N}']+/u", mb_strtolower($query, 'UTF-8'), $matches)) {
            return [];
        }
        $out = [];
        foreach ($matches[0] as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }
            $out[] = $token;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }
}
