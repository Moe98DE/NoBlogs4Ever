<?php

declare(strict_types=1);

namespace NBE;

/**
 * Health signals for monitoring. None of them carry tenant, reader, URL or
 * IP dimensions.
 *
 * - GET /wp-json/nbe/v1/live    public liveness: database reachable + job storage writable
 * - GET /wp-json/nbe/v1/health  network operators only: the full status below
 * - php scripts/health.php      the same status for container/host checks
 */
final class Health
{
    public const BACKUP_MARKER = '/var/lib/nbe/backup-status/last-success';
    public const WORKER_MAX_AGE = 180;
    public const MIN_FREE_BYTES = 268435456;

    public static function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('nbe/v1', '/live', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => function () {
                    $ok = self::databaseOk() && is_writable(Migration::root());
                    $response = new \WP_REST_Response(['ok' => $ok, 'time' => gmdate('c')], $ok ? 200 : 503);
                    $response->header('Cache-Control', 'no-store');
                    return $response;
                },
            ]);
            register_rest_route('nbe/v1', '/health', [
                'methods' => 'GET',
                'permission_callback' => fn () => current_user_can('manage_network_options'),
                'callback' => fn () => self::status(),
            ]);
        });
    }

    public static function databaseOk(): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT 1') === '1';
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        global $wpdb;
        $now = time();
        $storage = wp_upload_dir(null, false)['basedir'] ?: Migration::root();
        $backupAge = is_readable(self::BACKUP_MARKER) ? $now - (int) strtotime(trim((string) file_get_contents(self::BACKUP_MARKER))) : null;
        $stallSeconds = Config::int('MIGRATION_STALL_MINUTES', 30, 5) * 60;
        $jobs = $wpdb->get_row($wpdb->prepare(
            'SELECT SUM(state = %s) failed, SUM(state IN (%s, %s) AND updated < %s) stalled FROM '.$wpdb->base_prefix.'nbe_jobs',
            'failed',
            'running',
            'queued',
            gmdate('Y-m-d H:i:s', $now - $stallSeconds)
        ), ARRAY_A) ?: [];
        $free = @disk_free_space(Migration::root());
        return [
            'time' => gmdate('c'),
            'database' => self::databaseOk(),
            'worker_age_seconds' => $now - (int) get_site_option('nbe_worker_heartbeat', 0),
            'worker_last_error' => (string) get_site_option('nbe_worker_last_error', ''),
            'storage_free_bytes' => $free === false ? 0 : (int) $free,
            'storage_writable' => is_writable(Migration::root()) && is_writable((string) $storage),
            'backup_age_seconds' => $backupAge,
            'backup_max_age_seconds' => Config::int('BACKUP_MAX_AGE_HOURS', 26, 1) * 3600,
            'readonly' => Security::readonly(),
            'failed_migrations' => (int) ($jobs['failed'] ?? 0),
            'stalled_migrations' => (int) ($jobs['stalled'] ?? 0),
            'pending_exports' => Export::pendingCount(),
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @param 'basic'|'worker'|'operations' $level
     */
    public static function healthy(array $status, string $level = 'basic'): bool
    {
        $ok = $status['database'] && $status['storage_writable'] && $status['storage_free_bytes'] > self::MIN_FREE_BYTES;
        if ($level === 'worker' || $level === 'operations') {
            $ok = $ok && $status['worker_age_seconds'] < self::WORKER_MAX_AGE;
        }
        if ($level === 'operations') {
            $ok = $ok && $status['backup_age_seconds'] !== null && $status['backup_age_seconds'] <= $status['backup_max_age_seconds']
                && !$status['stalled_migrations'] && !$status['failed_migrations'];
        }
        return $ok;
    }
}
