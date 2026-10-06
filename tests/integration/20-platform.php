<?php

/**
 * Registration policy, read-only mode, site lifecycle, media, embeds,
 * analytics isolation, discovery, privacy and the contact endpoint.
 */

use NBE\Analytics;
use NBE\Config;
use NBE\Discovery;
use NBE\Embeds;
use NBE\EventLog;
use NBE\Media;
use NBE\Registration;
use NBE\Security;
use NBE\Sites;

global $wpdb;
[$owner, $site] = nbe_fixture('plat');
[$other, $otherSite] = nbe_fixture('platb');
$savedPolicy = get_site_option('nbe_registration_policy', null);
$capturedMail = [];
$captureMail = function ($return, $atts) use (&$capturedMail) {
    $capturedMail[] = $atts;
    return true; // short-circuit delivery
};
add_filter('pre_wp_mail', $captureMail, 10, 2);

// ---- Registration policy ---------------------------------------------------
wp_set_current_user(0);
update_site_option('nbe_registration_policy', 'invitation');
$result = wpmu_validate_user_signup('selfreg'.wp_rand(1000, 9999), 'self'.wp_rand().'@example.org');
verify($result['errors']->get_error_code() === 'nbe_policy', 'invitation mode refuses self-registration');
verify(get_site_option('registration') === 'blog', 'invitation mode closes user signup but keeps site creation for members');
switch_to_blog($site);
wp_set_current_user($owner);
$result = wpmu_validate_user_signup('invited'.wp_rand(1000, 9999), 'invited'.wp_rand().'@example.org');
verify(!$result['errors']->has_errors(), 'site administrators can still invite new people in invitation mode');
update_site_option('nbe_registration_denylist', 'blocked.example');
$result = wpmu_validate_user_signup('invited'.wp_rand(1000, 9999), 'x@blocked.example');
verify($result['errors']->get_error_code() === 'nbe_policy', 'deny list applies to invitations too');
update_site_option('nbe_registration_denylist', '');
restore_current_blog();

wp_set_current_user(0);
update_site_option('nbe_registration_policy', 'allowlist');
update_site_option('nbe_registration_allowlist', 'school.example');
verify(wpmu_validate_user_signup('student'.wp_rand(1000, 9999), 'a@school.example')['errors']->get_error_code() !== 'user_email', 'allowlisted domain may register');
verify(wpmu_validate_user_signup('student'.wp_rand(1000, 9999), 'a@elsewhere.example')['errors']->get_error_code() === 'user_email', 'other domains are refused in allowlist mode');

