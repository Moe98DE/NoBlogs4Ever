<?php

declare(strict_types=1);

namespace NBE;

/**
 * Full site archive: a portable ZIP a site administrator can download
 * without operator help, and re-import here or elsewhere.
 *
 *   publication.xml   standard WordPress WXR export (all content)
 *   uploads/…         every uploaded file of this site
 *   site.json         safe presentation/configuration (no credentials)
 *   manifest.json     format version, counts and SHA-256 of every file
 *   README.txt        what is and is not included
 *
 * Archives are built by the worker (large media libraries must not depend on
 * one HTTP request), stored privately outside the web root, downloadable only
 * by administrators of that site, and deleted after EXPORT_RETENTION_DAYS.
 */
final class Export
{
    public const FORMAT = 'noblogs4ever-site-archive/1';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix.'nbe_exports';
    }

    public static function root(): string
    {
        return Migration::root().'/exports';
    }

    public static function register(): void
    {
        add_action('admin_menu', function (): void {
            add_management_page(__('Full site archive'), __('Full site archive'), 'export', 'nbe-export', [self::class, 'page']);
        });
        add_action('admin_post_nbe_export_request', [self::class, 'request']);
        add_action('admin_post_nbe_export_download', [self::class, 'download']);
        add_action('export_filters', function (): void {
            echo '<p>'.wp_kses(sprintf(
                /* translators: %s: URL */
                __('Need your media files and settings too? Create a <a href="%s">full site archive</a>.'),
                esc_url(admin_url('tools.php?page=nbe-export'))
            ), ['a' => ['href' => []]]).'</p>';
        });
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE '.self::table().' (
 id char(36) NOT NULL,
 site_id bigint unsigned NOT NULL,
 user_id bigint unsigned NOT NULL,
 state varchar(16) NOT NULL,
 created datetime NOT NULL,
 updated datetime NOT NULL,
 size bigint unsigned NOT NULL DEFAULT 0,
 sha256 char(64) NOT NULL DEFAULT \'\',
 message text NOT NULL,
 PRIMARY KEY  (id),
 KEY site_state (site_id,state)
) '.$wpdb->get_charset_collate().';');
    }

    public static function pendingCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM '.self::table()." WHERE state IN ('queued','running')");
    }

    public static function page(): void
    {
        if (!current_user_can('export')) {
            wp_die(esc_html__('Not permitted'));
        }
        global $wpdb;
        echo '<div class="wrap"><h1>'.esc_html__('Full site archive').'</h1>';
        echo '<p>'.esc_html__('A ZIP file with all your content (standard WordPress WXR), every uploaded media file, your theme choice, Additional CSS, menus and a checksum manifest. You can import it into another site here, or use the WXR file with any WordPress installation.').'</p>';
        echo '<p>'.esc_html__('Not included: passwords, two-factor settings, connected-account tokens, encryption keys, comments\' IP addresses (never stored) and copies held by other servers through federation.').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_export_request');
        echo '<input type="hidden" name="action" value="nbe_export_request">';
        submit_button(__('Create a new archive'), 'primary', 'submit', false);
        echo '</form><h2>'.esc_html__('Your archives').'</h2><table class="widefat striped"><thead><tr><th scope="col">'.esc_html__('Requested (UTC)').'</th><th scope="col">'.esc_html__('Status').'</th><th scope="col">'.esc_html__('Size').'</th><th scope="col">'.esc_html__('Download').'</th></tr></thead><tbody>';
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' WHERE site_id = %d ORDER BY created DESC LIMIT 10', get_current_blog_id()));
        foreach ($rows as $row) {
            $link = $row->state === 'complete'
                ? '<a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=nbe_export_download&id='.$row->id), 'nbe_export_download')).'">'.esc_html__('Download ZIP').'</a><br><code style="font-size:11px">SHA-256 '.esc_html($row->sha256).'</code>'
                : esc_html($row->message);
            echo '<tr><td>'.esc_html($row->created).'</td><td>'.esc_html(self::label($row->state)).'</td><td>'.esc_html($row->size ? size_format((int) $row->size) : '—').'</td><td>'.$link.'</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="4">'.esc_html__('No archives yet.').'</td></tr>';
        }
        echo '</tbody></table><p>'.esc_html(sprintf(
            /* translators: %d: days */
            __('Archives are built in the background (usually within a minute) and deleted after %d days.'),
            Config::int('EXPORT_RETENTION_DAYS', 7, 1)
        )).'</p></div>';
    }

    private static function label(string $state): string
    {
        return ['queued' => __('Waiting'), 'running' => __('Building'), 'complete' => __('Ready'), 'failed' => __('Failed')][$state] ?? $state;
    }

    public static function request(): void
    {
        check_admin_referer('nbe_export_request');
        if (!current_user_can('export')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        if (!RateLimiter::hit('export', 3, 3600, 'site:'.get_current_blog_id())) {
            wp_die(esc_html__('Several archives were requested recently. Try again later.'), '', ['response' => 429]);
        }
        self::queue(get_current_blog_id(), get_current_user_id());
        wp_safe_redirect(admin_url('tools.php?page=nbe-export'));
        exit;
    }

    public static function queue(int $site, int $user): string
    {
        global $wpdb;
        $id = wp_generate_uuid4();
        $now = current_time('mysql', true);
        $wpdb->insert(self::table(), ['id' => $id, 'site_id' => $site, 'user_id' => $user, 'state' => 'queued', 'created' => $now, 'updated' => $now, 'message' => '']);
        EventLog::record('export_queued', ['site' => $site]);
        return $id;
    }

    public static function download(): void
    {
        check_admin_referer('nbe_export_download');
        global $wpdb;
        $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id = %s AND site_id = %d AND state = %s', $id, get_current_blog_id(), 'complete'));
        $file = $row ? self::file($row->id) : '';
        if (!$row || !current_user_can('export') || !is_file($file)) {
            wp_die(esc_html__('Archive not found.'), '', ['response' => 404]);
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Length: '.filesize($file));
        header('Content-Disposition: attachment; filename="'.sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST).'-'.substr($row->created, 0, 10)).'.zip"');
        readfile($file);
        EventLog::record('export_downloaded');
        exit;
    }

    private static function file(string $id): string
    {
        return self::root().'/'.$id.'.zip';
    }

    /** Build the oldest queued archive, if any. Called by the worker. */
    public static function runNext(): void
    {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM '.self::table()." WHERE state IN ('queued','running') ORDER BY created LIMIT 1");
        if (!$row || (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', 'nbe-export-'.$row->id)) !== 1) {
            return;
        }
        $wpdb->update(self::table(), ['state' => 'running', 'updated' => current_time('mysql', true)], ['id' => $row->id]);
        try {
            [$size, $hash] = self::build($row);
            $wpdb->update(self::table(), ['state' => 'complete', 'size' => $size, 'sha256' => $hash, 'message' => '', 'updated' => current_time('mysql', true)], ['id' => $row->id]);
            EventLog::record('export_complete', ['site' => (int) $row->site_id]);
        } catch (\Throwable $e) {
            @unlink(self::file($row->id));
            $wpdb->update(self::table(), ['state' => 'failed', 'message' => substr($e->getMessage(), 0, 500), 'updated' => current_time('mysql', true)], ['id' => $row->id]);
            EventLog::record('export_failed', ['site' => (int) $row->site_id]);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'nbe-export-'.$row->id));
        }
    }

    /** @return array{0: int, 1: string} size and SHA-256 of the finished archive */
    public static function build(object $row): array
    {
        require_once ABSPATH.'wp-admin/includes/export.php';
        if (!is_dir(self::root()) && !mkdir(self::root(), 0700, true)) {
            throw new \RuntimeException('Private export storage is unavailable.');
        }
        $previousUser = get_current_user_id();
        switch_to_blog((int) $row->site_id);
        wp_set_current_user((int) $row->user_id);
        $tmp = self::file($row->id).'.part';
        try {
            if (!user_can((int) $row->user_id, 'export')) {
                throw new \RuntimeException('The requesting user can no longer export this site.');
            }
            ob_start();
            // export_wp() sends download headers; they are meaningless here and only warn when output already started.
            $reporting = error_reporting(error_reporting() & ~E_WARNING);
            try {
                export_wp(['content' => 'all']);
            } finally {
                error_reporting($reporting);
                $xml = (string) ob_get_clean();
            }
            if (!str_contains($xml, '<rss')) {
                throw new \RuntimeException('WordPress export did not produce WXR.');
            }
            $limit = Config::int('EXPORT_MAX_MB', 4096, 1) * MB_IN_BYTES;
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create archive.');
            }
            $manifest = ['format' => self::FORMAT, 'generated_at' => gmdate('c'), 'site' => home_url('/'), 'files' => []];
            $add = function (string $name, string $contents) use ($zip, &$manifest): void {
                $zip->addFromString($name, $contents);
                $manifest['files'][$name] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
            };
            $add('publication.xml', $xml);
            $add('site.json', (string) wp_json_encode(self::siteSettings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $add('README.txt', self::readme());
            $total = strlen($xml);
            $uploads = wp_upload_dir(null, false);
            $base = realpath((string) $uploads['basedir']);
            if ($base && is_dir($base)) {
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    /** @var \SplFileInfo $file */
                    if (!$file->isFile() || $file->isLink()) {
                        continue;
                    }
                    $relative = substr($file->getPathname(), strlen($base) + 1);
                    // The main site's uploads root contains every other tenant's files.
                    if (is_main_site() && str_starts_with($relative, 'sites/')) {
                        continue;
                    }
                    $total += $file->getSize();
                    if ($total > $limit) {
                        throw new \RuntimeException('The site is larger than the archive limit. Ask the operator for an export.');
                    }
                    $zip->addFile($file->getPathname(), 'uploads/'.$relative);
                    $manifest['files']['uploads/'.$relative] = ['bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
                }
            }
            $manifest['counts'] = ['files' => count($manifest['files']), 'posts' => (int) wp_count_posts('post')->publish, 'pages' => (int) wp_count_posts('page')->publish];
            $zip->addFromString('manifest.json', (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new \RuntimeException('Cannot finish archive.');
            }
            chmod($tmp, 0600);
            rename($tmp, self::file($row->id));
            return [(int) filesize(self::file($row->id)), (string) hash_file('sha256', self::file($row->id))];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            restore_current_blog();
            wp_set_current_user($previousUser);
        }
    }

    /**
     * Allowlisted, non-secret settings that describe presentation.
     *
     * @return array<string, mixed>
     */
    public static function siteSettings(): array
    {
        $theme = wp_get_theme();
        $menus = [];
        foreach (wp_get_nav_menus() as $menu) {
            $menus[] = ['slug' => $menu->slug, 'name' => $menu->name];
        }
        $locations = [];
        foreach ((array) get_nav_menu_locations() as $location => $menuId) {
            $term = $menuId ? get_term((int) $menuId, 'nav_menu') : null;
            if ($term && !is_wp_error($term)) {
                $locations[$location] = $term->slug;
            }
        }
        return [
            'format' => self::FORMAT,
            'name' => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url' => home_url('/'),
            'language' => get_locale(),
            'timezone' => get_option('timezone_string') ?: get_option('gmt_offset'),
            'date_format' => get_option('date_format'),
            'time_format' => get_option('time_format'),
            'permalink_structure' => get_option('permalink_structure'),
            'posts_per_page' => (int) get_option('posts_per_page'),
            'show_on_front' => get_option('show_on_front'),
            'page_on_front' => (int) get_option('page_on_front'),
            'page_for_posts' => (int) get_option('page_for_posts'),
            'theme' => ['stylesheet' => $theme->get_stylesheet(), 'template' => $theme->get_template(), 'name' => $theme->get('Name'), 'version' => $theme->get('Version')],
            'custom_css' => wp_get_custom_css(),
            'menus' => $menus,
            'menu_locations' => $locations,
            'site_icon' => (int) get_option('site_icon'),
            'comments' => ['default_status' => get_option('default_comment_status'), 'moderation' => (bool) get_option('comment_moderation'), 'registration_required' => (bool) get_option('comment_registration')],
        ];
    }

    private static function readme(): string
    {
        return 'Full site archive ('.self::FORMAT.")\n\n"
            ."publication.xml  WordPress eXtended RSS (WXR): posts, pages, comments, categories, tags, menus, authors (names only), attachments.\n"
            ."uploads/         every uploaded file, in WordPress's year/month layout.\n"
            ."site.json        theme, Additional CSS, menu locations, reading and comment settings. No secrets.\n"
            ."manifest.json    SHA-256 checksum of every file in this archive.\n\n"
            ."To import into any WordPress site: Tools → Import → WordPress, upload publication.xml, then copy uploads/.\n"
            ."To import into a NoBlogs4Ever site: Tools → Import publication, upload this ZIP unchanged.\n\n"
            ."Not included: passwords, two-factor settings, sessions, OAuth/API tokens, encryption keys, comment IP addresses,\n"
            ."plugin or theme code, and anything held by other servers (for example federated copies).\n";
    }

    public static function purge(): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - Config::int('EXPORT_RETENTION_DAYS', 7, 1) * DAY_IN_SECONDS);
        foreach ($wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::table().' WHERE updated < %s AND state IN (\'complete\',\'failed\') LIMIT 50', $cutoff)) as $id) {
            if (preg_match('/\A[a-f0-9-]{36}\z/', $id)) {
                @unlink(self::file($id));
                $wpdb->delete(self::table(), ['id' => $id]);
            }
        }
    }
}
