<?php

declare(strict_types=1);

namespace NBE;

/**
 * Fixed-window rate limiting without an IP history.
 *
 * Counters live in a private tmpfs (`NBE_RATE_DIR`). Each counter file is
 * named by an HMAC of scope + subject + time window, keyed with the site
 * salt, so neither raw addresses nor request data are ever written. Files are
 * removed by the worker two hours after their window, and vanish entirely
 * when the tmpfs is recreated.
 */
final class RateLimiter
{
    public static function dir(): string
    {
        return getenv('NBE_RATE_DIR') ?: sys_get_temp_dir().'/nbe-rate';
    }

    /**
     * Count one event and report whether it is still within the limit.
     *
     * Fails closed: if counter storage is unavailable the action is refused.
     *
     * @param string|null $subject defaults to the client address
     */
    public static function hit(string $scope, int $limit, int $seconds, ?string $subject = null): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        $slot = intdiv(time(), max(1, $seconds));
        $who = $subject ?? self::client();
        $key = hash_hmac('sha256', $scope.'|'.$who.'|'.$slot, function_exists('wp_salt') ? wp_salt('auth') : 'nbe');
        $handle = @fopen($dir.'/'.$key, 'c+');
        if (!$handle) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $count = (int) stream_get_contents($handle);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) ($count + 1));
            flock($handle, LOCK_UN);
            return $count < $limit;
        } finally {
            fclose($handle);
        }
    }

    /** The client address as seen after Apache mod_remoteip (set by the trusted edge proxy). */
    public static function client(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    }

    public static function purge(int $olderThanSeconds = 7200): int
    {
        $removed = 0;
        foreach (glob(self::dir().'/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - $olderThanSeconds && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
