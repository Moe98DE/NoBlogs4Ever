<?php

declare(strict_types=1);

namespace NBE;

/**
 * Tenant privilege boundaries, authentication hardening, MFA enforcement,
 * emergency read-only mode and HTTP response headers.
 */
final class Security
{
    /** Capabilities that only network operators (super admins) may ever hold. */
    public const OPERATOR_ONLY_CAPS = [
        'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
        'install_themes', 'upload_themes', 'update_themes', 'delete_themes', 'edit_themes', 'edit_files',
        'update_core', 'install_languages', 'update_languages',
        'manage_network', 'manage_network_options', 'manage_network_plugins', 'manage_network_themes',
        'manage_network_users', 'manage_sites', 'create_sites', 'delete_sites', 'upgrade_network', 'setup_network',
        'unfiltered_html', 'unfiltered_upload',
    ];

    /**
     * Capabilities that change content, people or configuration; denied to everyone in read-only mode
     * so the admin UI hides write actions. (Every non-GET request is refused by guard() anyway; viewing
     * one's own profile stays possible because WordPress sends users without edit rights there.)
     */
    public const WRITE_CAPS = [
        'edit_post', 'edit_page', 'delete_post', 'delete_page', 'publish_post', 'publish_posts', 'publish_pages',
        'edit_posts', 'edit_pages', 'edit_others_posts', 'edit_others_pages', 'edit_published_posts', 'edit_published_pages',
        'edit_private_posts', 'edit_private_pages', 'delete_posts', 'delete_pages', 'delete_others_posts', 'delete_others_pages',
        'delete_published_posts', 'delete_published_pages', 'delete_private_posts', 'delete_private_pages',
        'upload_files', 'edit_comment', 'moderate_comments', 'manage_categories', 'edit_terms', 'delete_terms', 'assign_terms',
        'edit_theme_options', 'switch_themes', 'customize', 'edit_css', 'manage_options', 'import',
        'create_users', 'delete_users', 'delete_user', 'remove_users', 'remove_user', 'promote_users', 'promote_user',
        'activate_plugins', 'activate_plugin', 'deactivate_plugin', 'delete_site',
    ];

    /** wp-login.php actions that stay usable in read-only mode so operators can sign in to remediate. */
    private const READONLY_LOGIN_ACTIONS = ['', 'login', 'logout', 'validate_2fa', 'backup_2fa', 'revalidate_2fa'];

