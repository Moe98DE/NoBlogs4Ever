<?php

declare(strict_types=1);

namespace NBE;

/**
 * Allowlisted structured security/application events (JSON Lines).
 *
 * Only an event name, UTC time, site ID and a fixed set of scalar fields are
 * ever written. Request URLs, IP addresses, user agents, passwords, cookies,
 * tokens and message contents are structurally impossible to log through
 * this API. Files rotate daily and are deleted after LOG_RETENTION_DAYS.
 */
final class EventLog
{
    /** The only data fields an event may carry. */
    public const FIELDS = ['user', 'actor', 'site', 'job', 'count', 'code', 'role', 'state'];

    public static function dir(): string
    {
        return getenv('NBE_LOG_DIR') ?: dirname(Migration::root()).'/logs';
    }

    /** @param array<string, mixed> $data */
    public static function record(string $event, array $data = []): void
    {
        $record = [
            'time' => gmdate('c'),
            'event' => preg_replace('/[^a-z0-9_]/', '', strtolower($event)),
            'site' => function_exists('get_current_blog_id') ? get_current_blog_id() : 0,
        ];
        if (function_exists('get_current_user_id') && get_current_user_id()) {
            $record['actor'] = get_current_user_id();
        }
        foreach (self::FIELDS as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $record[$key] = is_string($data[$key]) ? substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $data[$key]) ?? '', 0, 64) : $data[$key];
            }
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $file = $dir.'/events-'.gmdate('Y-m-d').'.jsonl';
        if (@file_put_contents($file, json_encode($record, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX) !== false) {
            @chmod($file, 0600);
        }
    }

    /**
     * Most recent events first, bounded.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $limit = 200, ?string $event = null): array
    {
        $files = glob(self::dir().'/events-*.jsonl') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || ($event !== null && ($row['event'] ?? '') !== $event)) {
                    continue;
                }
                $out[] = $row;
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    public static function purge(int $retentionDays): int
    {
        $removed = 0;
        foreach (glob(self::dir().'/events-*.jsonl') ?: [] as $file) {
            if (filemtime($file) < time() - max(1, $retentionDays) * 86400 && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
