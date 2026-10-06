<?php

declare(strict_types=1);

namespace NBE;

/**
 * Tools → Import publication: upload, author mapping, progress, report.
 */
final class MigrationAdmin
{
    private const STATE_LABELS = [
        'intake' => 'Waiting for inventory',
        'awaiting_author_mapping' => 'Map authors to continue',
        'queued' => 'Queued',
        'running' => 'Importing',
        'complete' => 'Complete',
        'needs_attention' => 'Complete — needs attention',
        'failed' => 'Failed',
    ];

    public static function register(): void
    {
        add_action('admin_menu', function (): void {
            add_management_page(__('Import publication'), __('Import publication'), 'manage_options', 'nbe-import', [self::class, 'page']);
        });
        add_action('admin_post_nbe_import', [self::class, 'upload']);
        add_action('admin_post_nbe_author_map', [self::class, 'authorMap']);
        add_action('admin_post_nbe_retry', [self::class, 'retry']);
        add_action('admin_post_nbe_report', [self::class, 'downloadReport']);
    }

    private static function back(array $args = []): void
    {
        wp_safe_redirect(add_query_arg($args, admin_url('tools.php?page=nbe-import')));
        exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'));
        }
        global $wpdb;
        $max = size_format(min(Archive::MAX_INPUT, wp_max_upload_size()));
        echo '<div class="wrap"><h1>'.esc_html__('Import publication').'</h1>';
        if (!empty($_GET['nbe_error'])) {
            echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['nbe_error']))).'</p></div>';
        }
        if (!empty($_GET['nbe_ok'])) {
            echo '<div class="notice notice-success"><p>'.esc_html__('Saved. The import continues in the background; this page shows its progress.').'</p></div>';
        }
        echo '<p>'.esc_html__('Bring an existing WordPress or NoBlogs publication here. Upload a WordPress export (WXR .xml), or a .zip containing the export plus your media files (for example a wp-content/uploads or blogs.dir/…/files folder, or a full site archive from this platform).').'</p>';
        echo '<ul class="ul-disc"><li>'.esc_html__('Your original file is kept privately and nothing in it is executed. Theme and plugin code inside an archive is ignored.').'</li>';
        echo '<li>'.esc_html__('Media must be inside the archive; nothing is downloaded from the old site. Missing files are listed in the report — upload a corrected archive later and only the missing pieces are added.').'</li>';
        echo '<li>'.esc_html__('Publication dates, statuses, comments, categories, tags, featured images, menus and Additional CSS (for curated themes) are preserved. Passwords, logins and connected accounts are never imported.').'</li></ul>';
        echo '<form enctype="multipart/form-data" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_import');
        echo '<input type="hidden" name="action" value="nbe_import"><p><label for="nbe-archive"><strong>'.esc_html__('Publication archive').'</strong></label><br><input id="nbe-archive" required type="file" name="archive" accept=".xml,.zip" aria-describedby="nbe-archive-help"></p>';
        echo '<p id="nbe-archive-help" class="description">'.esc_html(sprintf(
            /* translators: %s: size */
            __('Maximum %s. Larger archives can be imported by the platform operator.'),
            $max
        )).'</p>';
        submit_button(__('Upload and take inventory'));
        echo '</form>';

        echo '<h2>'.esc_html__('Imports').'</h2>';
        $jobs = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Migration::table().' WHERE site_id = %d ORDER BY created DESC LIMIT 30', get_current_blog_id()));
        if (!$jobs) {
            echo '<p>'.esc_html__('No imports yet.').'</p>';
        }
        foreach ($jobs as $job) {
            self::renderJob($job);
        }
        echo '</div>';
    }

    private static function renderJob(object $job): void
    {
        $report = json_decode($job->report, true) ?: [];
        $label = self::STATE_LABELS[$job->state] ?? $job->state;
        echo '<div class="card" style="max-width:none"><h3>'.esc_html(sprintf(__('Import started %s UTC'), $job->created)).'</h3>';
        echo '<p><strong>'.esc_html__('Status:').'</strong> '.esc_html($label);
        if ((int) $job->total > 0) {
            echo ' — '.esc_html(sprintf(__('%1$d of %2$d items processed'), (int) $job->cursor, (int) $job->total));
            echo ' <progress max="'.(int) $job->total.'" value="'.(int) $job->cursor.'" aria-label="'.esc_attr__('Import progress').'"></progress>';
        }
        echo '</p>';
        if ($job->state === 'awaiting_author_mapping') {
            self::renderAuthorMap($job, $report);
        }
        if (!empty($report['errors'])) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(implode(' ', array_map('strval', $report['errors']))).'</p></div>';
        }
        if (in_array($job->state, ['complete', 'needs_attention', 'failed'], true) || !empty($report['inventory'])) {
            self::renderReport($report);
        }
        echo '<p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=nbe_report&job='.$job->id), 'nbe_report')).'">'.esc_html__('Download full report (JSON)').'</a> ';
        if (in_array($job->state, ['failed', 'needs_attention', 'complete'], true)) {
            echo '<form style="display:inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('nbe_retry');
            echo '<input type="hidden" name="action" value="nbe_retry"><input type="hidden" name="job" value="'.esc_attr($job->id).'">';
            submit_button(__('Run again'), 'secondary', 'submit', false, ['aria-describedby' => 'nbe-retry-'.$job->id]);
            echo '</form> <span class="description" id="nbe-retry-'.esc_attr($job->id).'">'.esc_html__('Safe to repeat: items already imported are skipped.').'</span>';
        }
        echo '</p></div>';
    }

    /** @param array<string, mixed> $report */
    private static function renderAuthorMap(object $job, array $report): void
    {
        $members = get_users(['blog_id' => get_current_blog_id(), 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_login']]);
        echo '<h4>'.esc_html__('Who wrote what?').'</h4><p>'.esc_html__('Choose a member of this site for each author in the archive. Several source authors may map to the same person. To add someone new, choose “Invite by email” — they get an email to set their own password.').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_author_map_'.$job->id);
        echo '<input type="hidden" name="action" value="nbe_author_map"><input type="hidden" name="job" value="'.esc_attr($job->id).'"><table class="form-table" role="presentation"><tbody>';
        foreach ((array) ($report['authors'] ?? []) as $index => $source) {
            $field = 'nbe-author-'.(int) $index;
            echo '<tr><th scope="row"><label for="'.esc_attr($field).'">'.esc_html($source !== '' ? $source : __('(no author recorded)')).'</label></th><td>';
            echo '<select id="'.esc_attr($field).'" name="authors['.(int) $index.']" required><option value="">'.esc_html__('Choose…').'</option>';
            foreach ($members as $member) {
                echo '<option value="'.(int) $member->ID.'"'.selected((int) $member->ID, get_current_user_id(), false).'>'.esc_html($member->display_name.' ('.$member->user_login.')').'</option>';
            }
            echo '<option value="invite">'.esc_html__('Invite by email…').'</option></select> ';
            echo '<label class="screen-reader-text" for="'.esc_attr($field).'-email">'.esc_html__('Email for invitation').'</label><input id="'.esc_attr($field).'-email" type="email" name="invite['.(int) $index.']" placeholder="'.esc_attr__('email, if inviting').'">';
            echo '</td></tr>';
        }
        echo '</tbody></table><p class="description">'.esc_html__('Invited people join this site as Authors. Old passwords and roles are never imported.').'</p>';
        submit_button(__('Save and start import'), 'primary', 'submit', false);
        echo '</form>';
    }

    /** @param array<string, mixed> $report */
    private static function renderReport(array $report): void
    {
        $inv = (array) ($report['inventory'] ?? []);
        $rows = [
            __('Archive SHA-256') => '<code style="font-size:11px">'.esc_html((string) ($report['archive_checksum'] ?? '')).'</code>',
            __('Found in archive') => esc_html(sprintf(
                __('%1$d posts, %2$d pages, %3$d attachment records, %4$d comments, %5$d media files, %6$d categories, %7$d tags'),
                (int) ($inv['posts'] ?? 0),
                (int) ($inv['pages'] ?? 0),
                (int) ($inv['attachments'] ?? 0),
                (int) ($inv['comments'] ?? 0),
                count((array) ($inv['media'] ?? [])),
                (int) ($inv['categories'] ?? 0),
                (int) ($inv['tags'] ?? 0)
            )),
            __('Imported') => esc_html(sprintf(
                __('%1$d posts/pages/blocks, %2$d media files, %3$d comments, %4$d categories/tags, %5$d menus'),
                (int) ($report['imported_posts'] ?? 0),
                (int) ($report['media_imported'] ?? 0),
                (int) ($report['imported_comments'] ?? 0),
                (int) ($report['imported_taxonomies'] ?? 0),
                (int) ($report['imported_menus'] ?? 0)
            )),
            __('Links rewritten') => esc_html((string) count((array) ($report['url_rewrites'] ?? []))),
        ];
        echo '<table class="widefat striped" style="margin:1em 0"><tbody>';
        foreach ($rows as $label => $html) {
            echo '<tr><th scope="row" style="width:14em">'.esc_html($label).'</th><td>'.$html.'</td></tr>';
        }
        echo '</tbody></table>';
        $lists = [
            'media_missing' => __('Media files missing from the archive'),
            'unresolved_source_references' => __('Content still linking to the old site'),
            'unsupported_post_types' => __('Content types this platform cannot import (kept in your original file)'),
            'unsupported_taxonomies' => __('Taxonomies not imported'),
            'unsupported_blocks' => __('Blocks without a renderer here (shown as plain content)'),
            'unsupported_shortcodes' => __('Shortcodes without a renderer here (visible as raw [text])'),
            'presentation' => __('Presentation (theme, menus, CSS)'),
            'warnings' => __('Warnings'),
            'external_integrations' => __('Reconnect manually'),
        ];
        foreach ($lists as $key => $label) {
            $values = (array) ($report[$key] ?? []);
            if (!$values) {
                continue;
            }
            echo '<details'.(in_array($key, ['media_missing', 'unresolved_source_references', 'unsupported_shortcodes'], true) ? ' open' : '').'><summary><strong>'.esc_html($label).'</strong> ('.count($values).')</summary><ul class="ul-disc">';
            $shown = 0;
            foreach ($values as $k => $v) {
                if (++$shown > 50) {
                    echo '<li>'.esc_html__('…see the JSON report for the full list.').'</li>';
                    break;
                }
                $text = is_array($v) ? (is_string($k) || $key === 'unresolved_source_references' ? sprintf(__('Post %s: '), $k).implode(', ', array_map('strval', $v)) : implode(', ', array_map('strval', $v))) : (string) $v;
                echo '<li>'.esc_html($text).'</li>';
            }
            echo '</ul></details>';
        }
        if (!empty($report['source_independence'])) {
            echo '<p><strong>'.esc_html__('Independence from the old site:').'</strong> '.esc_html((string) $report['source_independence']).'</p>';
        }
    }

    public static function upload(): void
    {
        check_admin_referer('nbe_import');
        if (!current_user_can('manage_options') || Security::readonly()) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        if (!RateLimiter::hit('import', 5, 3600, 'user:'.get_current_user_id())) {
            self::back(['nbe_error' => __('Too many imports in the last hour. Try again later.')]);
        }
        $file = $_FILES['archive'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            self::back(['nbe_error' => __('Upload failed. Check the file and the size limit.')]);
        }
        try {
            Migration::intake((string) $file['tmp_name'], get_current_blog_id(), get_current_user_id());
        } catch (\Throwable $e) {
            self::back(['nbe_error' => $e->getMessage()]);
        }
        self::back(['nbe_ok' => 1]);
    }

    public static function authorizedJob(string $id): object
    {
        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Migration::table().' WHERE id = %s AND site_id = %d', $id, get_current_blog_id()));
        if (!$job || !current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        return $job;
    }

    public static function authorMap(): void
    {
        $id = sanitize_text_field(wp_unslash($_POST['job'] ?? ''));
        check_admin_referer('nbe_author_map_'.$id);
        $job = self::authorizedJob($id);
        if ($job->state !== 'awaiting_author_mapping' || Security::readonly()) {
            wp_die(esc_html__('This import is not waiting for an author map.'), '', ['response' => 409]);
        }
        $submitted = wp_unslash($_POST['authors'] ?? []);
        $invites = wp_unslash($_POST['invite'] ?? []);
        if (!is_array($submitted) || !is_array($invites)) {
            wp_die(esc_html__('Invalid author mapping.'), '', ['response' => 400]);
        }
        $report = json_decode($job->report, true) ?: [];
        $map = [];
        try {
            foreach ((array) ($report['authors'] ?? []) as $index => $source) {
                $choice = (string) ($submitted[$index] ?? '');
                $map[$source] = $choice === 'invite'
                    ? self::invite((string) $source, sanitize_email((string) ($invites[$index] ?? '')), (int) $job->site_id)
                    : (int) $choice;
            }
            Migration::saveAuthorMap($job, $map);
        } catch (\RuntimeException $e) {
            self::back(['nbe_error' => $e->getMessage()]);
        }
        self::back(['nbe_ok' => 1]);
    }

    /** Create (or reuse) an account for an imported author and add it to the site as Author. */
    public static function invite(string $source, string $email, int $site): int
    {
        if (!is_email($email)) {
            throw new \RuntimeException(sprintf(__('Enter a valid email to invite “%s”.'), $source));
        }
        if (!Policy::emailAllowed($email, 'unrestricted', [], Config::list('REGISTRATION_DENYLIST'))) {
            throw new \RuntimeException(__('That email domain is not accepted by the platform policy.'));
        }
        $existing = get_user_by('email', $email);
        if ($existing) {
            if (!is_user_member_of_blog($existing->ID, $site)) {
                add_user_to_blog($site, $existing->ID, 'author');
            }
            return (int) $existing->ID;
        }
        if (!RateLimiter::hit('invite', 30, 3600, 'site:'.$site)) {
            throw new \RuntimeException(__('Too many invitations in the last hour. Try again later.'));
        }
        $login = Policy::loginFromSource($source);
        $base = $login;
        for ($n = 2; username_exists($login); $n++) {
            $login = substr($base, 0, 45).$n;
        }
        $user = wpmu_create_user($login, wp_generate_password(32, true, true), $email);
        if (!$user) {
            throw new \RuntimeException(sprintf(__('Could not create an account for “%s”.'), $source));
        }
        add_user_to_blog($site, $user, 'author');
        wp_new_user_notification($user, null, 'user');
        EventLog::record('migration_author_invited', ['user' => $user, 'site' => $site]);
        return (int) $user;
    }

    public static function downloadReport(): void
    {
        check_admin_referer('nbe_report');
        $job = self::authorizedJob(sanitize_text_field(wp_unslash($_GET['job'] ?? '')));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="migration-report-'.substr($job->id, 0, 8).'.json"');
        echo wp_json_encode(json_decode($job->report, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function retry(): void
    {
        check_admin_referer('nbe_retry');
        $job = self::authorizedJob(sanitize_text_field(wp_unslash($_POST['job'] ?? '')));
        if (Security::readonly()) {
            wp_die(esc_html__('The platform is read-only.'), '', ['response' => 503]);
        }
        if ($job->state === 'awaiting_author_mapping') {
            self::back(['nbe_error' => __('Map every imported author before running the import.')]);
        }
        Migration::retry($job);
        self::back(['nbe_ok' => 1]);
    }
}
