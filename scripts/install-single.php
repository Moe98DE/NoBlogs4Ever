<?php

if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI.');
}
if (is_blog_installed()) {
    echo "Single-site tables already exist; preserving the administrator credential.\n";
    return;
}
$file = getenv('NBE_ADMIN_PASSWORD_FILE') ?: '/run/secrets/admin_password';
$password = is_readable($file) ? trim((string)file_get_contents($file)) : '';
$admin = getenv('NBE_ADMIN_USER') ?: 'operator';
$email = getenv('NBE_ADMIN_EMAIL') ?: 'operator@example.invalid';
if (strlen($password) < 20 || !is_email($email)) {
    throw new RuntimeException('Administrator password or email is invalid.');
}
require_once ABSPATH.'wp-admin/includes/upgrade.php';
// wp_install() passes the password through unslashed; do not wp_slash() it.
wp_install(getenv('BRAND_NAME') ?: 'NoBlogs4Ever', $admin, $email, true, '', $password);
global $wpdb;
if ($wpdb->last_error || !is_blog_installed()) {
    throw new RuntimeException('Single-site table installation failed.');
}
echo "Single-site tables installed without exposing the administrator password.\n";
