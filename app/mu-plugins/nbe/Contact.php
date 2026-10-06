<?php

declare(strict_types=1);

namespace NBE;

/**
 * Browser-encrypted contact form ([nbe_contact]).
 *
 * The visitor's browser encrypts the message with a fresh AES-256-GCM key,
 * wraps that key with the recipient's RSA-OAEP (SHA-256) public key, and
 * posts only the ciphertext envelope. The server validates the envelope
 * shape and forwards it by email; it never sees or logs plaintext.
 *
 * What the platform can still observe: that a message was sent to this site,
 * when, its approximate size, the transient connection metadata, and the SMTP
 * envelope. A compromised server could serve modified JavaScript or a
 * substituted key, which is why the fingerprint is published for out-of-band
 * verification. See docs/features/encrypted-contact.md.
 */
final class Contact
{
    public const ENVELOPE_VERSION = 1;

    public static function boot(): void
    {
        add_shortcode('nbe_contact', [self::class, 'form']);
        add_action('admin_menu', fn () => add_options_page(__('Encrypted contact'), __('Encrypted contact'), 'manage_options', 'nbe-contact', [self::class, 'settings']));
        add_action('admin_post_nbe_contact_key', [self::class, 'save']);
        add_action('rest_api_init', fn () => register_rest_route('nbe/v1', '/contact', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'receive'],
        ]));
    }

    /**
     * Validate a recipient public key and compute its fingerprint.
     *
     * @return array{pem: string, fingerprint: string}
     */
    public static function key(string $pem): array
    {
        if (str_contains($pem, 'PRIVATE KEY') || strlen($pem) > 8192) {
            throw new \RuntimeException(__('Paste only the PUBLIC key. Never upload a private key to the platform.'));
        }
        $key = openssl_pkey_get_public($pem);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 3072) {
            throw new \RuntimeException(__('A valid RSA public key (SPKI PEM) of at least 3072 bits is required.'));
        }
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s/', '', $details['key']), true);
        return ['pem' => $details['key'], 'fingerprint' => hash('sha256', (string) $der)];
    }

    public static function settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'));
        }
        $fingerprint = (string) get_option('nbe_contact_fingerprint', '');
        echo '<div class="wrap"><h1>'.esc_html__('Encrypted contact').'</h1>';
        if (!empty($_GET['nbe_error'])) {
            echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['nbe_error']))).'</p></div>';
        }
        echo '<p>'.esc_html__('Messages are encrypted in the visitor’s browser with your public key and arrive by email as an encrypted envelope. Only the matching private key — which stays on your own device — can read them.').'</p>';
        echo '<p>'.esc_html__('The platform still sees that a message was sent, when, roughly how large it is, and the email delivery metadata. Someone in control of the server could replace the form’s code or key, so share the fingerprint below through a channel you trust.').'</p>';
        echo '<p>'.wp_kses(sprintf(
            /* translators: %s: path of the offline tool */
            __('Generate a key pair and decrypt messages offline with <code>%s</code> from the project repository (it makes no network requests).'),
            'tools/contact-key-tool.html'
        ), ['code' => []]).'</p>';
        if ($fingerprint) {
            echo '<p><strong>'.esc_html__('Current key fingerprint (SHA-256):').'</strong><br><code style="overflow-wrap:anywhere">'.esc_html(trim(chunk_split($fingerprint, 4, ' '))).'</code></p>';
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('nbe_contact_key');
        echo '<input type="hidden" name="action" value="nbe_contact_key"><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="nbe-contact-email">'.esc_html__('Deliver to').'</label></th><td><input id="nbe-contact-email" class="regular-text" type="email" name="email" value="'.esc_attr((string) get_option('nbe_contact_email', '')).'"></td></tr>';
        echo '<tr><th scope="row"><label for="nbe-contact-key">'.esc_html__('Public key (PEM)').'</label></th><td><textarea id="nbe-contact-key" class="large-text code" name="key" rows="12" aria-describedby="nbe-contact-key-help">'.esc_textarea((string) get_option('nbe_contact_key', '')).'</textarea>';
        echo '<p class="description" id="nbe-contact-key-help">'.esc_html__('To rotate, paste a new public key; keep the old private key to read older messages. Clear the field to disable the form. Add the form to any page with the shortcode [nbe_contact].').'</p></td></tr></tbody></table>';
        submit_button(__('Save'));
        echo '</form></div>';
    }

    public static function save(): void
    {
        check_admin_referer('nbe_contact_key');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Not permitted'), '', ['response' => 403]);
        }
        $back = admin_url('options-general.php?page=nbe-contact');
        $pem = trim((string) wp_unslash($_POST['key'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        try {
            $key = $pem === '' ? ['pem' => '', 'fingerprint' => ''] : self::key($pem);
            if ($pem !== '' && !is_email($email)) {
                throw new \RuntimeException(__('Enter the email address that should receive encrypted messages.'));
            }
        } catch (\RuntimeException $e) {
            wp_safe_redirect(add_query_arg('nbe_error', rawurlencode($e->getMessage()), $back));
            exit;
        }
        $changed = $key['fingerprint'] !== (string) get_option('nbe_contact_fingerprint', '');
        update_option('nbe_contact_key', $key['pem']);
        update_option('nbe_contact_fingerprint', $key['fingerprint']);
        update_option('nbe_contact_email', $email);
        if ($changed) {
            EventLog::record($key['pem'] === '' ? 'contact_key_removed' : 'contact_key_changed');
        }
        wp_safe_redirect($back);
        exit;
    }

    public static function form(): string
    {
        $key = (string) get_option('nbe_contact_key', '');
        if ($key === '') {
            return '<p>'.esc_html__('The contact form is not configured yet.').'</p>';
        }
        $fingerprint = (string) get_option('nbe_contact_fingerprint');
        $id = 'nbe-contact-'.wp_unique_id();
        wp_enqueue_script('nbe-contact', plugins_url('contact.js', __FILE__), [], '1.1.0', true);
        return '<form class="nbe-contact" data-key="'.esc_attr(base64_encode($key)).'" data-fingerprint="'.esc_attr($fingerprint).'" data-endpoint="'.esc_url(rest_url('nbe/v1/contact')).'">'
            .'<p>'.esc_html__('Your message is encrypted in your browser before it is sent. Only the recipient can read it.').'</p>'
            .'<p><small>'.esc_html__('Recipient key fingerprint (SHA-256):').' <code style="overflow-wrap:anywhere">'.esc_html(trim(chunk_split($fingerprint, 4, ' '))).'</code></small></p>'
            .'<p><label for="'.esc_attr($id).'">'.esc_html__('Message').'</label><br><textarea id="'.esc_attr($id).'" required rows="8" maxlength="20000"></textarea></p>'
            .'<p><button type="submit">'.esc_html__('Encrypt and send').'</button></p><p role="status" aria-live="polite"></p>'
            .'<noscript><p>'.esc_html__('Encryption happens in your browser and needs JavaScript and HTTPS. No unencrypted fallback is offered.').'</p></noscript></form>';
    }

    /** @return array<string, bool>|\WP_Error */
    public static function receive(\WP_REST_Request $request)
    {
        if (Security::readonly()) {
            return new \WP_Error('readonly', __('Contact is temporarily unavailable.'), ['status' => 503]);
        }
        // Stateless ciphertext-only endpoint: no cookies or user authority are used. The Origin check stops cross-site posting.
        if ($request->get_header('origin') !== rtrim(home_url(), '/')) {
            return new \WP_Error('origin', __('Invalid origin.'), ['status' => 403]);
        }
        if (!RateLimiter::hit('contact', 5, 3600) || !RateLimiter::hit('contact-site', 100, 3600, 'site:'.get_current_blog_id())) {
            return new \WP_Error('rate', __('Too many messages. Try again later.'), ['status' => 429]);
        }
        $body = $request->get_json_params();
        if (empty($body) || strlen($request->get_body()) > 65536) {
            return new \WP_Error('size', __('Invalid encrypted message.'), ['status' => 400]);
        }
        if (($body['version'] ?? 0) !== self::ENVELOPE_VERSION || !hash_equals((string) get_option('nbe_contact_fingerprint'), (string) ($body['fingerprint'] ?? ''))) {
            return new \WP_Error('key', __('The recipient key changed. Reload the page and try again.'), ['status' => 409]);
        }
        foreach (['iv', 'key', 'ciphertext'] as $field) {
            if (!isset($body[$field]) || !is_string($body[$field]) || base64_decode($body[$field], true) === false) {
                return new \WP_Error('ciphertext', __('Invalid encrypted message.'), ['status' => 400]);
            }
        }
        if (strlen((string) base64_decode($body['iv'], true)) !== 12 || strlen((string) base64_decode($body['ciphertext'], true)) < 16 || strlen((string) base64_decode($body['key'], true)) < 384) {
            return new \WP_Error('ciphertext', __('Invalid encrypted message.'), ['status' => 400]);
        }
        $envelope = array_intersect_key($body, array_flip(['version', 'fingerprint', 'iv', 'key', 'ciphertext']));
        $to = (string) get_option('nbe_contact_email');
        $text = sprintf(
            "An encrypted message was sent through the contact form of %s.\n\nCopy everything between the lines into your offline decryption tool.\n\n-----BEGIN NBE ENVELOPE-----\n%s\n-----END NBE ENVELOPE-----\n",
            home_url('/'),
            (string) wp_json_encode($envelope)
        );
        if (!get_option('nbe_contact_key') || !is_email($to) || !wp_mail($to, sprintf('[%s] Encrypted contact message', get_bloginfo('name')), $text)) {
            return new \WP_Error('delivery', __('Delivery is unavailable. Please try later.'), ['status' => 503]);
        }
        EventLog::record('contact_message');
        return ['sent' => true];
    }
}
