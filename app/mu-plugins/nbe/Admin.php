<?php

declare(strict_types=1);

namespace NBE;

/**
 * Platform pages inside the normal WordPress dashboard:
 *
 * - "Your platform" (every signed-in user): your sites, create a site,
 *   site privacy/discovery/analytics (site admins), account security.
 * - Network Admin → "Platform policy" (operators): registration policy,
 *   pending approvals, read-only mode, recent security events.
 */
final class Admin
{
    public static function register(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page(__('Your platform'), __('Your platform'), 'read', 'nbe', [self::class, 'page'], 'dashicons-admin-multisite', 3);
        });
        add_action('network_admin_menu', function (): void {
            add_menu_page(__('Platform policy'), __('Platform policy'), 'manage_network_options', 'nbe-network', [self::class, 'networkPage'], 'dashicons-shield');
        });
        add_action('admin_post_nbe_settings', [self::class, 'save']);
        add_action('admin_post_nbe_revoke', [self::class, 'revokeSessions']);
        add_action('admin_notices', [self::class, 'notices']);
        add_action('network_admin_notices', [self::class, 'notices']);
        add_action('admin_bar_menu', function (\WP_Admin_Bar $bar): void {
            if (Security::readonly() && is_user_logged_in()) {
                $bar->add_node(['id' => 'nbe-readonly', 'title' => esc_html__('Read-only maintenance'), 'meta' => ['class' => 'nbe-readonly']]);
            }
        }, 100);
    }

    public static function notices(): void
    {
        if (Security::readonly()) {
            echo '<div class="notice notice-warning"><p><strong>'.esc_html__('The platform is in read-only maintenance mode.').'</strong> '.esc_html__('Published sites stay online, but publishing, uploads, comments, settings changes, imports and scheduled posts are paused until an operator ends maintenance.').'</p></div>';
        }
        if (isset($_GET['nbe_enroll'])) {
            echo '<div class="notice notice-error"><p><strong>'.esc_html__('Two-factor authentication required.').'</strong> '.esc_html__('Set up an authenticator app (TOTP) or a security key below, and generate backup recovery codes, before you continue.').'</p></div>';
        }
        if (!empty($_GET['nbe_error'])) {
            echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['nbe_error']))).'</p></div>';
        }
    }

    public static function page(): void
    {
        $user = get_current_user_id();
        echo '<div class="wrap"><h1>'.esc_html(Config::brand()).'</h1>';
        echo '<p>'.esc_html__('One account, many sites: you can hold a different role on each site. Invite collaborators under Users — everyone should have their own account rather than sharing a password.').'</p>';

        echo '<h2 id="sites">'.esc_html__('Your sites').'</h2><table class="widefat striped"><thead><tr><th scope="col">'.esc_html__('Site').'</th><th scope="col">'.esc_html__('Your role').'</th><th scope="col">'.esc_html__('Links').'</th></tr></thead><tbody>';
        foreach (get_blogs_of_user($user) as $site) {
            $id = (int) $site->userblog_id;
            switch_to_blog($id);
            $member = new \WP_User($user);
            $roles = array_map(fn ($r) => translate_user_role(wp_roles()->roles[$r]['name'] ?? $r), $member->roles);
            restore_current_blog();
            echo '<tr><td><strong>'.esc_html($site->blogname).'</strong><br><code>'.esc_html($site->domain).'</code></td><td>'.esc_html(implode(', ', $roles) ?: '—').'</td><td><a href="'.esc_url(get_home_url($id)).'">'.esc_html__('Visit').'</a> · <a href="'.esc_url(get_admin_url($id)).'">'.esc_html__('Dashboard').'</a></td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2 id="create">'.esc_html__('Create a site').'</h2>';
        if (!Sites::canCreateMore($user)) {
            echo '<p>'.esc_html(sprintf(__('You administer the maximum of %d sites. Ask an operator if you need more.'), Config::int('MAX_SITES_PER_USER', 3))).'</p>';
        } elseif (Security::readonly()) {
            echo '<p>'.esc_html__('Site creation is paused during maintenance.').'</p>';
        } else {
            $domain = get_network()->domain;
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('nbe_site');
            echo '<input type="hidden" name="action" value="nbe_site"><table class="form-table" role="presentation"><tbody>';
            echo '<tr><th scope="row"><label for="nbe-slug">'.esc_html__('Address').'</label></th><td><input id="nbe-slug" required name="slug" pattern="[a-z][a-z0-9\-]{2,61}[a-z0-9]" maxlength="63" autocomplete="off" aria-describedby="nbe-slug-help"> <code>.'.esc_html($domain).'</code><p class="description" id="nbe-slug-help">'.esc_html__('4–63 lowercase letters, numbers or hyphens; starts with a letter. Some names are reserved.').'</p></td></tr>';
            echo '<tr><th scope="row"><label for="nbe-title">'.esc_html__('Title').'</label></th><td><input id="nbe-title" class="regular-text" required name="title" maxlength="200"></td></tr>';
            echo '<tr><th scope="row"><label for="nbe-tagline">'.esc_html__('Tagline').'</label></th><td><input id="nbe-tagline" class="regular-text" name="tagline" maxlength="200"> <span class="description">'.esc_html__('Optional').'</span></td></tr>';
            echo '<tr><th scope="row"><label for="nbe-language">'.esc_html__('Language').'</label></th><td><select id="nbe-language" name="language"><option value="">English (United States)</option>';
            require_once ABSPATH.'wp-admin/includes/translation-install.php';
            $names = wp_get_available_translations();
            foreach (get_available_languages() as $language) {
                echo '<option value="'.esc_attr($language).'">'.esc_html($names[$language]['native_name'] ?? $language).'</option>';
            }
            echo '</select></td></tr></tbody></table>';
            submit_button(__('Create site'));
            echo '</form>';
        }

        if (current_user_can('manage_options')) {
            self::sitePrivacyForm();
        }

        echo '<h2 id="security">'.esc_html__('Account security').'</h2><p>'.esc_html__('Protect your account with an authenticator app or a security key, and keep your backup codes somewhere safe.').' <a href="'.esc_url(admin_url('profile.php#two-factor-options')).'">'.esc_html__('Set up two-factor authentication').'</a></p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_revoke');
        echo '<input type="hidden" name="action" value="nbe_revoke">';
        submit_button(__('Sign out everywhere'), 'secondary', 'submit', false);
        echo ' <span class="description">'.esc_html__('Ends every session of your account on every device, including this one.').'</span></form></div>';
    }

    private static function sitePrivacyForm(): void
    {
        echo '<h2 id="privacy">'.esc_html__('Site privacy').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_settings');
        echo '<input type="hidden" name="action" value="nbe_settings"><fieldset><legend class="screen-reader-text">'.esc_html__('Site privacy').'</legend>';
        $options = [
            'discoverable' => [__('List this site in the network directory'), __('Your site name, tagline and latest public posts appear on the platform\'s discovery pages.')],
            'analytics' => [__('Count page views'), __('Aggregate daily counts only — no cookies, scripts or visitor identifiers. See Dashboard → Site analytics.')],
            'private' => [__('Members only'), __('Only signed-in members of this site can read it, its feeds and its media. It is then never listed or counted.')],
        ];
        foreach ($options as $key => [$label, $help]) {
            echo '<p><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked((int) get_option('nbe_'.$key), 1, false).'> '.esc_html($label).'</label><br><span class="description">'.esc_html($help).'</span></p>';
        }
        echo '</fieldset><p class="description">'.esc_html__('Federation (ActivityPub), if your operator enables it, is opt-in per site. Remember that copies sent to other servers can be kept by them after you delete content here.').'</p>';
        submit_button(__('Save privacy settings'));
        echo '</form>';
    }

    public static function save(): void
    {
        check_admin_referer('nbe_settings');
        if (isset($_POST['network'])) {
            self::saveNetwork();
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        foreach (['discoverable', 'analytics', 'private'] as $key) {
            update_option('nbe_'.$key, (int) isset($_POST[$key]));
        }
        Discovery::setListed(get_current_blog_id(), isset($_POST['discoverable']) && !isset($_POST['private']));
        EventLog::record('site_privacy_changed');
        wp_safe_redirect(admin_url('admin.php?page=nbe#privacy'));
        exit;
    }

    private static function saveNetwork(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        $readonly = isset($_POST['readonly']);
        if (Security::readonly() && $readonly) {
            // In read-only mode only the read-only switch itself is honoured.
            wp_safe_redirect(network_admin_url('admin.php?page=nbe-network'));
            exit;
        }
        $mode = sanitize_key(wp_unslash($_POST['policy'] ?? 'invitation'));
        if (!in_array($mode, Policy::REGISTRATION_MODES, true)) {
            wp_die(esc_html__('Invalid policy'));
        }
        if (!Security::readonly()) {
            update_site_option('nbe_registration_policy', $mode);
            update_site_option('nbe_registration_allowlist', implode(',', Policy::list(sanitize_text_field(wp_unslash($_POST['allowlist'] ?? '')))));
            update_site_option('nbe_registration_denylist', implode(',', Policy::list(sanitize_text_field(wp_unslash($_POST['denylist'] ?? '')))));
            update_site_option('nbe_max_sites_per_user', max(0, (int) ($_POST['max_sites'] ?? 3)));
            update_site_option('nbe_require_mfa_for_site_admins', isset($_POST['mfa_site_admins']) ? 'yes' : 'no');
        }
        if ($readonly !== Security::readonly()) {
            update_site_option('nbe_readonly', $readonly);
            EventLog::record($readonly ? 'readonly_enabled' : 'readonly_disabled');
        }
        EventLog::record('network_policy_changed', ['code' => $mode]);
        wp_safe_redirect(network_admin_url('admin.php?page=nbe-network&updated=1'));
        exit;
    }

    public static function networkPage(): void
    {
        if (!current_user_can('manage_network_options')) {
            wp_die(esc_html__('Not permitted'));
        }
        echo '<div class="wrap"><h1>'.esc_html__('Platform policy').'</h1>';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>'.esc_html__('Policy saved.').'</p></div>';
        }
        $current = Config::registrationPolicy();
        $help = [
            'invitation' => __('Only site administrators and operators can add people. Signed-in users can still create sites.'),
            'approval' => __('Anyone can request an account; an operator approves each request below before the activation email is sent.'),
            'allowlist' => __('Self-registration only for the email domains listed below.'),
            'unrestricted' => __('Anyone can register. Combine with the deny list and monitor for abuse.'),
        ];
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_settings');
        echo '<input type="hidden" name="action" value="nbe_settings"><input type="hidden" name="network" value="1">';
        echo '<h2>'.esc_html__('Registration').'</h2><fieldset><legend class="screen-reader-text">'.esc_html__('Registration policy').'</legend>';
        foreach (Policy::REGISTRATION_MODES as $mode) {
            echo '<p><label><input type="radio" name="policy" value="'.esc_attr($mode).'" '.checked($current, $mode, false).'> <strong>'.esc_html(ucfirst($mode)).'</strong></label> — <span class="description">'.esc_html($help[$mode]).'</span></p>';
        }
        echo '</fieldset><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="nbe-allow">'.esc_html__('Allowed email domains').'</label></th><td><input id="nbe-allow" class="large-text" name="allowlist" value="'.esc_attr(implode(', ', Config::list('REGISTRATION_ALLOWLIST'))).'" aria-describedby="nbe-allow-help"><p class="description" id="nbe-allow-help">'.esc_html__('Comma-separated exact domains, used by the allowlist policy.').'</p></td></tr>';
        echo '<tr><th scope="row"><label for="nbe-deny">'.esc_html__('Denied email domains').'</label></th><td><input id="nbe-deny" class="large-text" name="denylist" value="'.esc_attr(implode(', ', Config::list('REGISTRATION_DENYLIST'))).'"><p class="description">'.esc_html__('Always refused, including for invitations.').'</p></td></tr>';
        echo '<tr><th scope="row"><label for="nbe-max">'.esc_html__('Sites per person').'</label></th><td><input id="nbe-max" type="number" min="0" max="1000" name="max_sites" value="'.esc_attr((string) Config::int('MAX_SITES_PER_USER', 3)).'"><p class="description">'.esc_html__('How many sites one account may administer. Operators are exempt.').'</p></td></tr>';
        echo '<tr><th scope="row">'.esc_html__('Two-factor').'</th><td><label><input type="checkbox" name="mfa_site_admins" '.checked(Config::bool('REQUIRE_MFA_FOR_SITE_ADMINS'), true, false).'> '.esc_html__('Require two-factor authentication for site administrators too').'</label><p class="description">'.esc_html__('Network operators always need it in production.').'</p></td></tr>';
        echo '</tbody></table><h2>'.esc_html__('Emergency read-only mode').'</h2><p><label><input type="checkbox" name="readonly" '.checked(Security::readonly(), true, false).'> '.esc_html__('Pause all writes on every site').'</label></p><p class="description">'.esc_html__('For incidents and maintenance. Published content stays readable; publishing, uploads, comments, registrations, imports, scheduled posts and settings changes stop. Operators can still sign in and turn this off here. This is a coordination tool, not forensic isolation.').'</p>';
        submit_button(__('Save platform policy'));
        echo '</form>';

        self::pendingSignups();
        self::auditLog();
        echo '</div>';
    }

    private static function pendingSignups(): void
    {
        $pending = Registration::pending();
        echo '<h2 id="signups">'.esc_html__('Registrations awaiting approval').'</h2>';
        if (!$pending) {
            echo '<p>'.esc_html(Config::registrationPolicy() === 'approval' ? __('No pending requests.') : __('Approval is used only by the “Approval” registration policy.')).'</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th scope="col">'.esc_html__('Requested (UTC)').'</th><th scope="col">'.esc_html__('Username').'</th><th scope="col">'.esc_html__('Email').'</th><th scope="col">'.esc_html__('Requested site').'</th><th scope="col">'.esc_html__('Decision').'</th></tr></thead><tbody>';
        foreach ($pending as $row) {
            echo '<tr><td>'.esc_html($row->registered).'</td><td>'.esc_html($row->user_login).'</td><td>'.esc_html($row->user_email).'</td><td>'.esc_html($row->domain ?: '—').'</td><td>';
            foreach (['approve' => __('Approve'), 'reject' => __('Reject')] as $decision => $label) {
                echo '<form style="display:inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('nbe_signup_decision');
                echo '<input type="hidden" name="action" value="nbe_signup_decision"><input type="hidden" name="signup" value="'.(int) $row->signup_id.'"><input type="hidden" name="decision" value="'.esc_attr($decision).'">';
                submit_button($label, $decision === 'approve' ? 'primary small' : 'delete small', 'submit', false, ['aria-label' => $label.' '.$row->user_login]);
                echo '</form> ';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function auditLog(): void
    {
        $events = EventLog::recent(100);
        echo '<h2 id="events">'.esc_html__('Recent security events').'</h2><p class="description">'.esc_html(sprintf(
            __('Allowlisted events only (no IP addresses, URLs or content). Kept for %d days.'),
            Config::int('LOG_RETENTION_DAYS', 7, 1)
        )).'</p><table class="widefat striped"><thead><tr><th scope="col">'.esc_html__('Time (UTC)').'</th><th scope="col">'.esc_html__('Event').'</th><th scope="col">'.esc_html__('Site').'</th><th scope="col">'.esc_html__('Actor').'</th><th scope="col">'.esc_html__('Details').'</th></tr></thead><tbody>';
        foreach ($events as $event) {
            $details = array_diff_key($event, array_flip(['time', 'event', 'site', 'actor']));
            echo '<tr><td>'.esc_html((string) $event['time']).'</td><td>'.esc_html((string) $event['event']).'</td><td>'.esc_html((string) ($event['site'] ?? '')).'</td><td>'.esc_html((string) ($event['actor'] ?? '')).'</td><td><code>'.esc_html($details ? (string) wp_json_encode($details) : '').'</code></td></tr>';
        }
        if (!$events) {
            echo '<tr><td colspan="5">'.esc_html__('No events recorded yet.').'</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function revokeSessions(): void
    {
        check_admin_referer('nbe_revoke');
        $user = get_current_user_id();
        \WP_Session_Tokens::get_instance($user)->destroy_all();
        EventLog::record('sessions_revoked', ['user' => $user]);
        wp_logout();
        wp_safe_redirect(wp_login_url());
        exit;
    }
}
