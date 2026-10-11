<?php

declare(strict_types=1);

/** File-based sliding window. Fails open if the data directory cannot be written. */
final class RateLimit
{
    public function __construct(
        private readonly string $directory,
        private readonly int $limit,
        private readonly int $windowSeconds = 60,
    ) {
    }

    public function allow(string $bucket): bool
    {
        if ($this->limit < 1) {
            return true;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            return true;
        }
        $path = $this->directory . '/' . hash('sha256', $bucket . '|' . Http::clientIp()) . '.log';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return true;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            $raw = stream_get_contents($handle);
            $now = time();
            $hits = [];
            if (is_string($raw) && $raw !== '') {
                foreach (explode(',', $raw) as $stamp) {
                    $stamp = (int) $stamp;
                    if ($stamp > $now - $this->windowSeconds) {
                        $hits[] = $stamp;
                    }
                }
            }
            $allowed = count($hits) < $this->limit;
            if ($allowed) {
                $hits[] = $now;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, implode(',', $hits));
            fflush($handle);
            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
