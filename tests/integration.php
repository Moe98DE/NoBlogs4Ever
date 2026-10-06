<?php

/**
 * Integration suite: runs inside WordPress through WP-CLI against a
 * disposable development network.
 *
 *   make integration
 *   docker compose run --rm cli wp eval-file /opt/nbe/tests/integration.php --url=http://lvh.me --allow-root
 *   ... /opt/nbe/tests/integration.php 30   (only files whose name starts with "30")
 *
 * Each file in tests/integration/ is self-contained and creates its own
 * random users and sites. Never point this at a network with real data.
 */
if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI against a disposable network.');
}
if (getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Tests cannot run on production.');
}
$GLOBALS['nbe_count'] = 0;

function verify($ok, string $message): void
{
    $GLOBALS['nbe_count']++;
    if (!$ok) {
        throw new RuntimeException('FAIL: '.$message);
    }
    echo 'ok '.$GLOBALS['nbe_count'].' '.$message."\n";
}

/** A fresh user, optionally owning a fresh site. @return array{0:int,1:int} user ID, site ID (0 when none) */
function nbe_fixture(string $prefix, bool $withSite = true): array
{
    $suffix = strtolower(wp_generate_password(8, false, false));
    $user = wp_create_user($prefix.$suffix, wp_generate_password(40, true, true), $prefix.$suffix.'@example.invalid');
    if (is_wp_error($user)) {
        throw new RuntimeException($user->get_error_message());
    }
    $site = 0;
    if ($withSite) {
        $site = wpmu_create_blog($prefix.$suffix.'.'.DOMAIN_CURRENT_SITE, '/', ucfirst($prefix).' '.$suffix, $user, ['public' => 1]);
        if (is_wp_error($site)) {
            throw new RuntimeException($site->get_error_message());
        }
    }
    return [(int) $user, (int) $site];
}

/** Build a ZIP from [archive path => file path|string contents]. */
function nbe_zip(array $entries): string
{
    $file = tempnam(sys_get_temp_dir(), 'nbe-zip-');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $source) {
        is_file($source) ? $zip->addFile($source, $name) : $zip->addFromString($name, $source);
    }
    $zip->close();
    return $file;
}

/** Run a migration job until it stops making progress. */
function nbe_run_job(string $job, int $batch = 50): object
{
    for ($i = 0; $i < 100; $i++) {
        $row = \NBE\Migration::job($job);
        if (!in_array($row->state, ['intake', 'queued', 'running'], true)) {
            return $row;
        }
        \NBE\Migration::run($job, $batch);
    }
    throw new RuntimeException('Migration job did not finish.');
}

\NBE\Platform::install();
$only = $args[0] ?? '';
foreach (glob(__DIR__.'/integration/*.php') as $file) {
    if ($only !== '' && !str_starts_with(basename($file), $only)) {
        continue;
    }
    echo '# '.basename($file)."\n";
    $before = get_current_user_id();
    require $file;
    while (ms_is_switched()) {
        restore_current_blog();
    }
    wp_set_current_user($before);
}
echo 'PASS '.$GLOBALS['nbe_count']." integration assertions\n";
