<?php

/**
 * Container/host health probe. Prints one JSON object and exits 0 when healthy.
 *
 *   php health.php               web container: database + writable storage
 *   php health.php --worker      also requires a fresh worker heartbeat
 *   php health.php --operations  also requires a fresh backup and no failed/stalled migrations
 */

declare(strict_types=1);

require (getenv('WP_ROOT') ?: '/var/www/html').'/wp-load.php';
$level = in_array('--operations', $argv, true) ? 'operations' : (in_array('--worker', $argv, true) ? 'worker' : 'basic');
$status = \NBE\Health::status();
$status['ok'] = \NBE\Health::healthy($status, $level);
$status['level'] = $level;
echo json_encode($status, JSON_UNESCAPED_SLASHES)."\n";
exit($status['ok'] ? 0 : 1);
