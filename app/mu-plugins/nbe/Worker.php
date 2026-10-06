<?php

declare(strict_types=1);

namespace NBE;

/**
 * Background work, run by the `worker` container every 30 seconds via
 * scripts/worker.php. Public HTTP requests never run scheduled jobs
 * (wp-cron.php is disabled and blocked at the edge).
 *
 * One pass:
 *  1. runs due WordPress cron events for every active site (scheduled posts, …);
 *  2. advances migration jobs for up to MIGRATION_TIME_BUDGET seconds;
 *  3. builds at most one queued full-site archive;
 *  4. refreshes the discovery index when due;
 *  5. at most hourly: applies retention to analytics, rate counters, event
 *     logs, migration workspaces and archives.
 *
 * In emergency read-only mode only the heartbeat is written: no scheduled
 * post is published and no import or archive advances.
 */
final class Worker
{
    public static function run(): void
    {
        update_site_option('nbe_worker_heartbeat', time());
        if (Security::readonly()) {
            return;
        }
        self::guarded('cron', [self::class, 'runCron']);
        self::guarded('migration', function (): void {
            $deadline = microtime(true) + Config::int('MIGRATION_TIME_BUDGET', 20, 1, 300);
            while (microtime(true) < $deadline && Migration::runNext()) {
                // keep going while there is work and time
            }
        });
        self::guarded('export', [Export::class, 'runNext']);
        self::guarded('discovery', [Discovery::class, 'refreshIfDue']);
        if (time() - (int) get_site_option('nbe_last_housekeeping', 0) >= HOUR_IN_SECONDS) {
            self::guarded('housekeeping', [self::class, 'housekeeping']);
            update_site_option('nbe_last_housekeeping', time());
        }
        update_site_option('nbe_worker_heartbeat', time());
    }

    private static function guarded(string $stage, callable $task): void
    {
        try {
            $task();
        } catch (\Throwable $e) {
            update_site_option('nbe_worker_last_error', gmdate('c').' '.$stage);
            EventLog::record('worker_error', ['code' => $stage]);
        } finally {
            while (ms_is_switched()) {
                restore_current_blog();
            }
            wp_set_current_user(0);
        }
    }

    public static function runCron(): void
    {
        $now = time();
        foreach (get_sites(['number' => 0, 'deleted' => 0, 'spam' => 0, 'archived' => 0, 'fields' => 'ids']) as $siteId) {
            switch_to_blog((int) $siteId);
            try {
                wp_set_current_user(0);
                foreach (_get_cron_array() as $timestamp => $hooks) {
                    if ($timestamp > $now) {
                        break;
                    }
                    foreach ($hooks as $hook => $events) {
                        foreach ($events as $event) {
                            if ($event['schedule']) {
                                wp_reschedule_event($timestamp, $event['schedule'], $hook, $event['args']);
                            }
                            wp_unschedule_event($timestamp, $hook, $event['args']);
                            try {
                                do_action_ref_array($hook, $event['args']);
                            } catch (\Throwable $e) {
                                EventLog::record('cron_error', ['site' => (int) $siteId, 'code' => substr((string) $hook, 0, 64)]);
                            }
                        }
                    }
                }
            } finally {
                restore_current_blog();
            }
        }
    }

    public static function housekeeping(): void
    {
        Analytics::purge();
        RateLimiter::purge();
        EventLog::purge(Config::int('LOG_RETENTION_DAYS', 7, 1));
        Migration::purge();
        Export::purge();
    }
}
