<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * File-backed per-key sliding window throttle (no Redis).
 * Suitable for shared hosting; best-effort under concurrent writers.
 */
final class RateLimiter
{
    public function __construct(
        private readonly string $directory,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
        if ($this->windowSeconds < 1) {
            throw new \InvalidArgumentException('windowSeconds must be >= 1');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0770, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create rate limit directory: ' . $this->directory);
        }
    }

    /**
     * Record a hit. Returns true when allowed, false when throttled.
     */
    public function attempt(string $key, ?int $now = null): bool
    {
        $now ??= time();
        $path = $this->pathFor($key);
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            // Fail open if filesystem is unavailable — auth still has other controls.
            return true;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return true;
            }

            $raw = stream_get_contents($fh);
            $hits = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $hits = array_values(array_filter(
                        $decoded,
                        static fn ($ts): bool => is_int($ts) || (is_string($ts) && ctype_digit($ts))
                    ));
                    $hits = array_map('intval', $hits);
                }
            }

            $windowStart = $now - $this->windowSeconds;
            $hits = array_values(array_filter($hits, static fn (int $ts): bool => $ts >= $windowStart));

            if (count($hits) >= $this->maxAttempts) {
                $this->rewrite($fh, $hits);

                return false;
            }

            $hits[] = $now;
            $this->rewrite($fh, $hits);

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function pathFor(string $key): string
    {
        return $this->directory . '/' . hash('sha256', $key) . '.json';
    }

    /** @param list<int> $hits */
    private function rewrite($fh, array $hits): void
    {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits, JSON_THROW_ON_ERROR));
        fflush($fh);
    }
}
