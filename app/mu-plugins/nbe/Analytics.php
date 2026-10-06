<?php

declare(strict_types=1);

namespace NBE;

/**
 * Optional, server-side, aggregate page counts.
 *
 * No script is added to pages and nothing is stored about an individual
 * visit: each request increments one counter keyed by (site, day, post,
 * coarse browser family, coarse device class). There are no IP addresses,
 * user agents, cookies, referrers, query strings or visitor identifiers, so
 * "unique visitors" are deliberately not measured. Rows expire after
 * ANALYTICS_RETENTION_DAYS. Site administrators see only their own site.
 */
final class Analytics
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix.'nbe_analytics';
    }

    public static function register(): void
    {
        add_action('template_redirect', [self::class, 'count'], 99);
        add_action('rest_api_init', function (): void {
            register_rest_route('nbe/v1', '/analytics', [
                'methods' => 'GET',
                'permission_callback' => fn () => current_user_can('manage_options'),
                'args' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 366, 'default' => 14]],
                'callback' => fn (\WP_REST_Request $request) => self::summary(get_current_blog_id(), (int) $request->get_param('days')),
            ]);
        });
        add_action('admin_menu', function (): void {
            add_dashboard_page(__('Site analytics'), __('Site analytics'), 'manage_options', 'nbe-analytics', [self::class, 'page']);
        });
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta('CREATE TABLE '.self::table().' (
 site_id bigint unsigned NOT NULL,
 day date NOT NULL,
 post_id bigint unsigned NOT NULL,
 browser varchar(12) NOT NULL,
 device varchar(10) NOT NULL,
 views bigint unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY  (site_id,day,post_id,browser,device),
 KEY site_day (site_id,day)
) '.$wpdb->get_charset_collate().';');
        // Carry forward counts from the 0.x table layout, then retire it.
        $legacy = $wpdb->base_prefix.'nbe_views';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy)) === $legacy) {
            $wpdb->query('INSERT IGNORE INTO '.self::table()." (site_id, day, post_id, browser, device, views) SELECT site_id, day, post_id, IF(bot, 'automated', 'other'), IF(bot, 'automated', 'unknown'), views FROM $legacy");
            $wpdb->query("DROP TABLE $legacy");
        }
    }

    public static function enabled(): bool
    {
        return (bool) get_option('nbe_analytics') && (int) get_option('blog_public') === 1 && !get_option('nbe_private');
    }

    public static function count(): void
    {
        if (!self::enabled() || is_user_logged_in() || is_404() || is_preview() || is_feed() || is_robots() || is_trackback() || is_customize_preview()
            || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $post = 0;
        if (is_singular()) {
            if (post_password_required() || get_post_status() !== 'publish') {
                return;
            }
            $post = (int) get_queried_object_id();
        }
        $class = Policy::userAgentClass((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO '.self::table().' (site_id, day, post_id, browser, device, views) VALUES (%d, %s, %d, %s, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1',
            get_current_blog_id(),
            gmdate('Y-m-d'),
            $post,
            $class['browser'],
            $class['device']
        ));
    }

    public static function purge(): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d', time() - Config::int('ANALYTICS_RETENTION_DAYS', 14, 1, 366) * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM '.self::table().' WHERE day < %s', $cutoff));
    }

    /**
     * Aggregates for one site.
     *
     * @return array{days: int, total: int, automated: int, daily: list<array{day: string, human: int, automated: int}>, posts: list<array{post_id: int, title: string, url: string, views: int}>, browsers: array<string, int>, devices: array<string, int>}
     */
    public static function summary(int $site, int $days = 14): array
    {
        global $wpdb;
        $days = max(1, min($days, Config::int('ANALYTICS_RETENTION_DAYS', 14, 1, 366)));
        $since = gmdate('Y-m-d', time() - ($days - 1) * DAY_IN_SECONDS);
        $table = self::table();
        $daily = $wpdb->get_results($wpdb->prepare("SELECT day, SUM(IF(browser = 'automated', 0, views)) human, SUM(IF(browser = 'automated', views, 0)) automated FROM $table WHERE site_id = %d AND day >= %s GROUP BY day ORDER BY day DESC", $site, $since), ARRAY_A) ?: [];
        $posts = $wpdb->get_results($wpdb->prepare("SELECT post_id, SUM(views) views FROM $table WHERE site_id = %d AND day >= %s AND browser <> 'automated' GROUP BY post_id ORDER BY views DESC LIMIT 20", $site, $since), ARRAY_A) ?: [];
        $browsers = $wpdb->get_results($wpdb->prepare("SELECT browser k, SUM(views) v FROM $table WHERE site_id = %d AND day >= %s AND browser <> 'automated' GROUP BY browser ORDER BY v DESC", $site, $since), ARRAY_A) ?: [];
        $devices = $wpdb->get_results($wpdb->prepare("SELECT device k, SUM(views) v FROM $table WHERE site_id = %d AND day >= %s AND device <> 'automated' GROUP BY device ORDER BY v DESC", $site, $since), ARRAY_A) ?: [];
        $switched = get_current_blog_id() !== $site;
        if ($switched) {
            switch_to_blog($site);
        }
        $postRows = array_map(function (array $row): array {
            $id = (int) $row['post_id'];
            return ['post_id' => $id, 'title' => $id ? get_the_title($id) : __('Home, archives and other pages'), 'url' => $id ? (string) get_permalink($id) : home_url('/'), 'views' => (int) $row['views']];
        }, $posts);
        if ($switched) {
            restore_current_blog();
        }
        $human = array_sum(array_map(fn ($r) => (int) $r['human'], $daily));
        $automated = array_sum(array_map(fn ($r) => (int) $r['automated'], $daily));
        return [
            'days' => $days,
            'total' => $human,
            'automated' => $automated,
            'daily' => array_map(fn ($r) => ['day' => $r['day'], 'human' => (int) $r['human'], 'automated' => (int) $r['automated']], $daily),
            'posts' => $postRows,
            'browsers' => array_map('intval', array_column($browsers, 'v', 'k')),
            'devices' => array_map('intval', array_column($devices, 'v', 'k')),
        ];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'));
        }
        $days = isset($_GET['days']) ? (int) $_GET['days'] : 14;
        $data = self::summary(get_current_blog_id(), $days);
        echo '<div class="wrap"><h1>'.esc_html__('Site analytics').'</h1>';
        if (!self::enabled()) {
            echo '<div class="notice notice-info inline"><p>'.wp_kses(sprintf(
                /* translators: %s: settings URL */
                __('Analytics are off for this site. Turn them on under <a href="%s">Your platform → Site privacy</a>. Members-only sites and sites that discourage search engines are never counted.'),
                esc_url(admin_url('admin.php?page=nbe#privacy'))
            ), ['a' => ['href' => []]]).'</p></div>';
        }
        echo '<p>'.esc_html(sprintf(
            /* translators: %d: number of days */
            __('Aggregate page views for the last %d days. No cookies, scripts, IP addresses or visitor identifiers are used, so unique visitors are not measured. Views by signed-in members are not counted.'),
            $data['days']
        )).'</p>';
        echo '<p><strong>'.esc_html(number_format_i18n($data['total'])).'</strong> '.esc_html__('page views by people').' · '.esc_html(number_format_i18n($data['automated'])).' '.esc_html__('automated requests (approximate)').'</p>';
        $max = max(1, ...array_map(fn ($r) => $r['human'], $data['daily'] ?: [['human' => 1]]));
        echo '<h2>'.esc_html__('Daily views').'</h2><table class="widefat striped"><caption class="screen-reader-text">'.esc_html__('Daily views').'</caption><thead><tr><th scope="col">'.esc_html__('Day (UTC)').'</th><th scope="col">'.esc_html__('People').'</th><th scope="col">'.esc_html__('Automated').'</th><th scope="col" aria-hidden="true"></th></tr></thead><tbody>';
        foreach ($data['daily'] as $row) {
            $width = (int) round(100 * $row['human'] / $max);
            echo '<tr><td>'.esc_html($row['day']).'</td><td>'.esc_html(number_format_i18n($row['human'])).'</td><td>'.esc_html(number_format_i18n($row['automated'])).'</td><td aria-hidden="true"><span style="display:inline-block;height:.8em;width:'.$width.'%;background:#2271b1"></span></td></tr>';
        }
        if (!$data['daily']) {
            echo '<tr><td colspan="4">'.esc_html__('No views recorded yet.').'</td></tr>';
        }
        echo '</tbody></table><h2>'.esc_html__('Most viewed').'</h2><table class="widefat striped"><thead><tr><th scope="col">'.esc_html__('Page').'</th><th scope="col">'.esc_html__('Views').'</th></tr></thead><tbody>';
        foreach ($data['posts'] as $row) {
            echo '<tr><td><a href="'.esc_url($row['url']).'">'.esc_html($row['title']).'</a></td><td>'.esc_html(number_format_i18n($row['views'])).'</td></tr>';
        }
        echo '</tbody></table><div style="display:flex;gap:2rem;flex-wrap:wrap">';
        foreach (['browsers' => __('Browser family'), 'devices' => __('Device class')] as $key => $label) {
            echo '<div><h2>'.esc_html($label).'</h2><table class="widefat striped"><tbody>';
            foreach ($data[$key] as $name => $views) {
                echo '<tr><th scope="row">'.esc_html(ucfirst((string) $name)).'</th><td>'.esc_html(number_format_i18n($views)).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></div>';
    }
}
