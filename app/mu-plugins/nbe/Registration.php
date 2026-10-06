<?php

declare(strict_types=1);

namespace NBE;

/**
 * Registration policy: unrestricted, allowlist, invitation or approval.
 *
 * - Self-registration (wp-signup.php) is checked against the policy, the
 *   domain allow/deny lists and a per-address rate limit.
 * - People who can already create users (site administrators inviting
 *   collaborators, network operators) are never blocked by the self-service
 *   policy; only the deny list applies to them.
 * - In "approval" mode, signups are held: the activation email is not sent
 *   and the activation key is replaced with an unguessable value until a
 *   network operator approves the request from Network Admin → Platform policy.
 */
final class Registration
{
    public static function register(): void
    {
        add_filter('wpmu_validate_user_signup', [self::class, 'validateUser']);
        add_filter('pre_site_option_registration', [self::class, 'networkRegistrationMode']);
        add_filter('wpmu_signup_user_notification', [self::class, 'holdNotification'], 10, 1);
        add_filter('wpmu_signup_blog_notification', [self::class, 'holdNotification'], 10, 1);
        add_action('after_signup_user', [self::class, 'afterSignup'], 10, 4);
        add_action('after_signup_site', fn ($domain, $path, $title, $login, $email, $key, $meta) => self::afterSignup($login, $email, $key, $meta), 10, 7);
        add_action('signup_finished', [self::class, 'signupFinishedNotice']);
        add_action('admin_post_nbe_signup_decision', [self::class, 'decide']);
    }

    /** Whether the current request is an administrator adding someone, not a self-registration. */
    private static function adminInitiated(): bool
    {
        return is_user_logged_in() && (current_user_can('create_users') || current_user_can('promote_users') || is_super_admin());
    }

    /**
     * @param array{user_name: string, user_email: string, errors: \WP_Error} $result
     * @return array{user_name: string, user_email: string, errors: \WP_Error}
     */
    public static function validateUser(array $result): array
    {
        $email = (string) $result['user_email'];
        $deny = Config::list('REGISTRATION_DENYLIST');
        if (self::adminInitiated()) {
            if (!Policy::emailAllowed($email, 'unrestricted', [], $deny)) {
                $result['errors']->add('nbe_policy', __('This email domain is not accepted by the platform policy.'));
            }
            return $result;
        }
        $mode = Config::registrationPolicy();
        if (!Policy::selfRegistrationOpen($mode)) {
            $result['errors']->add('nbe_policy', __('Registration is by invitation only. Ask a site administrator to invite you.'));
            return $result;
        }
        if (!Policy::emailAllowed($email, $mode, Config::list('REGISTRATION_ALLOWLIST'), $deny)) {
            $result['errors']->add('user_email', __('This email address is not eligible for registration here. Contact support if you think this is a mistake.'));
            return $result;
        }
        // wp-signup.php validates once per step; only count the final submission.
        if (($_POST['stage'] ?? '') !== '' && !RateLimiter::hit('registration', 5, 3600)) {
            $result['errors']->add('nbe_rate', __('Too many registrations from this connection. Try again later.'));
        }
        return $result;
    }

    /**
     * Invitation mode turns off self-service user signups but keeps
     * site creation available for people who are already signed in.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function networkRegistrationMode($value)
    {
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return $value;
        }
        return Policy::selfRegistrationOpen(Config::registrationPolicy()) ? 'all' : 'blog';
    }

    private static function holding(): bool
    {
        return Config::registrationPolicy() === 'approval' && !self::adminInitiated() && empty($GLOBALS['nbe_releasing_signup']);
    }

    /**
     * @param mixed $send
     * @return mixed
     */
    public static function holdNotification($send)
    {
        return self::holding() ? false : $send;
    }

