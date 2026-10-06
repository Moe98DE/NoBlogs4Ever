<?php

declare(strict_types=1);

namespace NBE;

/**
 * Entry point that wires every module into WordPress, plus a few stable
 * helpers used by operator scripts and tests.
 */
final class Platform
{
    public const VERSION = '1.0.0-rc.1';

    /** Modules in boot order. Each exposes a static register(). */
    private const MODULES = [
        Security::class,
        Privacy::class,
        Registration::class,
        Sites::class,
        Media::class,
        Embeds::class,
        Analytics::class,
        Discovery::class,
        Health::class,
        Export::class,
        MigrationAdmin::class,
        Admin::class,
        Integrations::class,
    ];

    public static function boot(): void
    {
        // Multisite site lookup needs a bare hostname (see wp-config.php). Once
        // lookup is done, restore the public development host (with port) for URLs.
        if (isset($_SERVER['NBE_PUBLIC_HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = $_SERVER['NBE_PUBLIC_HTTP_HOST'];
        }
        foreach (self::MODULES as $module) {
            $module::register();
        }
        Contact::boot();
        add_filter('wp_revisions_to_keep', fn ($num) => $num < 0 || $num > 30 ? 30 : $num);
        add_filter('wp_mail', [self::class, 'mailLimit']);
        add_action('phpmailer_init', [self::class, 'smtp']);
    }

    /** Create or upgrade every platform table. Idempotent. */
    public static function install(): void
    {
        Analytics::install();
        Migration::install();
        Export::install();
        update_site_option('nbe_schema_version', self::VERSION);
    }

    /**
     * Network-wide outbound mail cap: protects the SMTP account's reputation
     * if a form or bug starts sending in a loop.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function mailLimit(array $args): array
    {
        if (!RateLimiter::hit('mail', Config::int('MAIL_PER_HOUR', 200, 1), 3600, 'network')) {
            EventLog::record('mail_rate_limited');
            $args['to'] = [];
        }
        return $args;
    }

    /** @param \PHPMailer\PHPMailer\PHPMailer $mailer */
    public static function smtp($mailer): void
    {
        $mailer->isSMTP();
        $mailer->Host = getenv('SMTP_HOST') ?: 'mail';
        $mailer->Port = (int) (getenv('SMTP_PORT') ?: 1025);
        $mailer->SMTPAuth = (bool) getenv('SMTP_USER');
        $mailer->Username = getenv('SMTP_USER') ?: '';
        $mailer->Password = $mailer->SMTPAuth ? nbe_secret('SMTP_PASSWORD') : '';
        $mailer->SMTPSecure = getenv('SMTP_TLS') ?: '';
        $mailer->SMTPAutoTLS = Config::production();
        $mailer->SMTPDebug = 0;
        $mailer->setFrom(getenv('SMTP_FROM') ?: 'publisher@example.invalid', Config::brand(), false);
    }

    // Stable helpers kept for scripts, tests and third-party code.

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function config(string $key, $default = '')
    {
        return Config::get($key, $default);
    }

    public static function readonly(): bool
    {
        return Security::readonly();
    }

    public static function rate(string $scope, int $limit, int $seconds, ?string $subject = null): bool
    {
        return RateLimiter::hit($scope, $limit, $seconds, $subject);
    }

    /** @param array<string, mixed> $data */
    public static function event(string $event, array $data = []): void
    {
        EventLog::record($event, $data);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function upload(array $file): array
    {
        return Media::check($file);
    }

    /**
     * @param array<string, string> $mimes
     * @return array<string, string>
     */
    public static function mimes(array $mimes = []): array
    {
        return Media::mimes($mimes);
    }

    public static function tick(): void
    {
        Worker::run();
    }
}
