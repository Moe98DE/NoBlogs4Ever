<?php

declare(strict_types=1);

namespace NBE;

/**
 * Tenant lifecycle: self-service creation, privacy-preserving defaults and
 * the deletion workflow.
 */
final class Sites
{
    public static function register(): void
    {
        remove_action('wp_initialize_site', 'wpmu_log_new_registrations', 100);
        add_filter('wpmu_validate_blog_signup', [self::class, 'validateSignup']);
        add_action('wp_initialize_site', [self::class, 'defaults'], 20, 2);
        add_action('admin_post_nbe_site', [self::class, 'create']);
        add_action('wp_delete_site', fn (\WP_Site $site) => EventLog::record('site_deleted', ['site' => (int) $site->blog_id]));
        add_action('make_spam_blog', fn ($id) => EventLog::record('site_marked_spam', ['site' => (int) $id]));
        add_action('archive_blog', fn ($id) => EventLog::record('site_archived', ['site' => (int) $id]));
        add_filter('delete_site_email_content', [self::class, 'deletionEmail']);
        add_action('load-ms-delete-site.php', [self::class, 'deletionNotice']);
    }

    /** Extra reserved names from configuration (comma-separated RESERVED_SLUGS). */
    public static function reserved(): array
    {
        return Config::list('RESERVED_SLUGS');
    }

    /** Number of sites the user administers (membership as a subscriber does not count). */
    public static function administeredCount(int $userId): int
    {
        $count = 0;
        foreach (get_blogs_of_user($userId) as $site) {
            if (user_can_for_site($userId, (int) $site->userblog_id, 'manage_options') && (int) $site->userblog_id !== get_main_site_id()) {
                $count++;
            }
        }
        return $count;
    }

    public static function canCreateMore(int $userId): bool
    {
        return is_super_admin($userId) || self::administeredCount($userId) < Config::int('MAX_SITES_PER_USER', 3, 0);
    }

    /**
     * @param array{blogname: string, errors: \WP_Error} $result
     * @return array{blogname: string, errors: \WP_Error}
     */
    public static function validateSignup(array $result): array
    {
        if (!Policy::slug((string) $result['blogname'], self::reserved())) {
            $result['errors']->add('blogname', __('Choose a site name of 4–63 lowercase letters, numbers or hyphens, starting with a letter. Some names are reserved.'));
        }
        if (is_user_logged_in() && !self::canCreateMore(get_current_user_id())) {
            $result['errors']->add('blogname', __('You have reached the number of sites you can create.'));
        }
        if (($_POST['stage'] ?? '') !== '' && !RateLimiter::hit('site-create', 5, 3600)) {
            $result['errors']->add('blogname', __('Too many sites created from this connection. Try again later.'));
        }
        return $result;
    }

    /**
     * Privacy-preserving defaults for every new tenant.
     *
     * @param array<string, mixed> $args
     */
    public static function defaults(\WP_Site $site, array $args = []): void
    {
        switch_to_blog((int) $site->blog_id);
        // WordPress gives every new subdomain site an http:// address. Use the
        // platform scheme (https in production) and, in development, the port.
        $scheme = getenv('PLATFORM_SCHEME') ?: (Config::production() ? 'https' : 'http');
        $port = (string) getenv('PLATFORM_PORT');
        $suffix = !Config::production() && $port !== '' && !in_array($port, ['80', '443'], true) ? ':'.$port : '';
        foreach (['home', 'siteurl'] as $key) {
            update_option($key, $scheme.'://'.$site->domain.$suffix);
        }
        update_option('classic-editor-replace', 'block');
        update_option('classic-editor-allow-users', 'allow');
        update_option('nbe_discoverable', 0);
        update_option('nbe_analytics', 0);
        update_option('nbe_private', 0);
        update_option('comment_moderation', 1);
        update_option('comment_registration', 0);
        update_option('comment_max_links', 2);
        update_option('show_avatars', 0);
        update_option('blog_upload_space', Config::int('SITE_QUOTA_MB', 1024, 1));
        update_option('permalink_structure', '/%year%/%monthnum%/%day%/%postname%/');
        $theme = (string) Config::get('DEFAULT_THEME', 'twentytwentyfive');
        if (wp_get_theme($theme)->exists()) {
            switch_theme($theme);
        }
        restore_current_blog();
        EventLog::record('site_created', ['site' => (int) $site->blog_id]);
    }

    /** Handle the "Create a site" form on the "Your platform" page. */
    public static function create(): void
    {
        check_admin_referer('nbe_site');
        $user = get_current_user_id();
        if (!$user || Security::readonly()) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        $slug = strtolower(sanitize_text_field(wp_unslash($_POST['slug'] ?? '')));
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $back = admin_url('admin.php?page=nbe');
        $fail = function (string $message) use ($back): void {
            wp_safe_redirect(add_query_arg('nbe_error', rawurlencode($message), $back));
            exit;
        };
        if (!Policy::slug($slug, self::reserved())) {
            $fail(__('That site name is invalid or reserved. Use 4–63 lowercase letters, numbers or hyphens, starting with a letter.'));
        }
        if ($title === '') {
            $fail(__('Give your site a title.'));
        }
        if (!self::canCreateMore($user)) {
            $fail(__('You have reached the number of sites you can create.'));
        }
        if (!RateLimiter::hit('site-create', 5, 3600, 'user:'.$user)) {
            $fail(__('You created several sites recently. Try again in an hour.'));
        }
        $domain = $slug.'.'.get_network()->domain;
        if (domain_exists($domain, '/', get_current_network_id())) {
            $fail(__('That site name is already taken.'));
        }
        $id = wpmu_create_blog($domain, '/', $title, $user, ['public' => 1], get_current_network_id());
        if (is_wp_error($id)) {
            $fail($id->get_error_message());
        }
        switch_to_blog((int) $id);
        update_option('blogdescription', sanitize_text_field(wp_unslash($_POST['tagline'] ?? '')));
        $language = sanitize_text_field(wp_unslash($_POST['language'] ?? ''));
        if ($language !== '' && in_array($language, get_available_languages(), true)) {
            update_option('WPLANG', $language);
        }
        restore_current_blog();
        // The new site is another host of this network, which wp_safe_redirect() would refuse.
        wp_redirect(get_admin_url((int) $id));
        exit;
    }

    public static function deletionEmail(string $content): string
    {
        $extra = "\n\nBefore you confirm:\n"
            ."- Export your content first (Tools → Export, or Tools → Full site archive).\n"
            ."- Deleting removes the site from the live platform immediately, but encrypted platform backups keep a copy until they expire under the operator's retention policy.\n"
            ."- If the site federated content (ActivityPub), remote servers may keep their copies; local deletion cannot remove them.\n";
        return $content.$extra;
    }

    public static function deletionNotice(): void
    {
        add_action('admin_notices', function (): void {
            echo '<div class="notice notice-warning"><p><strong>'.esc_html__('Export first.').'</strong> '
                .sprintf(
                    /* translators: 1: export URL, 2: full archive URL */
                    wp_kses(__('Download a <a href="%1$s">WXR export</a> or a <a href="%2$s">full site archive</a> before deleting. Deletion is permanent on the live platform; backups expire according to the operator\'s retention policy, and federated copies on other servers cannot be recalled.'), ['a' => ['href' => []]]),
                    esc_url(admin_url('export.php')),
                    esc_url(admin_url('tools.php?page=nbe-export'))
                ).'</p></div>';
        });
    }
}
