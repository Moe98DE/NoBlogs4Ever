<?php

/**
 * Idempotent network configuration, run by bootstrap.php through WP-CLI after
 * install and after every upgrade. Safe to re-run.
 */
if (!defined('ABSPATH')) {
    exit(1);
}
require_once ABSPATH.'wp-admin/includes/plugin.php';

// Network-activated, operator-managed plugins. Classic Editor is network-active
// with "allow sites to choose": the block editor stays the default, and each site
// (Settings → Writing) and each author (per post) can switch to the classic editor.
foreach (['two-factor/two-factor.php', 'two-factor-provider-webauthn/index.php', 'classic-editor/classic-editor.php'] as $plugin) {
    if (!is_plugin_active_for_network($plugin)) {
        $result = activate_plugin($plugin, '', true);
        if (is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
    }
}

global $wpdb;
if (getenv('APP_ENV') !== 'production') {
    // Multisite stores bare hostnames; the development port lives only in home/siteurl.
    $wpdb->update($wpdb->site, ['domain' => DOMAIN_CURRENT_SITE], ['id' => 1]);
    $wpdb->update($wpdb->blogs, ['domain' => DOMAIN_CURRENT_SITE], ['blog_id' => 1]);
    wp_cache_flush();
}
$scheme = getenv('PLATFORM_SCHEME') ?: 'http';
$port = getenv('PLATFORM_PORT') ?: (getenv('APP_ENV') === 'production' ? '443' : '8080');
$baseUrl = $scheme.'://'.DOMAIN_CURRENT_SITE.(in_array($port, ['80', '443'], true) ? '' : ':'.$port);
update_option('home', $baseUrl);
update_option('siteurl', $baseUrl);

// Curated themes: everything shipped in the image and listed in the lock file.
$lock = json_decode((string) file_get_contents('/opt/nbe/dependencies.lock.json'), true) ?: [];
$themes = [];
foreach ($lock as $dependency) {
    if (($dependency['kind'] ?? '') === 'theme' && wp_get_theme($dependency['slug'])->exists()) {
        $themes[$dependency['slug']] = true;
    }
}
update_site_option('allowedthemes', $themes ?: ['twentytwentyfive' => true]);

update_site_option('add_new_users', 1);            // site admins may invite new people
update_site_option('menu_items', ['plugins' => 1]); // site admins see the curated Plugins screen
update_site_option('upload_space_check_disabled', 0);
update_site_option('blog_upload_space', (int) (getenv('SITE_QUOTA_MB') ?: 1024));
update_site_option('classic-editor-replace', 'block');
update_site_option('classic-editor-allow-sites', 'allow');
update_option('classic-editor-replace', 'block');
update_option('classic-editor-allow-users', 'allow');
$firstRun = !get_site_option('nbe_configured');
if ($firstRun) {
    update_site_option('welcome_email', "Hello USERNAME,\n\nYour new site is ready: BLOG_URL\n\nSign in at BLOG_URLwp-login.php with the username USERNAME.\n\nWe recommend turning on two-factor authentication under Your platform → Account security.\n\n--The SITE_NAME team");
}
if (getenv('APP_ENV') === 'production') {
    update_site_option('admin_email', getenv('NBE_ADMIN_EMAIL') ?: get_site_option('admin_email'));
}
// Scheduling is done by the worker container; remove the 0.x hourly hook if present.
wp_clear_scheduled_hook('nbe_tick');

if ($firstRun) {
    switch_theme('twentytwentyfive');
    update_option('show_avatars', 0);
    update_site_option('nbe_configured', 1);
}
\NBE\Platform::install();

// A discovery page on the main site (created once; operators may edit it freely).
if (!get_option('nbe_discover_page')) {
    $page = wp_insert_post([
        'post_type' => 'page',
        'post_status' => 'publish',
        'post_title' => 'Discover',
        'post_name' => 'discover',
        'post_content' => "<!-- wp:paragraph --><p>Recent writing from sites on this platform that chose to be listed.</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->[nbe_topics]<!-- /wp:shortcode -->\n<!-- wp:shortcode -->[nbe_recent count=\"20\"]<!-- /wp:shortcode -->\n<!-- wp:heading --><h2 class=\"wp-block-heading\">Directory</h2><!-- /wp:heading -->\n<!-- wp:shortcode -->[nbe_directory]<!-- /wp:shortcode -->",
    ]);
    if (!is_wp_error($page)) {
        update_option('nbe_discover_page', (int) $page);
    }
}

echo "Network policy and curated software configured.\n";
