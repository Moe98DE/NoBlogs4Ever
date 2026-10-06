<?php

declare(strict_types=1);

namespace NBE;

/**
 * Reader and author privacy defaults.
 *
 * - Comment IP addresses and user agents are never stored.
 * - Pages make no third-party requests by default (no Gravatar, no emoji CDN).
 * - Members-only sites are enforced for pages, feeds, REST and media.
 */
final class Privacy
{
    public static function register(): void
    {
        add_filter('pre_comment_user_ip', '__return_empty_string');
        add_filter('pre_comment_user_agent', '__return_empty_string');
        add_filter('preprocess_comment', function (array $data): array {
            $data['comment_author_IP'] = '';
            $data['comment_agent'] = '';
            return $data;
        });
        // Defence in depth for code paths that call wp_insert_comment() directly.
        add_action('wp_insert_comment', function ($id, $comment): void {
            if ($comment->comment_author_IP !== '' || $comment->comment_agent !== '') {
                global $wpdb;
                $wpdb->update($wpdb->comments, ['comment_author_IP' => '', 'comment_agent' => ''], ['comment_ID' => (int) $id]);
                clean_comment_cache((int) $id);
            }
        }, 1, 2);
        // WordPress's flood check looks up recent comments by IP address. With IPs
        // never stored, every anonymous reader would share one (empty) address,
        // so it is replaced by the private in-memory rate limiter below.
        remove_action('check_comment_flood', 'check_comment_flood_db', 10);
        add_action('pre_comment_on_post', function (): void {
            if (!RateLimiter::hit('comment-burst', 2, 15) || !RateLimiter::hit('comment', 10, 600)) {
                wp_die(esc_html__('Too many comments from this connection. Try again later.'), '', ['response' => 429]);
            }
        });

        if (!Config::bool('ALLOW_GRAVATAR')) {
            add_filter('pre_get_avatar_data', [self::class, 'localAvatar'], 10, 2);
        }
        // The emoji fallback script downloads images from a WordPress.org CDN.
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        add_filter('emoji_svg_url', '__return_false');
        add_filter('wp_resource_hints', fn (array $urls, string $type) => $type === 'dns-prefetch' ? [] : $urls, 10, 2);

        add_action('template_redirect', [self::class, 'requireSiteAccess'], 0);
        add_filter('rest_pre_dispatch', function ($result) {
            return self::canAccessSite() ? $result : new \WP_Error('private_site', __('This site is restricted to its members.'), ['status' => 403]);
        });
        add_filter('robots_txt', function (string $output): string {
            return get_option('nbe_private') ? "User-agent: *\nDisallow: /\n" : $output;
        });
        add_filter('wp_sitemaps_enabled', fn ($enabled) => $enabled && !get_option('nbe_private'));
    }

    public static function canAccessSite(): bool
    {
        if (!get_option('nbe_private')) {
            return true;
        }
        $user = get_current_user_id();
        return $user && (is_user_member_of_blog($user, get_current_blog_id()) || is_super_admin($user));
    }

    public static function requireSiteAccess(): void
    {
        if (self::canAccessSite()) {
            return;
        }
        nocache_headers();
        if (!is_user_logged_in()) {
            auth_redirect();
        }
        wp_die(esc_html__('This site is restricted to its members.'), esc_html__('Private site'), ['response' => 403]);
    }

    /**
     * Replace third-party avatars with a local, generic placeholder.
     *
     * @param array<string, mixed> $args
     * @param mixed $idOrEmail
     * @return array<string, mixed>
     */
    public static function localAvatar(array $args, $idOrEmail): array
    {
        $size = (int) ($args['size'] ?? 96);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#c3c4c7"/><circle cx="32" cy="25" r="12" fill="#f0f0f1"/><path d="M10 58c3-13 12-19 22-19s19 6 22 19z" fill="#f0f0f1"/></svg>';
        $args['url'] = 'data:image/svg+xml;base64,'.base64_encode($svg);
        $args['found_avatar'] = true;
        $args['width'] = $size;
        $args['height'] = $size;
        return $args;
    }
}
