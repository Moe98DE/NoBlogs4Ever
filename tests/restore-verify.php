<?php

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI in the isolated restore application.');
}
global $wpdb;
$sites = get_sites(['number' => 0, 'site__not_in' => [get_main_site_id()]]);
if (count($sites) < 2) {
    throw new RuntimeException('A full drill requires a recovery point containing at least two tenants.');
}
$domains = [];
$owners = [];
$published = 0;
$media = 0;
foreach (array_slice($sites, 0, 2) as $site) {
    $domains[] = $site->domain;
    switch_to_blog((int)$site->blog_id);
    $admins = get_users(['role' => 'administrator']);
    if (!$admins || !get_option('home')) {
        throw new RuntimeException('Recovered tenant is not operational.');
    }
    $owners[] = (int)$admins[0]->ID;
    $published += (int)wp_count_posts('post')->publish + (int)wp_count_posts('page')->publish;
    foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1]) as $attachment) {
        if (is_file((string)get_attached_file($attachment->ID))) {
            $media++;
        }
    }
    restore_current_blog();
}
if ($owners[0] !== $owners[1]) {
    switch_to_blog((int)$sites[1]->blog_id);
    wp_set_current_user($owners[0]);
    if (current_user_can('manage_options')) {
        throw new RuntimeException('Tenant isolation failed after restore.');
    }
    restore_current_blog();
}
$jobs = (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->base_prefix.'nbe_jobs');
if ($jobs && count(glob(\NBE\Migration::root().'/*', GLOB_ONLYDIR) ?: []) === 0) {
    throw new RuntimeException('Migration database state exists but recovered job workspace is empty.');
}
echo wp_json_encode(['blogs' => (int)get_blog_count(), 'users' => (int)count_users()['total_users'], 'tenants' => count($sites), 'domains' => $domains, 'published' => $published, 'media' => $media, 'migration_jobs' => $jobs], JSON_UNESCAPED_SLASHES)."\n";
