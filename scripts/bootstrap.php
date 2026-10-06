<?php

// Execute with PHP. Password material is consumed only by install-single.php from a mounted file.
declare(strict_types=1);
$root = getenv('WP_ROOT') ?: '/var/www/html';
$host = getenv('PLATFORM_DOMAIN') ?: 'lvh.me';
$scheme = getenv('PLATFORM_SCHEME') ?: 'http';
$port = getenv('PLATFORM_PORT') ?: (getenv('APP_ENV') === 'production' ? '443' : '8080');
$url = $scheme.'://'.$host.(in_array($port, ['80','443'], true) ? '' : ':'.$port);
$admin = getenv('NBE_ADMIN_USER') ?: 'operator';
$email = getenv('NBE_ADMIN_EMAIL') ?: 'operator@example.invalid';
$file = getenv('NBE_ADMIN_PASSWORD_FILE') ?: '/run/secrets/admin_password';
if (!is_readable($file) || strlen(trim((string)file_get_contents($file))) < 20) {
    fwrite(STDERR, "Create a private administrator password of at least 20 characters and configure NBE_ADMIN_EMAIL before bootstrap.\n");
    exit(1);
}
$wpCliBin = getenv('WP_CLI_BIN') ?: '/usr/local/bin/wp';
function command(array $args): void
{
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    passthru($cmd, $code);
    if ($code) {
        exit($code);
    }
}
// WP-CLI refuses eval-file on an empty database. Loading WordPress directly
// avoids its pre-install check and keeps the password inside this PHP process.
// Do not use WP-CLI --prompt here: it echoes the completed command, including
// the supplied administrator password, to stdout.
putenv('NBE_INSTALLING=1');
define('WP_CLI', true);
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = $host;
$_SERVER['SERVER_NAME'] = $host;
$_SERVER['REQUEST_URI'] = '/';
require $root.'/wp-load.php';
require __DIR__.'/install-single.php';
$blogsTable = $wpdb->base_prefix.'blogs';
$networkInstalled = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($blogsTable))) === $blogsTable;
putenv('NBE_INSTALLING');
// The database is now installed, so multisite-install never generates or prints an administrator password.
if (!$networkInstalled) {
    command([$wpCliBin, 'core', 'multisite-install', '--path='.$root, '--url='.$url, '--title='.(getenv('BRAND_NAME') ?: 'NoBlogs4Ever'), '--admin_user='.$admin, '--admin_email='.$email, '--subdomains', '--skip-email', '--allow-root']);
    echo "Network installed.\n";
} else {
    echo "Existing network tables preserved.\n";
}
// WP-CLI resolves multisite blogs by the bare wp_blogs.domain. The public
// development URL contains a port, but that port must not be used for lookup.
command([$wpCliBin, 'eval-file', __DIR__.'/configure.php', '--path='.$root, '--url='.$scheme.'://'.$host, '--allow-root']);
