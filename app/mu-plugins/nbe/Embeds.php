<?php

declare(strict_types=1);

namespace NBE;

/**
 * Controlled iframe embeds.
 *
 * WordPress already strips <iframe> (and all script) from authors without
 * `unfiltered_html`, which nobody but operators has on this platform. oEmbed
 * providers (YouTube, Vimeo, …) keep working through WordPress's own embed
 * handling. For approved cases such as map embeds, the operator can list
 * hosts in EMBED_IFRAME_HOSTS; iframes pointing at those HTTPS hosts survive
 * sanitization and are rewritten into a sandboxed, no-referrer, lazy form.
 * Every other iframe is removed when content is saved. (Rendering is left
 * alone so that WordPress's own oEmbed iframes keep working; shrinking the
 * allowlist therefore applies to content saved afterwards.)
 */
final class Embeds
{
    private const SANDBOX = 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-forms';

    public static function register(): void
    {
        if (!self::hosts()) {
            return;
        }
        add_filter('wp_kses_allowed_html', [self::class, 'allowIframe'], 10, 2);
        foreach (['content_save_pre', 'excerpt_save_pre'] as $hook) {
            add_filter($hook, [self::class, 'filterSlashed'], 11);
        }
    }

    /** @return list<string> */
    public static function hosts(): array
    {
        return Config::list('EMBED_IFRAME_HOSTS');
    }

    /**
     * @param array<string, mixed> $tags
     * @param string|array<mixed> $context
     * @return array<string, mixed>
     */
    public static function allowIframe(array $tags, $context): array
    {
        if ($context === 'post') {
            $tags['iframe'] = ['src' => true, 'width' => true, 'height' => true, 'title' => true, 'loading' => true, 'allowfullscreen' => true, 'referrerpolicy' => true, 'sandbox' => true];
        }
        return $tags;
    }

    public static function filterSlashed(string $content): string
    {
        if (current_user_can('unfiltered_html')) {
            return $content;
        }
        return wp_slash(self::filter(wp_unslash($content)));
    }

    public static function filter(string $html): string
    {
        if (stripos($html, '<iframe') === false) {
            return $html;
        }
        return (string) preg_replace_callback('~<iframe\b[^>]*>(?:.*?</iframe\s*>)?~is', [self::class, 'rewrite'], $html);
    }

    /** @param array<int, string> $match */
    private static function rewrite(array $match): string
    {
        $tag = new \WP_HTML_Tag_Processor($match[0]);
        if (!$tag->next_tag(['tag_name' => 'IFRAME'])) {
            return '';
        }
        $src = html_entity_decode((string) $tag->get_attribute('src'), ENT_QUOTES | ENT_HTML5);
        if (!Policy::iframeAllowed($src, self::hosts())) {
            return '';
        }
        $attrs = ['src' => esc_url($src, ['https'])];
        foreach (['width', 'height'] as $name) {
            $value = (string) $tag->get_attribute($name);
            if (preg_match('/\A\d{1,4}%?\z/', $value)) {
                $attrs[$name] = $value;
            }
        }
        $attrs['title'] = esc_attr(substr((string) ($tag->get_attribute('title') ?: __('Embedded content')), 0, 200));
        $attrs['loading'] = 'lazy';
        $attrs['referrerpolicy'] = 'no-referrer';
        $attrs['sandbox'] = self::SANDBOX;
        $out = '<iframe';
        foreach ($attrs as $name => $value) {
            $out .= ' '.$name.'="'.esc_attr((string) $value).'"';
        }
        return $out.' allowfullscreen></iframe>';
    }
}
