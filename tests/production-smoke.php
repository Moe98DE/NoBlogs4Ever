<?php

if (!defined('ABSPATH') || !defined('WP_CLI') || getenv('APP_ENV') !== 'production') {
    throw new RuntimeException('Run through WP-CLI against the disposable production smoke stack.');
}
$operator = get_user_by('login', getenv('NBE_ADMIN_USER') ?: 'operator');
if (!$operator || !is_super_admin($operator->ID)) {
    throw new RuntimeException('Production operator is unavailable.');
}
$suffix = substr(hash('sha256', (string)microtime(true)), 0, 8);
$tenant = wp_create_user('prodtenant'.$suffix, wp_generate_password(40), 'tenant-'.$suffix.'@example.invalid');
if (is_wp_error($tenant)) {
    throw new RuntimeException($tenant->get_error_message());
}
$site = wpmu_create_blog('smoke-'.$suffix.'.'.DOMAIN_CURRENT_SITE, '/', 'Production smoke tenant', $tenant, ['public' => 1]);
if (is_wp_error($site)) {
    throw new RuntimeException($site->get_error_message());
}
switch_to_blog((int)$site);
wp_set_current_user((int)$tenant);
$post = wp_insert_post(['post_title' => 'Production smoke publication', 'post_content' => 'Read-only image filesystem with writable publication storage.', 'post_status' => 'publish', 'post_author' => $tenant], true);
if (is_wp_error($post) || get_post_status($post) !== 'publish') {
    throw new RuntimeException('Normal production publishing path failed.');
}
$upload = wp_upload_bits('production-smoke.txt', null, 'media request qualification');
if (!empty($upload['error']) || !is_file($upload['file'])) {
    throw new RuntimeException('Writable upload storage failed.');
}
restore_current_blog();
wp_set_current_user(0);
echo wp_json_encode(['site_id' => (int)$site, 'site_domain' => 'smoke-'.$suffix.'.'.DOMAIN_CURRENT_SITE, 'post_id' => (int)$post, 'media_url' => $upload['url']], JSON_UNESCAPED_SLASHES)."\n";
