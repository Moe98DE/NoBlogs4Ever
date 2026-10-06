<?php

/**
 * Full site archive: build, verify contents and checksums, prove that other
 * tenants' files and secrets are excluded, then re-import into a fresh site.
 * Uses the site created by 30-presentation-migration.php when available.
 */

use NBE\Export;
use NBE\Migration;

global $wpdb;
$capture = fn () => true;
add_filter('pre_wp_mail', $capture);
if (empty($GLOBALS['nbe_presentation_site'])) {
    [$owner, $site] = nbe_fixture('exp');
    switch_to_blog($site);
    wp_insert_post(['post_title' => 'Export me', 'post_status' => 'publish', 'post_author' => $owner]);
    wp_upload_bits('export-me.txt', null, 'tenant file');
    restore_current_blog();
} else {
    $site = $GLOBALS['nbe_presentation_site'];
    $owner = $GLOBALS['nbe_presentation_owner'];
}
[$stranger, $strangerSite] = nbe_fixture('strg');
switch_to_blog($strangerSite);
$foreign = wp_upload_bits('stranger-secret.txt', null, 'belongs to another tenant');
restore_current_blog();

$id = Export::queue($site, $owner);
Export::runNext();
$row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Export::table().' WHERE id = %s', $id));
verify($row->state === 'complete', 'full site archive is built by the worker');
$file = Export::root().'/'.$id.'.zip';
verify(is_file($file) && hash_file('sha256', $file) === $row->sha256, 'archive checksum recorded');
verify((fileperms($file) & 0077) === 0, 'archive is private on disk');
$zip = new ZipArchive();
$zip->open($file);
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $names[] = $zip->getNameIndex($i);
}
$manifest = json_decode($zip->getFromName('manifest.json'), true);
$siteJson = json_decode($zip->getFromName('site.json'), true);
$xml = $zip->getFromName('publication.xml');
verify(in_array('publication.xml', $names, true) && in_array('site.json', $names, true) && in_array('README.txt', $names, true), 'archive contains WXR, settings and README');
verify((bool) array_filter($names, fn ($n) => str_starts_with($n, 'uploads/')), 'archive contains uploaded media');
verify(!array_filter($names, fn ($n) => str_contains($n, 'stranger-secret')), 'archive never contains another tenant\'s files');
$valid = true;
foreach ($manifest['files'] as $name => $entry) {
    $valid = $valid && hash('sha256', (string) $zip->getFromName($name)) === $entry['sha256'];
}
verify($valid && count($manifest['files']) === count($names) - 1, 'manifest checksums match every file');
$everything = $xml.json_encode($siteJson);
verify(!preg_match('/user_pass|\$P\$|\$wp\$|\$2y\$|nbe_contact_key|two_factor|session_tokens|DB_PASSWORD/i', $everything), 'archive contains no password hashes, keys or session data');
$zip->close();
if (!empty($GLOBALS['nbe_presentation_site'])) {
    verify(str_contains($siteJson['custom_css'], 'letter-spacing') && $siteJson['theme']['stylesheet'] === 'twentytwentyfive', 'site settings include theme and Additional CSS');
}

// Round trip: import the archive unchanged into a new site.
[$newOwner, $newSite] = nbe_fixture('rtrp');
switch_to_blog($newSite);
switch_theme('twentytwentyone');
restore_current_blog();
$wxrFile = tempnam(sys_get_temp_dir(), 'wxr');
file_put_contents($wxrFile, $xml);
$authors = array_fill_keys(\NBE\Archive::wxr($wxrFile)['authors'], $newOwner);
unlink($wxrFile);
$job = Migration::intake($file, $newSite, $newOwner, $authors);
$jobRow = nbe_run_job($job);
$report = json_decode($jobRow->report, true);
verify(in_array($jobRow->state, ['complete', 'needs_attention'], true) && !$report['media_missing'], 'a full site archive re-imports with all media');
switch_to_blog($site);
$sourceSlugs = wp_list_pluck(get_posts(['numberposts' => -1, 'post_type' => ['post', 'page']]), 'post_name');
restore_current_blog();
switch_to_blog($newSite);
$newSlugs = wp_list_pluck(get_posts(['numberposts' => -1, 'post_type' => ['post', 'page']]), 'post_name');
verify($sourceSlugs && !array_diff($sourceSlugs, $newSlugs), 'round trip keeps every published post and page');
if (!empty($GLOBALS['nbe_presentation_site'])) {
    verify(get_stylesheet() === 'twentytwentyfive', 'round trip restores the curated theme from site.json');
    verify(str_contains(wp_get_custom_css(), 'letter-spacing'), 'round trip restores Additional CSS');
    verify(wp_get_nav_menu_object('main-menu') !== false, 'round trip restores menus');
}
restore_current_blog();

Export::purge();
verify(is_file($file), 'recent archives are kept until retention expires');
$wpdb->update(Export::table(), ['updated' => '2000-01-01 00:00:00'], ['id' => $id]);
Export::purge();
verify(!is_file($file) && !$wpdb->get_var($wpdb->prepare('SELECT id FROM '.Export::table().' WHERE id = %s', $id)), 'expired archives are deleted');
remove_filter('pre_wp_mail', $capture);
