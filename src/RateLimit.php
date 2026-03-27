<?php
declare(strict_types=1);

namespace Shh;

/**
 * tiny file-backed token bucket per ip. 10 requests per 60s window by default.
 * not perfect under heavy concurrency, good enough for a hobby instance.
 */
final class RateLimit
{
    private string $dir;
    private int $max;
    private int $windowSeconds;

    public function __construct(?string $dir = null, int $max = 10, int $windowSeconds = 60)
    {
        $this->dir = $dir ?? shh_env('SHH_RATE_DIR', shh_data_path('rate'));
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $this->max = $max;
        $this->windowSeconds = $windowSeconds;
    }

    public function allow(string $ip, ?int $now = null): bool
    {
        $now ??= time();
        $key = preg_replace('/[^a-zA-Z0-9:._-]/', '_', $ip) ?: 'unknown';
        $file = $this->dir . '/' . $key . '.json';

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return true; // fail open, disk issue shouldn't lock users out
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = ['hits' => [], 'window' => $this->windowSeconds];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['hits']) && is_array($decoded['hits'])) {
                    $data = $decoded;
                }
            }
            // drop hits outside window
            $cutoff = $now - $this->windowSeconds;
            $hits = array_values(array_filter($data['hits'], fn($t) => (int)$t > $cutoff));
            if (count($hits) >= $this->max) {
                // write back the pruned list so it doesn't grow forever
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, json_encode(['hits' => $hits, 'window' => $this->windowSeconds]));
                return false;
            }
            $hits[] = $now;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode(['hits' => $hits, 'window' => $this->windowSeconds]));
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function reset(string $ip): void
    {
        $key = preg_replace('/[^a-zA-Z0-9:._-]/', '_', $ip) ?: 'unknown';
        $file = $this->dir . '/' . $key . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
