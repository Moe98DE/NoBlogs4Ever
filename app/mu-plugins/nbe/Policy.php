<?php

declare(strict_types=1);

namespace NBE;

/**
 * Pure, WordPress-independent policy decisions.
 *
 * Everything in this class is deterministic and side-effect free so that it
 * can be exercised by `tests/unit.php` without a database.
 */
final class Policy
{
    public const REGISTRATION_MODES = ['unrestricted', 'allowlist', 'invitation', 'approval'];

    /** Hostnames that must never become tenant subdomains. */
    public const RESERVED_SLUGS = [
        'www', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'sftp', 'ssh', 'vpn', 'ns', 'ns1', 'ns2', 'mx',
        'admin', 'administrator', 'operator', 'operators', 'root', 'sysadmin', 'webmaster', 'hostmaster', 'postmaster',
        'abuse', 'security', 'support', 'help', 'helpdesk', 'billing', 'legal', 'privacy', 'official', 'staff',
        'api', 'status', 'health', 'metrics', 'monitor', 'cdn', 'static', 'assets', 'media', 'uploads', 'files',
        'login', 'logout', 'signin', 'signup', 'register', 'account', 'accounts', 'auth', 'oauth', 'sso', 'id',
        'network', 'wp', 'wordpress', 'blog', 'blogs', 'autodiscover', 'autoconfig', 'localhost', 'test', 'staging',
        'dev', 'backup', 'backups', 'secrets', 'discover', 'directory', 'noblogs4ever',
    ];

    /**
     * Validate a tenant site slug (one DNS label below the platform domain).
     *
     * @param list<string> $extraReserved operator-configured additions
     */
    public static function slug(string $slug, array $extraReserved = []): bool
    {
        if (!preg_match('/\A[a-z][a-z0-9-]{2,61}[a-z0-9]\z/D', $slug)) {
            return false;
        }
        // Punycode labels and "--" positions 3/4 are reserved by IDNA; they can spoof other names.
        if (str_starts_with($slug, 'xn--') || substr($slug, 2, 2) === '--') {
            return false;
        }
        $reserved = array_merge(self::RESERVED_SLUGS, array_map('strtolower', $extraReserved));
        return !in_array($slug, $reserved, true);
    }

    /**
     * @param list<string> $allow
     * @param list<string> $deny
     */
    public static function emailAllowed(string $email, string $mode, array $allow, array $deny): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr((string) strrchr($email, '@'), 1));
        if (in_array($domain, array_map('strtolower', $deny), true)) {
            return false;
        }
        return $mode !== 'allowlist' || in_array($domain, array_map('strtolower', $allow), true);
    }

    /** Whether self-service registration is open at all for a policy mode. */
    public static function selfRegistrationOpen(string $mode): bool
    {
        return in_array($mode, ['unrestricted', 'allowlist', 'approval'], true);
    }

    /** @return list<string> */
    public static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($value))), fn (string $item): bool => $item !== ''));
    }

    /** Post metadata that is portable and safe to import from an untrusted archive. */
    public static function safeMeta(string $key): bool
    {
        // Arbitrary plugin data may contain credentials or executable configuration.
        return in_array($key, ['_wp_page_template', '_wp_old_slug', 'footnotes'], true) || str_starts_with($key, 'public_');
    }

    /** @return list<string> URLs in $text that point at $host (exact host match). */
    public static function sourceReferences(string $text, string $host): array
    {
        $pattern = '~(?:https?:)?//'.preg_quote($host, '~').'(?=[/:?#\s<>"\x27]|$)(?::[0-9]+)?(?:[^\s<>"\x27]*)~iu';
        preg_match_all($pattern, html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m);
        return array_values(array_unique($m[0]));
    }

    /** A relative path that cannot escape its base directory. */
    public static function safePath(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || preg_match('~^(?:/|[a-zA-Z]:)~', $name)) {
            return false;
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '..' || $part === '.') {
                return false;
            }
        }
        return true;
    }

    /**
     * Coarse, non-identifying client classification for aggregate analytics.
     *
     * @return array{bot: bool, browser: string, device: string}
     */
    public static function userAgentClass(string $ua): array
    {
        $bot = $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|curl|wget|python|http-?client|java\/|go-http|headless|preview|feed|monitor|scan/i', $ua);
        $browser = 'other';
        foreach ([
            'edge' => '/Edg(e|A|iOS)?\//',
            'opera' => '/OPR\/|Opera/',
            'samsung' => '/SamsungBrowser/',
            'firefox' => '/Firefox\/|FxiOS/',
            'chrome' => '/Chrome\/|CriOS/',
            'safari' => '/Safari\//',
        ] as $name => $regex) {
            if (preg_match($regex, $ua)) {
                $browser = $name;
                break;
            }
        }
        $device = 'desktop';
        if (preg_match('/iPad|Tablet|Nexus (7|9|10)|SM-T/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobi|iPhone|Android.+Mobile|Windows Phone/i', $ua)) {
            $device = 'mobile';
        }
        if ($bot) {
            $browser = 'automated';
            $device = 'automated';
        }
        return ['bot' => $bot, 'browser' => $browser, 'device' => $device];
    }

    /**
     * Whether an iframe source is on the operator's embed allowlist.
     *
     * @param list<string> $hosts exact hostnames, or ".example.org" for a domain and its subdomains
     */
    public static function iframeAllowed(string $src, array $hosts): bool
    {
        if (!$hosts || !preg_match('~\Ahttps://~i', $src)) {
            return false;
        }
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        if ($host === '' || parse_url($src, PHP_URL_USER) !== null || parse_url($src, PHP_URL_PORT) !== null) {
            return false;
        }
        foreach ($hosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }
            if ($allowed[0] === '.' ? ($host === substr($allowed, 1) || str_ends_with($host, $allowed)) : $host === $allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Imported Additional CSS must be plain CSS: no markup that could break
     * out of the <style> element and no legacy script-capable constructs.
     */
    public static function safeCss(string $css): bool
    {
        return strlen($css) <= 262144
            && !preg_match('~<\s*/?\s*(style|script)|expression\s*\(|javascript:|behavior\s*:|-moz-binding~i', $css);
    }

    /** Derive a valid, conservative WordPress login from an imported author identifier. */
    public static function loginFromSource(string $source): string
    {
        $login = strtolower(preg_replace('/[^a-z0-9]/i', '', $source) ?? '');
        if (strlen($login) < 4) {
            $login = 'author'.$login;
        }
        return substr($login, 0, 50);
    }

    /**
     * Split an uploaded image derivative ("photo-300x200.jpg") into its
     * original name and size, or null when the name is not a derivative.
     *
     * @return array{base: string, width: int, height: int}|null
     */
    public static function derivative(string $path): ?array
    {
        if (!preg_match('~\A(.+)-(\d{1,5})x(\d{1,5})(\.[A-Za-z0-9]{2,5})\z~', $path, $m)) {
            return null;
        }
        return ['base' => $m[1].$m[4], 'width' => (int) $m[2], 'height' => (int) $m[3]];
    }
}
