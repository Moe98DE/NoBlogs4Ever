<?php

declare(strict_types=1);

namespace NBE;

/**
 * Upload policy and authorized media delivery.
 *
 * Every request below /wp-content/uploads/ is routed to `nbe-media.php`,
 * which calls {@see Media::serve()} so that tenant privacy, draft/private
 * attachments and MIME policy are enforced before a byte is sent.
 */
final class Media
{
    /** Extension → MIME type for every type the platform can accept. Operators narrow this with ALLOWED_EXTENSIONS. */
    public const SAFE_TYPES = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'ogg|oga' => 'audio/ogg',
        'wav' => 'audio/wav',
        'mp4|m4v' => 'video/mp4',
        'webm' => 'video/webm',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'epub' => 'application/epub+zip',
    ];

    public const DEFAULT_EXTENSIONS = 'jpg,jpeg,jpe,png,gif,webp,pdf,txt,mp3,mp4,ods,odt';

    /** Maximum decoded image size (pixels) accepted for derivative generation. */
    public const MAX_PIXELS = 40000000;

    public static function register(): void
    {
        add_filter('upload_mimes', [self::class, 'mimes']);
        add_filter('upload_dir', [self::class, 'uploadDir'], 99);
        add_filter('wp_handle_upload_prefilter', [self::class, 'check']);
        add_filter('wp_handle_sideload_prefilter', [self::class, 'check']);
        add_filter('big_image_size_threshold', fn () => Config::int('IMAGE_MAX_EDGE', 2560, 640, 10000));
        add_filter('wp_image_editors', fn () => ['WP_Image_Editor_GD']);
        add_filter('site_option_upload_filetypes', fn () => implode(' ', self::extensions()));
        add_filter('site_option_fileupload_maxk', fn () => Config::int('MAX_UPLOAD_MB', 32, 1, 4096) * 1024);
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        $allowed = Config::list('ALLOWED_EXTENSIONS', self::DEFAULT_EXTENSIONS);
        $all = [];
        foreach (array_keys(self::SAFE_TYPES) as $key) {
            foreach (explode('|', $key) as $ext) {
                if (in_array($ext, $allowed, true)) {
                    $all[] = $ext;
                }
            }
        }
        return $all;
    }

    /**
     * @param array<string, string> $mimes ignored; the platform list is authoritative
     * @return array<string, string>
     */
    public static function mimes(array $mimes = []): array
    {
        $allowed = self::extensions();
        $out = [];
        foreach (self::SAFE_TYPES as $key => $type) {
            $exts = array_values(array_intersect(explode('|', $key), $allowed));
            if ($exts) {
                $out[implode('|', $exts)] = $type;
            }
        }
        return $out;
    }

    /**
     * Keep tenant upload URLs on the tenant host even when a CLI worker has
     * switched blogs after WP_CONTENT_URL was fixed for the main site.
     *
     * @param array<string, mixed> $uploads
     * @return array<string, mixed>
     */
    public static function uploadDir(array $uploads): array
    {
        if (is_multisite() && !empty($uploads['basedir'])) {
            $site = get_current_blog_id();
            $suffix = $site === get_main_site_id() ? '' : '/sites/'.$site;
            if (rtrim((string) $uploads['basedir'], '/') === WP_CONTENT_DIR.'/uploads'.$suffix) {
                $baseUrl = untrailingslashit(get_site_url()).'/wp-content/uploads'.$suffix;
                $uploads['baseurl'] = $baseUrl;
                $uploads['url'] = $baseUrl.($uploads['subdir'] ?? '');
            }
        }
        return $uploads;
    }

    /**
     * Validate an incoming file (browser upload, sideload or migration).
     *
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function check(array $file): array
    {
        if (!empty($file['error'])) {
            return $file;
        }
        if ((int) ($file['size'] ?? 0) > Config::int('MAX_UPLOAD_MB', 32, 1, 4096) * MB_IN_BYTES) {
            $file['error'] = __('File exceeds the platform upload limit.');
            return $file;
        }
        $info = wp_check_filetype_and_ext((string) $file['tmp_name'], (string) $file['name'], self::mimes());
        if (!$info['ext'] || !$info['type']) {
            $file['error'] = __('File type is not permitted or does not match its contents.');
            return $file;
        }
        if (str_starts_with((string) $info['type'], 'image/')) {
            $size = @getimagesize((string) $file['tmp_name']);
            if (!$size || $size[0] * $size[1] > self::MAX_PIXELS) {
                $file['error'] = __('Image is unreadable or its dimensions exceed the processing limit.');
            }
        }
        return $file;
    }

    /**
     * Stream one uploaded file after authorization. Terminates the request.
     */
    public static function serve(string $requestUri): void
    {
        Privacy::requireSiteAccess();
        $upload = wp_upload_dir();
        $basePath = (string) parse_url((string) $upload['baseurl'], PHP_URL_PATH);
        $path = rawurldecode((string) parse_url($requestUri, PHP_URL_PATH));
        if (!str_starts_with($path, $basePath.'/')) {
            self::fail(404);
        }
        $relative = substr($path, strlen($basePath) + 1);
        if (!Policy::safePath($relative)) {
            self::fail(404);
        }
        $root = realpath((string) $upload['basedir']);
        $real = realpath($upload['basedir'].'/'.$relative);
        if (!$real || !$root || !str_starts_with($real, $root.'/') || !is_file($real)) {
            self::fail(404);
        }
        // The main site's uploads root contains every tenant's "sites/<id>" folder; never serve those through it.
        if (get_current_blog_id() === get_main_site_id() && str_starts_with($relative, 'sites/')) {
            self::fail(404);
        }
        $type = wp_check_filetype($real, self::mimes());
        if (!$type['type']) {
            self::fail(403);
        }
        $public = self::attachmentIsPublic($relative);
        if ($public === null) {
            self::fail(403);
        }
        $size = (int) filesize($real);
        $mtime = (int) filemtime($real);
        $etag = '"'.substr(hash('sha256', $relative.'|'.$size.'|'.$mtime), 0, 32).'"';
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: '.$type['type']);
        header('Accept-Ranges: bytes');
        header('ETag: '.$etag);
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $mtime).' GMT');
        if ($type['type'] !== 'application/pdf') {
            // Browser PDF viewers refuse sandboxed documents; every other type gets an inert, sandboxed context.
            header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; media-src \'self\'; style-src \'unsafe-inline\'; sandbox');
        }
        header($public && !get_option('nbe_private') ? 'Cache-Control: public, max-age=86400' : 'Cache-Control: private, no-store');
        if (!preg_match('~^(image|audio|video)/~', $type['type']) && $type['type'] !== 'application/pdf') {
            header('Content-Disposition: attachment; filename="'.rawurlencode(basename($real)).'"');
        }
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            status_header(304);
            exit;
        }
        [$start, $end] = self::range($size);
        if ($start !== 0 || $end !== $size - 1) {
            status_header(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: '.($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $size === 0) {
            exit;
        }
        $handle = fopen($real, 'rb');
        if ($handle) {
            fseek($handle, $start);
            $remaining = $end - $start + 1;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(1048576, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    /**
     * true: public; false: restricted but the viewer may read it; null: the viewer may not read it.
     */
    private static function attachmentIsPublic(string $relative): ?bool
    {
        global $wpdb;
        $original = preg_replace('/-(?:\d+x\d+|scaled|rotated)(?=\.[^.]+$)/', '', $relative);
        $attachment = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value IN (%s, %s, %s) LIMIT 1",
            $relative,
            $original,
            preg_replace('/(?=\.[^.]+$)/', '-scaled', (string) $original, 1)
        ));
        $record = $attachment ? get_post($attachment) : null;
        $parent = $record && $record->post_parent ? get_post($record->post_parent) : null;
        if ($parent && ($parent->post_status !== 'publish' || $parent->post_password !== '')) {
            return current_user_can('read_post', $parent->ID) ? false : null;
        }
        return true;
    }

    /** @return array{0: int, 1: int} inclusive byte range to send */
    private static function range(int $size): array
    {
        $header = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($size === 0 || !preg_match('/\Abytes=(\d*)-(\d*)\z/', $header, $m) || ($m[1] === '' && $m[2] === '')) {
            return [0, max(0, $size - 1)];
        }
        if ($m[1] === '') {
            $start = max(0, $size - (int) $m[2]);
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }
        if ($start > $end || $start >= $size) {
            status_header(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        return [$start, $end];
    }

    private static function fail(int $status): void
    {
        status_header($status);
        nocache_headers();
        exit;
    }
}
