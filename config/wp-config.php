<?php

function nbe_secret(string $key): string
{
    $file = getenv($key . '_FILE') ?: '/run/nbe-secrets/' . strtolower($key);
    if (is_readable($file)) {
        return trim(file_get_contents($file));
    }
    throw new RuntimeException('Required secret unavailable: ' . $key);
}
define('DB_NAME', getenv('DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('DB_USER') ?: 'wordpress');
define('DB_PASSWORD', nbe_secret('DB_PASSWORD'));
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $name) {
    define($name, hash_hmac('sha512', $name, nbe_secret('WP_SALT')));
}
$table_prefix = 'wp_';
define('WP_ALLOW_MULTISITE', true);
$nbe_installing = getenv('NBE_INSTALLING') === '1';
$network_host = getenv('PLATFORM_DOMAIN') ?: 'lvh.me';
// Multisite stores bare hostnames in wp_site/wp_blogs. A development port
// belongs in home/siteurl, never in DOMAIN_CURRENT_SITE.
if (getenv('APP_ENV') !== 'production' && isset($_SERVER['HTTP_HOST'])) {
    $request_host = strtolower((string) parse_url('http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST));
    $base_host = strtolower($network_host);
    if ($request_host === $base_host || str_ends_with($request_host, '.' . $base_host)) {
        $_SERVER['NBE_PUBLIC_HTTP_HOST'] = $_SERVER['HTTP_HOST'];
        $_SERVER['HTTP_HOST'] = $request_host;
        $_SERVER['SERVER_NAME'] = $request_host;
    }
}
if (!$nbe_installing) {
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', true);
    define('DOMAIN_CURRENT_SITE', $network_host);
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
}
define('COOKIE_DOMAIN', false);
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_ENVIRONMENT_TYPE', getenv('APP_ENV') === 'production' ? 'production' : 'development');
define('FORCE_SSL_ADMIN', getenv('APP_ENV') === 'production');
define('WP_MEMORY_LIMIT', '256M');
// Only the private reverse proxy can connect to the app container.
if (getenv('APP_ENV') === 'production' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (getenv('APP_ENV') === 'production' && (getenv('PLATFORM_SCHEME') !== 'https')) {
    throw new RuntimeException('Production requires HTTPS');
}
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
