<?php

/**
 * Second migration fixture (tests/fixtures/presentation.xml): several authors,
 * an invited author, scheduled/password/sticky posts, a large image with
 * derivatives, synced patterns, menus, Additional CSS and a Site Editor part.
 */

use NBE\Migration;
use NBE\MigrationAdmin;

global $wpdb;
[$owner, $site] = nbe_fixture('pres');
$capture = fn () => true;
add_filter('pre_wp_mail', $capture);

$wide = tempnam(sys_get_temp_dir(), 'wide');
$img = imagecreatetruecolor(3000, 1500);
imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
imagejpeg($img, $wide, 85);
$archive = nbe_zip([
    'export/old-example.WordPress.2021-03-06.xml' => __DIR__.'/../fixtures/presentation.xml',
    'export/uploads/2021/03/wide.jpg' => $wide,
    'export/wp-content/plugins/evil/evil.php' => '<?php system($_GET["c"]);',
]);

$job = Migration::intake($archive, $site, $owner);
$row = nbe_run_job($job);
$report = json_decode($row->report, true);
verify($row->state === 'awaiting_author_mapping', 'second fixture pauses for author mapping');
verify($report['authors'] === ['old-bob', 'old-carol'], 'both source authors are inventoried');
verify($report['inventory']['posts'] === 3 && $report['inventory']['pages'] === 1, 'inventory counts posts and pages');
verify(in_array('export/wp-content/plugins/evil/evil.php', $report['inventory']['ignored'], true), 'plugin code inside an archive is inventoried as ignored, never executed');
verify(in_array('series', $report['inventory']['custom_post_types'], true) === false, 'taxonomies are not mistaken for post types');

switch_to_blog($site);
wp_set_current_user($owner);
$carol = MigrationAdmin::invite('old-carol', 'carol'.wp_rand().'@example.invalid', $site);
verify(is_user_member_of_blog($carol, $site) && user_can($carol, 'publish_posts') && !user_can($carol, 'manage_options'), 'an invited author joins the site as Author');
restore_current_blog();
Migration::saveAuthorMap(Migration::job($job), ['old-bob' => $owner, 'old-carol' => $carol]);
$row = nbe_run_job($job, 3);
$report = json_decode($row->report, true);
verify(in_array($row->state, ['complete', 'needs_attention'], true), 'second fixture import finishes');
verify(!$report['media_missing'], 'large image imported (integrity verified against the original, not the scaled copy)');
verify(in_array('series', $report['unsupported_taxonomies'], true), 'unsupported custom taxonomy is reported');

switch_to_blog($site);
$soup = get_posts(['name' => 'winter-soup', 'post_status' => 'any', 'numberposts' => 1])[0];
$members = get_posts(['name' => 'members-recipe', 'post_status' => 'any', 'numberposts' => 1])[0];
$future = get_posts(['name' => 'spring-preview', 'post_status' => 'any', 'numberposts' => 1])[0];
verify((int) $soup->post_author === $owner && (int) $members->post_author === $carol, 'each source author maps to the chosen account');
verify($members->post_password === 'open-sesame', 'password-protected posts stay protected');
verify($future->post_status === 'future' && $future->post_date === '2099-04-01 09:00:00', 'scheduled posts stay scheduled at their original time');
verify(is_sticky($soup->ID), 'sticky flag preserved');
verify($soup->post_modified === '2021-03-05 09:00:00', 'modification date preserved');
$attachment = (int) get_post_thumbnail_id($soup->ID);
$meta = wp_get_attachment_metadata($attachment);
verify($attachment && str_contains((string) get_attached_file($attachment), '-scaled') && is_file((string) wp_get_original_image_path($attachment)), 'large image is scaled for the web and the original is kept');
verify(get_post_meta($attachment, '_wp_attachment_image_alt', true) === 'A wide landscape', 'image alt text preserved');
verify(!str_contains($soup->post_content, 'old.example'), 'derivative and full-size image URLs rewritten to local files');
verify(str_contains($soup->post_content, 'wide-300x150.jpg') && str_contains($soup->post_content, 'wp-image-'.$attachment), 'derivative URL points at the matching local size and the image class at the new ID');
verify(str_contains($soup->post_content, '"id":'.$attachment), 'block attribute attachment ID remapped');
$pattern = get_posts(['post_type' => 'wp_block', 'name' => 'signature', 'numberposts' => 1])[0] ?? null;
verify($pattern && str_contains($soup->post_content, '"ref":'.$pattern->ID), 'synced pattern reference remapped');
$soups = get_term_by('slug', 'soups', 'category');
$recipes = get_term_by('slug', 'recipes', 'category');
verify($soups && $recipes && (int) $soups->parent === (int) $recipes->term_id, 'category hierarchy preserved');

$menu = wp_get_nav_menu_object('main-menu');
$items = $menu ? wp_get_nav_menu_items($menu->term_id) : [];
verify($menu && count($items) === 3, 'navigation menu rebuilt with every item');
$byTitle = [];
foreach ($items as $item) {
    $byTitle[$item->title] = $item;
}
$about = get_page_by_path('about');
verify(isset($byTitle['About']) && (int) $byTitle['About']->object_id === $about->ID, 'page menu item points at the imported page');
verify(isset($byTitle['Home']) && str_starts_with($byTitle['Home']->url, home_url()), 'custom menu link to the old site rewritten to the new site');
verify(isset($byTitle['Soups']) && (int) $byTitle['Soups']->menu_item_parent === (int) $byTitle['About']->ID, 'menu hierarchy preserved even when children come first');
verify(str_contains(wp_get_custom_css('twentytwentyfive'), 'letter-spacing'), 'Additional CSS restored for a curated theme');
verify((bool) array_filter($report['presentation'], fn ($l) => str_contains($l, 'retro-theme')), 'CSS for a non-curated theme is reported, not applied');
$part = get_posts(['post_type' => 'wp_template_part', 'name' => 'footer', 'numberposts' => 1, 'post_status' => 'any'])[0] ?? null;
verify($part && has_term('twentytwentyfive', 'wp_theme', $part), 'Site Editor template part imported for its curated theme');

$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts");
restore_current_blog();
Migration::retry(Migration::job($job));
nbe_run_job($job);
switch_to_blog($site);
verify((int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts") === $before && count(wp_get_nav_menu_items($menu->term_id)) === 3, 'running the import again duplicates nothing (menus included)');
restore_current_blog();

$GLOBALS['nbe_presentation_site'] = $site;
$GLOBALS['nbe_presentation_owner'] = $owner;
unlink($archive);
unlink($wide);
remove_filter('pre_wp_mail', $capture);