    /** @param array<string, mixed> $meta */
    public static function afterSignup(string $login, string $email, string $key, array $meta = []): void
    {
        if (!self::holding()) {
            return;
        }
        global $wpdb;
        // The original key may already be known to the browser; replace it with one nobody has.
        $wpdb->update($wpdb->signups, [
            'activation_key' => bin2hex(random_bytes(20)),
            'meta' => serialize(array_merge($meta, ['nbe_pending_approval' => time()])),
        ], ['activation_key' => $key]);
        EventLog::record('signup_pending');
        $operator = (string) Config::get('NBE_ADMIN_EMAIL', get_site_option('admin_email'));
        if (is_email($operator)) {
            wp_mail(
                $operator,
                sprintf('[%s] Registration awaiting approval', Config::brand()),
                "A new registration is waiting for review.\n\nReview it in Network Admin → Platform policy:\n".network_admin_url('admin.php?page=nbe-network#signups')
            );
        }
    }

    public static function signupFinishedNotice(): void
    {
        if (Config::registrationPolicy() === 'approval' && !self::adminInitiated()) {
            echo '<div class="notice notice-info"><p><strong>'.esc_html__('Your request was received.').'</strong> '.esc_html__('An operator reviews new accounts before they can be activated. You will receive an activation email once it is approved; the email you may see mentioned above is sent only after approval.').'</p></div>';
        }
    }

    /**
     * Pending signups, newest first (bounded).
     *
     * @return list<object>
     */
    public static function pending(int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT signup_id, domain, path, title, user_login, user_email, registered, meta FROM $wpdb->signups WHERE active = 0 ORDER BY registered DESC LIMIT %d", $limit));
        return array_values(array_filter($rows ?: [], function ($row) {
            $meta = maybe_unserialize($row->meta);
            return is_array($meta) && !empty($meta['nbe_pending_approval']);
        }));
    }

    public static function decide(): void
    {
        check_admin_referer('nbe_signup_decision');
        if (!current_user_can('manage_network_users')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        $id = (int) ($_POST['signup'] ?? 0);
        $ok = ($_POST['decision'] ?? '') === 'approve' ? self::approve($id) : self::reject($id);
        if (!$ok) {
            wp_die(esc_html__('That registration is no longer pending.'), '', ['response' => 404]);
        }
        wp_safe_redirect(network_admin_url('admin.php?page=nbe-network&nbe_done=1#signups'));
        exit;
    }

    /** @return array<string, mixed>|null meta of a pending signup */
    private static function pendingRow(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $wpdb->signups WHERE signup_id = %d AND active = 0", $id));
        $meta = $row ? maybe_unserialize($row->meta) : null;
        return $row && is_array($meta) && !empty($meta['nbe_pending_approval']) ? ['row' => $row, 'meta' => $meta] : null;
    }

    /** Release a held signup: a fresh activation key is emailed to the applicant. */
    public static function approve(int $id): bool
    {
        global $wpdb;
        $pending = self::pendingRow($id);
        if (!$pending) {
            return false;
        }
        ['row' => $row, 'meta' => $meta] = $pending;
        unset($meta['nbe_pending_approval']);
        $key = substr(bin2hex(random_bytes(16)), 0, 16);
        $wpdb->update($wpdb->signups, ['activation_key' => $key, 'meta' => serialize($meta)], ['signup_id' => $id]);
        $GLOBALS['nbe_releasing_signup'] = true;
        try {
            if ($row->domain) {
                wpmu_signup_blog_notification($row->domain, $row->path, $row->title, $row->user_login, $row->user_email, $key, $meta);
            } else {
                wpmu_signup_user_notification($row->user_login, $row->user_email, $key, $meta);
            }
        } finally {
            unset($GLOBALS['nbe_releasing_signup']);
        }
        EventLog::record('signup_approved');
        return true;
    }

    public static function reject(int $id): bool
    {
        global $wpdb;
        if (!self::pendingRow($id)) {
            return false;
        }
        $wpdb->delete($wpdb->signups, ['signup_id' => $id]);
        EventLog::record('signup_rejected');
        return true;
    }
}