update_site_option('nbe_registration_policy', 'approval');
$login = 'pending'.wp_rand(10000, 99999);
$capturedMail = [];
wpmu_signup_user($login, $login.'@example.org', []);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->signups WHERE user_login = %s", $login));
$meta = maybe_unserialize($row->meta);
verify(!empty($meta['nbe_pending_approval']), 'approval mode holds new signups');
verify(!array_filter($capturedMail, fn ($m) => in_array($login.'@example.org', (array) $m['to'], true)), 'no activation email before approval');
verify(strlen($row->activation_key) === 40, 'held signup gets an unguessable replacement key');
verify((bool) array_filter(Registration::pending(), fn ($p) => $p->user_login === $login), 'pending signup is listed for operators');
verify(Registration::approve((int) $row->signup_id), 'operator can approve');
verify((bool) array_filter($capturedMail, fn ($m) => in_array($login.'@example.org', (array) $m['to'], true)), 'activation email is sent on approval');
$approved = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->signups WHERE user_login = %s", $login));
verify($approved->activation_key !== $row->activation_key && empty(maybe_unserialize($approved->meta)['nbe_pending_approval']), 'approval issues a new key and clears the hold');
$login2 = 'reject'.wp_rand(10000, 99999);
wpmu_signup_user($login2, $login2.'@example.org', []);
$row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->signups WHERE user_login = %s", $login2));
verify(Registration::reject((int) $row2->signup_id) && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->signups WHERE user_login = %s", $login2)), 'operator can reject');
$savedPolicy === null ? delete_site_option('nbe_registration_policy') : update_site_option('nbe_registration_policy', $savedPolicy);
delete_site_option('nbe_registration_allowlist');

// ---- Read-only mode ----------------------------------------------------------
verify(Security::readonlyAllows('wp-login.php', '', false), 'read-only: password sign-in stays possible');
verify(Security::readonlyAllows('wp-login.php', 'validate_2fa', false), 'read-only: two-factor step stays possible');
verify(!Security::readonlyAllows('wp-login.php', 'register', false), 'read-only: registration is paused');
verify(!Security::readonlyAllows('wp-login.php', 'lostpassword', false), 'read-only: password reset is paused');
verify(Security::readonlyAllows('admin-post.php', 'nbe_settings', true), 'read-only: operator can end maintenance');
verify(!Security::readonlyAllows('admin-post.php', 'nbe_settings', false), 'read-only: non-operators cannot change policy');
verify(!Security::readonlyAllows('admin-post.php', 'nbe_import', true), 'read-only: imports are paused even for operators');
update_site_option('nbe_readonly', true);
switch_to_blog($site);
wp_set_current_user($owner);
verify(!current_user_can('publish_posts') && !current_user_can('manage_options') && !current_user_can('moderate_comments'), 'read-only removes write capabilities');
$queued = \NBE\Migration::runNext();
verify($queued === false, 'read-only pauses the migration worker');
restore_current_blog();
update_site_option('nbe_readonly', false);

// ---- Sites ---------------------------------------------------------------------
verify(Sites::administeredCount($owner) === 1, 'site count includes only administered sites');
update_site_option('nbe_max_sites_per_user', 1);
verify(!Sites::canCreateMore($owner), 'site allowance is enforced');
update_site_option('nbe_max_sites_per_user', 3);
verify(Sites::canCreateMore($owner), 'site allowance follows network policy');
switch_to_blog($site);
verify((int) get_option('show_avatars') === 0 && (int) get_option('nbe_analytics') === 0 && (int) get_option('nbe_discoverable') === 0, 'new sites start private-by-default: no avatars, analytics or listing');
verify(str_contains(apply_filters('delete_site_email_content', 'x'), 'backups'), 'deletion email explains backup and federation retention');
restore_current_blog();

// ---- Media -----------------------------------------------------------------------
$disguised = tempnam(sys_get_temp_dir(), 'nbe');
file_put_contents($disguised, '%PDF-1.4 fake');
$check = Media::check(['tmp_name' => $disguised, 'name' => 'image.png', 'size' => filesize($disguised), 'error' => 0]);
verify(!empty($check['error']), 'file whose content does not match its extension is rejected');
file_put_contents($disguised, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
verify(!empty(Media::check(['tmp_name' => $disguised, 'name' => 'logo.svg', 'size' => filesize($disguised), 'error' => 0])['error']), 'SVG (script-capable) uploads are rejected');
unlink($disguised);
verify(isset(Media::mimes()['ods']) && !isset(Media::mimes()['svg']) && !isset(Media::mimes()['php']), 'MIME policy allows ODS but never SVG or PHP');

// ---- Embeds ------------------------------------------------------------------------
putenv('EMBED_IFRAME_HOSTS=www.openstreetmap.org');
$html = Embeds::filter('<p>x</p><iframe src="https://www.openstreetmap.org/export/embed.html?bbox=1" width="425" onload="alert(1)"></iframe><iframe src="https://evil.example/"></iframe>');
verify(str_contains($html, 'openstreetmap.org') && !str_contains($html, 'evil.example') && !str_contains($html, 'onload'), 'only allowlisted iframes survive, without event handlers');
verify(str_contains($html, 'sandbox="') && str_contains($html, 'referrerpolicy="no-referrer"'), 'allowed iframes are sandboxed and send no referrer');
putenv('EMBED_IFRAME_HOSTS');

// ---- Analytics isolation --------------------------------------------------------------
$table = Analytics::table();
$wpdb->insert($table, ['site_id' => $site, 'day' => gmdate('Y-m-d'), 'post_id' => 1, 'browser' => 'firefox', 'device' => 'mobile', 'views' => 7]);
$wpdb->insert($table, ['site_id' => $site, 'day' => gmdate('Y-m-d'), 'post_id' => 1, 'browser' => 'automated', 'device' => 'automated', 'views' => 3]);
$wpdb->insert($table, ['site_id' => $site, 'day' => '2000-01-01', 'post_id' => 1, 'browser' => 'chrome', 'device' => 'desktop', 'views' => 99]);
$summaryA = Analytics::summary($site, 14);
$summaryB = Analytics::summary($otherSite, 14);
verify($summaryA['total'] === 7 && $summaryA['automated'] === 3 && ($summaryA['devices']['mobile'] ?? 0) === 7, 'site analytics aggregate people and automated requests separately');
verify($summaryB['total'] === 0 && !$summaryB['posts'], 'site B cannot see site A analytics');
Analytics::purge();
verify(!(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE site_id = %d AND day = '2000-01-01'", $site)), 'analytics retention removes old rows');
$columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
verify(!array_intersect($columns, ['ip', 'user_agent', 'url', 'referrer', 'visitor']), 'analytics schema stores no identifying columns');
switch_to_blog($otherSite);
wp_set_current_user($owner);
verify(rest_do_request(new WP_REST_Request('GET', '/nbe/v1/analytics'))->get_status() === 403, 'analytics REST is tenant-scoped');
restore_current_blog();

// ---- Discovery ----------------------------------------------------------------------------
switch_to_blog($site);
wp_set_current_user($owner);
$public = wp_insert_post(['post_title' => 'Listed public post '.$site, 'post_status' => 'publish', 'post_author' => $owner]);
wp_insert_post(['post_title' => 'Hidden draft '.$site, 'post_status' => 'draft', 'post_author' => $owner]);
wp_insert_post(['post_title' => 'Hidden protected '.$site, 'post_status' => 'publish', 'post_password' => 'pw', 'post_author' => $owner]);
update_option('nbe_discoverable', 1);
restore_current_blog();
Discovery::setListed($site, true);
switch_to_blog($otherSite);
wp_insert_post(['post_title' => 'Private site post '.$otherSite, 'post_status' => 'publish', 'post_author' => $other]);
update_option('nbe_discoverable', 1);
update_option('nbe_private', 1);
restore_current_blog();
Discovery::setListed($otherSite, true);
Discovery::rebuild();
$titles = array_column(Discovery::posts(100), 'title');
verify(in_array('Listed public post '.$site, $titles, true), 'discovery lists public posts of opted-in sites');
verify(!in_array('Hidden draft '.$site, $titles, true) && !in_array('Hidden protected '.$site, $titles, true), 'discovery never lists drafts or password-protected posts');
verify(!in_array('Private site post '.$otherSite, $titles, true), 'discovery never lists members-only sites');
verify(str_contains(do_shortcode('[nbe_directory]'), 'Plat '), 'directory shortcode renders listed sites');

// ---- Privacy of members-only sites ------------------------------------------------------
switch_to_blog($otherSite);
wp_set_current_user(0);
verify(rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts'))->get_status() === 403, 'members-only site refuses anonymous REST reads');
wp_set_current_user($owner);
verify(rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts'))->get_status() === 403, 'members-only site refuses non-members');
wp_set_current_user($other);
verify(rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts'))->get_status() === 200, 'members-only site serves its members');
update_option('nbe_private', 0);
wp_set_current_user(0);
verify(rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'))->get_status() === 404, 'anonymous visitors cannot enumerate users over REST');
restore_current_blog();
switch_to_blog($site);
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_USER_AGENT'] = 'Browser/1.0';
$viaForm = wp_new_comment(['comment_post_ID' => $public, 'comment_content' => 'Nice post', 'comment_author' => 'Reader', 'comment_author_email' => 'r@example.invalid', 'comment_author_url' => '', 'comment_type' => 'comment', 'comment_parent' => 0, 'user_id' => 0], true);
$direct = wp_insert_comment(['comment_post_ID' => $public, 'comment_content' => 'hi', 'comment_author_IP' => '203.0.113.9', 'comment_agent' => 'Browser']);
unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
verify(!is_wp_error($viaForm), 'anonymous comments are not mistaken for a flood although IPs are not stored');
foreach ([$viaForm, $direct] as $comment) {
    $stored = is_wp_error($comment) ? null : get_comment($comment);
    verify($stored && $stored->comment_author_IP === '' && $stored->comment_agent === '', 'comment IP address and user agent are never stored');
}
verify(!str_contains((string) get_avatar(1), 'gravatar.com'), 'avatars are served locally (no Gravatar request)');
restore_current_blog();

// ---- Encrypted contact endpoint -----------------------------------------------------------
switch_to_blog($site);
$pair = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pem = openssl_pkey_get_details($pair)['key'];
$key = \NBE\Contact::key($pem);
update_option('nbe_contact_key', $key['pem']);
update_option('nbe_contact_fingerprint', $key['fingerprint']);
update_option('nbe_contact_email', 'owner@example.invalid');
$request = new WP_REST_Request('POST', '/nbe/v1/contact');
$request->set_header('origin', rtrim(home_url(), '/'));
$request->set_header('content-type', 'application/json');
$envelope = ['version' => 1, 'fingerprint' => $key['fingerprint'], 'iv' => base64_encode(random_bytes(12)), 'key' => base64_encode(random_bytes(384)), 'ciphertext' => base64_encode(random_bytes(64)), 'plaintext' => 'SHOULD-NOT-BE-FORWARDED'];
$request->set_body(wp_json_encode($envelope));
$capturedMail = [];
$response = rest_do_request($request);
verify($response->get_status() === 200 && count($capturedMail) === 1, 'contact endpoint forwards a valid ciphertext envelope');
verify(!str_contains($capturedMail[0]['message'], 'SHOULD-NOT-BE-FORWARDED'), 'contact endpoint forwards only envelope fields');
$request->set_header('origin', 'https://evil.example');
verify(rest_do_request($request)->get_status() === 403, 'contact endpoint rejects cross-origin posts');
$request->set_header('origin', rtrim(home_url(), '/'));
$request->set_body(wp_json_encode(['fingerprint' => 'stale'] + $envelope));
verify(rest_do_request($request)->get_status() === 409, 'contact endpoint rejects a stale recipient key');
$threw = false;
try {
    \NBE\Contact::key("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----");
} catch (RuntimeException $e) {
    $threw = true;
}
verify($threw, 'private keys are refused as contact keys');
restore_current_blog();

// ---- Event log -----------------------------------------------------------------------------
EventLog::record('test_event', ['user' => 5, 'password' => 'hunter2', 'url' => 'https://x/?token=abc', 'code' => 'ok<script>']);
$last = EventLog::recent(1, 'test_event')[0] ?? [];
verify(($last['user'] ?? 0) === 5 && !isset($last['password']) && !isset($last['url']), 'event log keeps only allowlisted fields');
verify(($last['code'] ?? '') === 'okscript', 'event log strips markup from values');
verify(Config::registrationPolicy() !== '', 'configuration resolves a registration policy');
remove_filter('pre_wp_mail', $captureMail, 10);
