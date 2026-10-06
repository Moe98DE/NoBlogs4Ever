<?php

/**
 * Apply migration-workspace retention now (the worker also does this hourly).
 * Run with: wp eval-file /opt/nbe/scripts/purge-jobs.php
 */
if (!defined('WP_CLI') || !WP_CLI) {
    exit(1);
}
$removed = \NBE\Migration::purge();
echo 'Expired '.$removed." migration workspaces. Content ID maps remain for idempotency.\n";
