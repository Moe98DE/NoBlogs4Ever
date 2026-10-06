<?php

declare(strict_types=1);

namespace NBE;

/**
 * Curated plugin catalog seen by site administrators, and guard rails for
 * the optional integrations.
 *
 * Plugins are installed only by the operator, in the image (see
 * dependencies.lock.json). Site administrators may activate the plugins
 * listed here on their own site:
 *
 * - Classic Editor: always offered.
 * - Polylang (multilingual) and ActivityPub (federation): offered only when
 *   ENABLE_EXPERIMENTAL_INTEGRATIONS=yes. They are pinned and checksummed but
 *   not yet covered by the release qualification.
 */
final class Integrations
{
    public const TENANT_PLUGINS = ['classic-editor/classic-editor.php'];
    public const EXPERIMENTAL_PLUGINS = ['polylang/polylang.php', 'activitypub/activitypub.php'];

    public static function register(): void
    {
        add_filter('all_plugins', [self::class, 'catalog']);
        add_filter('activitypub_is_blog_public', fn ($public) => $public && !get_option('nbe_private') && !Security::readonly());
        add_filter('activitypub_is_post_disabled', fn ($disabled) => $disabled || get_option('nbe_private') || Security::readonly());
        add_action('load-plugins.php', function (): void {
            if (!is_network_admin()) {
                add_action('admin_notices', [self::class, 'pluginsNotice']);
            }
        });
    }

    /** @return list<string> */
    public static function tenantPlugins(): array
    {
        return Config::bool('ENABLE_EXPERIMENTAL_INTEGRATIONS')
            ? array_merge(self::TENANT_PLUGINS, self::EXPERIMENTAL_PLUGINS)
            : self::TENANT_PLUGINS;
    }

    /**
     * @param array<string, mixed> $plugins
     * @return array<string, mixed>
     */
    public static function catalog(array $plugins): array
    {
        if (is_super_admin()) {
            return $plugins;
        }
        return array_intersect_key($plugins, array_flip(self::tenantPlugins()));
    }

    public static function pluginsNotice(): void
    {
        echo '<div class="notice notice-info"><p>'.esc_html__('Plugins on this platform are curated and maintained by the operators. You can turn the listed ones on or off for your site; installing other plugins is not possible.').'</p>';
        if (in_array('activitypub/activitypub.php', self::tenantPlugins(), true)) {
            echo '<p>'.esc_html__('About ActivityPub (federation): posts you publish are copied to the servers of your followers. Deleting a post here asks those servers to delete it too, but they may keep their copies. Your federated identity is tied to this site\'s address.').'</p>';
        }
        echo '</div>';
    }
}
