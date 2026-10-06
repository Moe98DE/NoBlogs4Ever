<?php

declare(strict_types=1);

namespace NBE;

/**
 * Network-level discovery of public sites that opted in.
 *
 * A site is listed only when its administrator enabled "List this site in
 * the network directory" and it is public, not members-only, not archived,
 * spam or deleted. Only published, non-password-protected posts appear.
 *
 * The worker rebuilds a small denormalised index (bounded: 500 sites × 5
 * posts) every few minutes, so rendering the directory never fans out across
 * tenant tables during a page view.
 *
 * Shortcodes for the main site: [nbe_directory], [nbe_recent count="20"
 * topic="slug"], [nbe_topics]. JSON: GET /wp-json/nbe/v1/discover.
 */
final class Discovery
{
    public const OPTION = 'nbe_discovery_index';
    private const MAX_SITES = 500;
    private const POSTS_PER_SITE = 5;
    private const REFRESH_SECONDS = 600;

    public static function register(): void
    {
        add_shortcode('nbe_directory', [self::class, 'directory']);
        add_shortcode('nbe_recent', [self::class, 'recent']);
        add_shortcode('nbe_topics', [self::class, 'topics']);
        add_action('rest_api_init', function (): void {
            register_rest_route('nbe/v1', '/discover', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'args' => ['topic' => ['type' => 'string', 'required' => false]],
                'callback' => function (\WP_REST_Request $request) {
                    $topic = sanitize_title((string) $request->get_param('topic'));
                    return ['generated_at' => self::index()['generated_at'], 'sites' => self::sites(), 'recent' => self::posts(50, $topic)];
                },
            ]);
        });
        // Rebuild soon after a listed site publishes something.
        add_action('transition_post_status', function ($new, $old) {
            if (($new === 'publish' || $old === 'publish') && get_option('nbe_discoverable')) {
                update_site_option('nbe_discovery_stale', 1);
            }
        }, 10, 2);
    }

    public static function setListed(int $site, bool $listed): void
    {
        update_site_meta($site, 'nbe_discoverable', $listed ? 1 : 0);
        update_site_option('nbe_discovery_stale', 1);
    }

    /** @return array{generated_at: string, sites: list<array<string, mixed>>} */
    public static function index(): array
    {
        $index = get_site_option(self::OPTION);
        return is_array($index) && isset($index['sites']) ? $index : ['generated_at' => '', 'sites' => []];
    }

    public static function refreshIfDue(): void
    {
        $index = self::index();
        $age = $index['generated_at'] ? time() - (int) strtotime($index['generated_at']) : PHP_INT_MAX;
        if ($age > self::REFRESH_SECONDS || ($age > 30 && get_site_option('nbe_discovery_stale'))) {
            self::rebuild();
        }
    }

    public static function rebuild(): void
    {
        $sites = get_sites([
            'number' => self::MAX_SITES,
            'public' => 1, 'archived' => 0, 'spam' => 0, 'deleted' => 0, 'mature' => 0,
            'meta_query' => [['key' => 'nbe_discoverable', 'value' => '1']],
            'orderby' => 'last_updated', 'order' => 'DESC',
        ]);
        $entries = [];
        foreach ($sites as $site) {
            switch_to_blog((int) $site->blog_id);
            try {
                if (!get_option('nbe_discoverable') || get_option('nbe_private') || (int) get_option('blog_public') !== 1) {
                    continue;
                }
                $posts = [];
                foreach (get_posts(['numberposts' => self::POSTS_PER_SITE, 'post_type' => 'post', 'post_status' => 'publish', 'has_password' => false, 'suppress_filters' => false]) as $post) {
                    $posts[] = [
                        'title' => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5),
                        'url' => get_permalink($post),
                        'date_gmt' => $post->post_date_gmt,
                        'excerpt' => wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_excerpt ?: $post->post_content)), 30),
                        'topics' => array_map(fn ($t) => ['slug' => $t->slug, 'name' => $t->name], array_filter(get_the_category($post->ID), fn ($t) => $t->slug !== 'uncategorized')),
                    ];
                }
                $entries[] = [
                    'id' => (int) $site->blog_id,
                    'name' => html_entity_decode(get_bloginfo('name'), ENT_QUOTES | ENT_HTML5),
                    'description' => html_entity_decode(get_bloginfo('description'), ENT_QUOTES | ENT_HTML5),
                    'url' => home_url('/'),
                    'posts' => $posts,
                ];
            } finally {
                restore_current_blog();
            }
        }
        update_site_option(self::OPTION, ['generated_at' => gmdate('c'), 'sites' => $entries]);
        update_site_option('nbe_discovery_stale', 0);
    }

    /** @return list<array{name: string, description: string, url: string}> */
    public static function sites(): array
    {
        return array_map(fn ($s) => ['name' => $s['name'], 'description' => $s['description'], 'url' => $s['url']], self::index()['sites']);
    }

    /** @return list<array<string, mixed>> */
    public static function posts(int $count = 20, string $topic = ''): array
    {
        $all = [];
        foreach (self::index()['sites'] as $site) {
            foreach ($site['posts'] as $post) {
                if ($topic !== '' && !in_array($topic, array_column($post['topics'], 'slug'), true)) {
                    continue;
                }
                $all[] = $post + ['site' => $site['name'], 'site_url' => $site['url']];
            }
        }
        usort($all, fn ($a, $b) => strcmp($b['date_gmt'], $a['date_gmt']));
        return array_slice($all, 0, max(1, min(100, $count)));
    }

    /** @param array<string, string>|string $atts */
    public static function directory($atts = []): string
    {
        $sites = self::sites();
        if (!$sites) {
            return '<p>'.esc_html__('No sites are listed in the directory yet.').'</p>';
        }
        $html = '<ul class="nbe-directory">';
        foreach ($sites as $site) {
            $html .= '<li><a href="'.esc_url($site['url']).'">'.esc_html($site['name']).'</a>'.($site['description'] ? ' — '.esc_html($site['description']) : '').'</li>';
        }
        return $html.'</ul>';
    }

    /** @param array<string, string>|string $atts */
    public static function recent($atts = []): string
    {
        $atts = shortcode_atts(['count' => '20', 'topic' => ''], is_array($atts) ? $atts : []);
        $topic = sanitize_title($atts['topic'] ?: (string) ($_GET['topic'] ?? ''));
        $posts = self::posts((int) $atts['count'], $topic);
        if (!$posts) {
            return '<p>'.esc_html__('Nothing has been published here yet.').'</p>';
        }
        $html = $topic !== '' ? '<p>'.esc_html(sprintf(__('Showing posts about “%s”.'), $topic)).' <a href="'.esc_url(remove_query_arg('topic')).'">'.esc_html__('Show all').'</a></p>' : '';
        $html .= '<ul class="nbe-recent">';
        foreach ($posts as $post) {
            $html .= '<li><article><h3><a href="'.esc_url($post['url']).'">'.esc_html($post['title']).'</a></h3><p><a href="'.esc_url($post['site_url']).'">'.esc_html($post['site']).'</a> · <time datetime="'.esc_attr(mysql2date('c', $post['date_gmt'], false)).'">'.esc_html(mysql2date(get_option('date_format'), $post['date_gmt'])).'</time></p>'.($post['excerpt'] ? '<p>'.esc_html($post['excerpt']).'</p>' : '').'</article></li>';
        }
        return $html.'</ul>';
    }

    /** @param array<string, string>|string $atts */
    public static function topics($atts = []): string
    {
        $counts = [];
        $names = [];
        foreach (self::index()['sites'] as $site) {
            foreach ($site['posts'] as $post) {
                foreach ($post['topics'] as $topic) {
                    $counts[$topic['slug']] = ($counts[$topic['slug']] ?? 0) + 1;
                    $names[$topic['slug']] = $topic['name'];
                }
            }
        }
        if (!$counts) {
            return '';
        }
        arsort($counts);
        $html = '<ul class="nbe-topics">';
        foreach (array_slice($counts, 0, 40, true) as $slug => $count) {
            $html .= '<li><a href="'.esc_url(add_query_arg('topic', $slug)).'">'.esc_html($names[$slug]).'</a> ('.(int) $count.')</li>';
        }
        return $html.'</ul>';
    }
}