    public static function register(): void
    {
        add_filter('map_meta_cap', [self::class, 'caps'], 99, 4);
        add_action('init', [self::class, 'guard'], 0);
        add_action('send_headers', [self::class, 'headers']);
        add_action('login_init', [self::class, 'loginHeaders']);
        if (PHP_SAPI !== 'cli') {
            header_register_callback([self::class, 'cookieHeaders']);
        }

        // Authentication hardening.
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('xmlrpc_methods', '__return_empty_array');
        add_filter('wp_is_application_passwords_available', '__return_false');
        add_filter('authenticate', [self::class, 'loginRate'], 5, 3);
        add_filter('login_errors', fn () => __('Sign-in failed. Check your credentials or try again later.'));
        add_filter('allow_password_reset', fn ($allow) => RateLimiter::hit('reset', 8, 900) ? $allow : false);
        add_filter('lostpassword_errors', [self::class, 'lostPasswordEnumeration'], 99, 2);
        add_filter('auth_cookie_expiration', fn () => Config::int('SESSION_HOURS', 24, 1, 24 * 90) * HOUR_IN_SECONDS);
        add_filter('two_factor_providers', function (array $providers): array {
            // Email codes are a weak second factor for operators; prefer TOTP, security keys and backup codes.
            unset($providers['Two_Factor_Email']);
            return $providers;
        });

        // Enumeration resistance.
        add_filter('rest_endpoints', [self::class, 'restEndpoints']);
        add_action('template_redirect', [self::class, 'authorEnumeration'], 1);
        add_filter('oembed_response_data', function (array $data): array {
            unset($data['author_name'], $data['author_url']);
            return $data;
        });

        // Audit trail for security-sensitive actions.
        add_action('set_user_role', fn ($id, $role) => EventLog::record('role_changed', ['user' => (int) $id, 'role' => (string) $role]), 10, 2);
        add_action('add_user_to_blog', fn ($id, $role, $site) => EventLog::record('member_added', ['user' => (int) $id, 'role' => (string) $role, 'site' => (int) $site]), 10, 3);
        add_action('remove_user_from_blog', fn ($id, $site) => EventLog::record('member_removed', ['user' => (int) $id, 'site' => (int) $site]), 10, 2);
        add_action('granted_super_admin', fn ($id) => EventLog::record('operator_granted', ['user' => (int) $id]));
        add_action('revoked_super_admin', fn ($id) => EventLog::record('operator_revoked', ['user' => (int) $id]));
        add_action('wpmu_delete_user', fn ($id) => EventLog::record('user_deleted', ['user' => (int) $id]));
        add_action('wp_login', fn ($login, $user) => EventLog::record('login', ['user' => (int) $user->ID]), 10, 2);
        add_action('wp_login_failed', fn () => EventLog::record('login_failed'));
        add_action('after_password_reset', fn ($user) => EventLog::record('password_reset', ['user' => (int) $user->ID]));
        add_action('activated_plugin', fn ($plugin, $network) => EventLog::record($network ? 'network_plugin_activated' : 'plugin_activated', ['code' => (string) $plugin]), 10, 2);
        add_action('deactivated_plugin', fn ($plugin, $network) => EventLog::record($network ? 'network_plugin_deactivated' : 'plugin_deactivated', ['code' => (string) $plugin]), 10, 2);
        add_action('switch_theme', fn ($name, $theme) => EventLog::record('theme_switched', ['code' => (string) $theme->get_stylesheet()]), 10, 2);
    }

    public static function readonly(): bool
    {
        return !(defined('WP_INSTALLING') && WP_INSTALLING) && (bool) get_site_option('nbe_readonly', false);
    }

    /**
     * @param list<string> $caps
     * @param array<int, mixed> $args
     * @return list<string>
     */
    public static function caps(array $caps, string $cap, int $userId, array $args): array
    {
        if (in_array($cap, self::OPERATOR_ONLY_CAPS, true) && !is_super_admin($userId)) {
            return ['do_not_allow'];
        }
        if (self::readonly() && in_array($cap, self::WRITE_CAPS, true)) {
            return ['do_not_allow'];
        }
        return $caps;
    }

