<?php

declare(strict_types=1);

namespace NBE;

/**
 * Operator configuration.
 *
 * Values come from the environment (`.env`, rendered by Docker Compose). A
 * small set of policies can additionally be changed at runtime from the
 * network admin "Platform policy" page; those are stored as network options
 * named `nbe_<lowercase key>` and take precedence over the environment.
 */
final class Config
{
    /** Keys that may be overridden from the network admin UI. */
    public const RUNTIME_KEYS = [
        'REGISTRATION_POLICY',
        'REGISTRATION_ALLOWLIST',
        'REGISTRATION_DENYLIST',
        'MAX_SITES_PER_USER',
        'REQUIRE_MFA_FOR_SITE_ADMINS',
    ];

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = '')
    {
        $env = getenv($key);
        $fallback = $env !== false && $env !== '' ? $env : $default;
        if (!function_exists('get_site_option') || !in_array($key, self::RUNTIME_KEYS, true)) {
            return $fallback;
        }
        return get_site_option('nbe_'.strtolower($key), $fallback);
    }

    public static function int(string $key, int $default, int $min = 0, ?int $max = null): int
    {
        $value = (int) self::get($key, (string) $default);
        $value = max($min, $value);
        return $max === null ? $value : min($max, $value);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = strtolower((string) self::get($key, $default ? 'yes' : 'no'));
        return in_array($value, ['1', 'yes', 'true', 'on'], true);
    }

    /** @return list<string> */
    public static function list(string $key, string $default = ''): array
    {
        return Policy::list((string) self::get($key, $default));
    }

    public static function production(): bool
    {
        return getenv('APP_ENV') === 'production';
    }

    public static function brand(): string
    {
        return (string) self::get('BRAND_NAME', 'NoBlogs4Ever');
    }

    public static function registrationPolicy(): string
    {
        $mode = (string) self::get('REGISTRATION_POLICY', 'invitation');
        return in_array($mode, Policy::REGISTRATION_MODES, true) ? $mode : 'invitation';
    }
}
