<?php

// Run through WP-CLI; output contains versions only, never secrets.
global $wpdb;
require_once ABSPATH.'wp-admin/includes/plugin.php';
$plugins = [];
foreach (get_plugins() as $file => $data) {
    $plugins[$file] = $data['Version'];
}
$themes = [];
foreach (wp_get_themes() as $slug => $theme) {
    $themes[$slug] = $theme->get('Version');
}
echo wp_json_encode(['captured_at' => gmdate('c'),'wordpress' => get_bloginfo('version'),'php' => PHP_VERSION,'database' => $wpdb->get_var('SELECT VERSION()'),'plugins' => $plugins,'themes' => $themes,'multisite' => is_multisite(),'subdomains' => is_subdomain_install()], JSON_PRETTY_PRINT)."\n";