    public static function guard(): void
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === 'wp-cron.php' && PHP_SAPI !== 'cli') {
            wp_die(esc_html__('Scheduled jobs are run by the platform worker.'), '', ['response' => 403]);
        }
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (self::readonly() && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !self::readonlyException($script)) {
            status_header(503);
            header('Retry-After: 600');
            wp_die(
                esc_html__('This platform is temporarily read-only for maintenance. Published sites stay online; changes are paused.'),
                esc_html__('Read-only maintenance'),
                ['response' => 503]
            );
        }
        self::enforceMfa($script, $method);
    }

    private static function readonlyException(string $script): bool
    {
        return self::readonlyAllows($script, (string) ($_REQUEST['action'] ?? ''), is_super_admin());
    }

    /** Which write requests stay possible during read-only mode: signing in, and an operator ending maintenance. */
    public static function readonlyAllows(string $script, string $action, bool $operator): bool
    {
        if ($script === 'wp-login.php') {
            return in_array($action, self::READONLY_LOGIN_ACTIONS, true);
        }
        return $script === 'admin-post.php' && $operator && $action === 'nbe_settings';
    }

    /** Whether a user has a working TOTP or security-key second factor configured. */
    public static function hasStrongMfa(int $userId): bool
    {
        if (!class_exists('Two_Factor_Core')) {
            return false;
        }
        $providers = \Two_Factor_Core::get_available_providers_for_user($userId);
        foreach (array_keys($providers) as $name) {
            if ($name === 'Two_Factor_Totp' || str_contains(strtolower((string) $name), 'webauthn')) {
                return true;
            }
        }
        return false;
    }

    public static function mfaRequired(int $userId): bool
    {
        if (!$userId) {
            return false;
        }
        if (is_super_admin($userId)) {
            return Config::production() || Config::bool('REQUIRE_MFA_FOR_OPERATORS_IN_DEVELOPMENT');
        }
        return Config::bool('REQUIRE_MFA_FOR_SITE_ADMINS') && user_can($userId, 'manage_options');
    }

    private static function enforceMfa(string $script, string $method): void
    {
        $user = get_current_user_id();
        if (!$user || !self::mfaRequired($user) || self::hasStrongMfa($user)) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $enrollmentAjax = $script === 'admin-ajax.php' && in_array($_REQUEST['action'] ?? '', ['webauthn_preregister', 'webauthn_register', 'two_factor_backup_codes_generate'], true);
        $enrollmentRest = str_contains($uri, '/two-factor/') || str_contains($uri, 'rest_route=%2Ftwo-factor') || str_contains($uri, 'rest_route=/two-factor');
        if ($enrollmentAjax || $enrollmentRest || in_array($script, ['profile.php', 'wp-login.php', 'admin-post.php'], true)) {
            return;
        }
        if (is_admin() && !wp_doing_ajax()) {
            wp_safe_redirect(admin_url('profile.php?nbe_enroll=1#two-factor-options'));
            exit;
        }
        if (str_contains($uri, '/wp-json/') && $method !== 'GET') {
            wp_die(esc_html__('Enroll an authenticator app or security key before making changes.'), '', ['response' => 403]);
        }
    }

    /**
     * @param \WP_User|\WP_Error|null $user
     * @return \WP_User|\WP_Error|null
     */
    public static function loginRate($user, string $username, string $password)
    {
        if ($username !== '' && !RateLimiter::hit('login', 20, 900)) {
            return new \WP_Error('nbe_rate', __('Sign-in temporarily unavailable. Try again later.'));
        }
        return $user;
    }

    /**
     * Always answer an unknown account the same way as a known one.
     *
     * @param \WP_Error $errors
     * @param \WP_User|false $user
     */
    public static function lostPasswordEnumeration($errors, $user)
    {
        if (!$user && !$errors->has_errors() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            wp_safe_redirect(wp_login_url().'?checkemail=confirm');
            exit;
        }
        return $errors;
    }

    /**
     * Hide user listing routes from visitors who cannot already list users or edit content.
     *
     * @param array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public static function restEndpoints(array $endpoints): array
    {
        if (current_user_can('list_users') || current_user_can('edit_posts')) {
            return $endpoints;
        }
        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with($route, '/wp/v2/users')) {
                unset($endpoints[$route]);
            }
        }
        return $endpoints;
    }

    public static function authorEnumeration(): void
    {
        if (isset($_GET['author']) && !is_user_logged_in()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    public static function headers(): void
    {
        if (is_user_logged_in() || is_admin()) {
            nocache_headers();
            header('Cache-Control: private, no-store, max-age=0');
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    public static function loginHeaders(): void
    {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
    }

    /** Add SameSite=Lax to any cookie that does not declare a SameSite policy. */
    public static function cookieHeaders(): void
    {
        $cookies = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookies[] = preg_match('/;\s*SameSite=/i', $header) ? $header : $header.'; SameSite=Lax';
            }
        }
        if ($cookies) {
            header_remove('Set-Cookie');
            foreach ($cookies as $cookie) {
                header($cookie, false);
            }
        }
    }
}
