<?php

declare(strict_types=1);

namespace NBE;

/**
 * Untrusted migration archive intake: safe ZIP extraction and bounded WXR parsing.
 *
 * Pure PHP (no WordPress dependency) so it can be unit-tested in isolation.
 * Nothing from an archive is ever executed; theme/plugin code inside an
 * archive is extracted only as inert files and reported as ignored.
 */
final class Archive
{
    /** File types recognised as importable media inside an archive. */
    public const MEDIA_EXTENSIONS = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'pdf', 'txt', 'csv', 'mp3', 'm4a', 'ogg', 'oga', 'wav', 'mp4', 'm4v', 'webm', 'odt', 'ods', 'odp', 'docx', 'xlsx', 'pptx', 'epub'];

    /** Configuration files produced by a NoBlogs4Ever full site archive (top level or one folder deep). */
    public const CONFIG_FILES = ['site.json', 'manifest.json'];

    /** Browser-intake defaults. Operators may raise them (up to CEILINGS) only with an explicit acknowledgement. */
    public const MAX_INPUT = 268435456;     // 256 MiB uploaded file
    public const MAX_BYTES = 1073741824;    // 1 GiB total extracted
    public const MAX_FILES = 10000;         // archive entries
    public const MAX_FILE = 67108864;       // 64 MiB per entry
    public const MAX_XML = 33554432;        // 32 MiB per WXR file
    public const MAX_ITEMS = 20000;         // WXR items in total

    public const CEILINGS = [
        'input' => 2147483648,
        'bytes' => 8589934592,
        'files' => 100000,
        'file' => 1073741824,
        'xml' => 134217728,
        'items' => 200000,
    ];

    /** Uncompressed/compressed ratio above which a large entry is treated as a decompression bomb. */
    private const MAX_RATIO = 200;

    /**
     * @param array<string, mixed> $requested
     * @return array{input: int, bytes: int, files: int, file: int, xml: int, items: int}
     */
    public static function limits(array $requested): array
    {
        $limits = [
            'input' => self::MAX_INPUT, 'bytes' => self::MAX_BYTES, 'files' => self::MAX_FILES,
            'file' => self::MAX_FILE, 'xml' => self::MAX_XML, 'items' => self::MAX_ITEMS,
        ];
        foreach ($limits as $key => $default) {
            if (!isset($requested[$key])) {
                continue;
            }
            $value = (int) $requested[$key];
            if ($value < 1 || $value > self::CEILINGS[$key] || ($value > $default && empty($requested['operator_ack']))) {
                throw new \RuntimeException('Invalid or unauthorized archive limit: '.$key);
            }
            $limits[$key] = $value;
        }
        return $limits;
    }

    /**
     * Copy a WXR file, or safely extract a ZIP, into a private workspace.
     *
     * Every ZIP entry is preflighted before anything is written: unsafe or
     * duplicate paths, symlinks/devices, oversized entries, too many entries
     * and suspicious compression ratios abort the whole intake.
     *
     * @param array<string, mixed> $requested limits (see limits())
     * @return array<string, mixed> inventory
     */
    public static function extract(string $input, string $dest, array $requested = []): array
    {
        $limits = self::limits($requested);
        if (!is_file($input) || filesize($input) > $limits['input']) {
            throw new \RuntimeException('Input exceeds configured limit.');
        }
        if (!is_dir($dest) && !mkdir($dest, 0700, true)) {
            throw new \RuntimeException('Cannot create private workspace.');
        }
        $inventory = [
            'sha256' => hash_file('sha256', $input), 'input_size' => filesize($input),
            'file_count' => 0, 'extracted_size' => 0, 'xml' => [], 'media' => [], 'config' => [], 'ignored' => [],
        ];
        if (str_starts_with((string) file_get_contents($input, false, null, 0, 4), 'PK')) {
            self::extractZip($input, $dest, $limits, $inventory);
        } else {
            if (filesize($input) > $limits['xml']) {
                throw new \RuntimeException('XML exceeds configured limit.');
            }
            if (!copy($input, $dest.'/publication.xml')) {
                throw new \RuntimeException('Cannot retain XML.');
            }
            chmod($dest.'/publication.xml', 0600);
            self::record($inventory, 'publication.xml', (int) filesize($input));
        }
        if (!$inventory['xml']) {
            throw new \RuntimeException('No WXR XML file was found in the archive.');
        }
        sort($inventory['xml']);
        return $inventory;
    }

    /**
     * @param array<string, int> $limits
     * @param array<string, mixed> $inventory
     */
    private static function extractZip(string $input, string $dest, array $limits, array &$inventory): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($input) !== true) {
            throw new \RuntimeException('Invalid ZIP archive.');
        }
        try {
            if ($zip->numFiles > $limits['files']) {
                throw new \RuntimeException('Too many archive entries.');
            }
            $seen = [];
            $bytes = 0;
            // Preflight every entry. Never use ZipArchive::extractTo() on untrusted archives.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                $opsys = 0;
                $attr = 0;
                $zip->getExternalAttributesIndex($i, $opsys, $attr);
                $type = ($attr >> 16) & 0170000;
                // 0120000 symlink, 0060000 block device, 0020000 char device, 0010000 FIFO
                if (!Policy::safePath($name) || in_array($type, [0120000, 0060000, 0020000, 0010000], true)) {
                    throw new \RuntimeException('Unsafe archive path or special file.');
                }
                $normalized = strtolower(rtrim($name, '/'));
                if (isset($seen[$normalized])) {
                    throw new \RuntimeException('Duplicate archive path.');
                }
                $seen[$normalized] = true;
                $bytes += (int) $stat['size'];
                $ratio = $stat['size'] / max(1, $stat['comp_size']);
                if ($bytes > $limits['bytes'] || $stat['size'] > $limits['file'] || ($stat['size'] > 1048576 && $ratio > self::MAX_RATIO)) {
                    throw new \RuntimeException('Archive expansion limit exceeded.');
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $path = $dest.'/'.$name;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0700, true);
                }
                $in = $zip->getStream($name);
                $out = fopen($path, 'xb');
                if (!$in || !$out) {
                    throw new \RuntimeException('Cannot extract entry.');
                }
                // Copy at most one byte more than declared so lying headers are detected.
                $copied = stream_copy_to_stream($in, $out, $limits['file'] + 1);
                fclose($in);
                fclose($out);
                chmod($path, 0600);
                if ($copied !== (int) $stat['size']) {
                    throw new \RuntimeException('Archive entry size mismatch.');
                }
                self::record($inventory, $name, (int) $stat['size']);
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, mixed> $inventory */
    private static function record(array &$inventory, string $name, int $size): void
    {
        $inventory['file_count']++;
        $inventory['extracted_size'] += $size;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = strtolower(basename($name));
        if ($ext === 'xml') {
            $inventory['xml'][] = $name;
        } elseif (in_array($base, self::CONFIG_FILES, true) && substr_count($name, '/') <= 1) {
            $inventory['config'][$base] = $name;
        } elseif (in_array($ext, self::MEDIA_EXTENSIONS, true)) {
            $inventory['media'][] = $name;
        } else {
            $inventory['ignored'][] = $name;
        }
    }

    /**
     * Parse one or more WXR files from the same source into one data set.
     *
     * XML files that are not WXR (e.g. a sitemap shipped with media) are
     * skipped and listed. All WXR files must declare the same source site.
     *
     * @param list<string> $paths
     * @param array<string, mixed> $requested
     * @return array{source: string, authors: list<string>, terms: list<array<string, string>>, items: list<array<string, mixed>>, files: list<string>, skipped: list<string>}
     */
    public static function wxrSet(array $paths, array $requested = []): array
    {
        $limits = self::limits($requested);
        $merged = ['source' => '', 'authors' => [], 'terms' => [], 'items' => [], 'files' => [], 'skipped' => []];
        $ids = [];
        foreach ($paths as $path) {
            try {
                $data = self::wxr($path, $requested);
            } catch (\RuntimeException $e) {
                if (count($paths) > 1 && str_starts_with($e->getMessage(), 'Not a WXR')) {
                    $merged['skipped'][] = basename($path);
                    continue;
                }
                throw $e;
            }
            if ($merged['source'] !== '' && $merged['source'] !== $data['source']) {
                throw new \RuntimeException('WXR files in one archive must come from the same source site. Import them separately.');
            }
            $merged['source'] = $data['source'];
            foreach ($data['items'] as $item) {
                if (isset($ids[$item['id']])) {
                    throw new \RuntimeException('Duplicate source post ID across WXR files.');
                }
                $ids[$item['id']] = true;
                $merged['items'][] = $item;
            }
            if (count($merged['items']) > $limits['items']) {
                throw new \RuntimeException('Archive exceeds configured item limit; split the export or use the reviewed operator path.');
            }
            $merged['authors'] = array_values(array_unique(array_merge($merged['authors'], $data['authors'])));
            $merged['terms'] = array_merge($merged['terms'], $data['terms']);
            $merged['files'][] = basename($path);
        }
        if ($merged['source'] === '') {
            throw new \RuntimeException('No WXR document was found.');
        }
        return $merged;
    }

    /**
     * Parse a single WXR 1.0–1.2 document. DTDs and entities are rejected
     * outright and the network is never consulted.
     *
     * @param array<string, mixed> $requested
     * @return array{source: string, authors: list<string>, terms: list<array<string, string>>, items: list<array<string, mixed>>}
     */
    public static function wxr(string $path, array $requested = []): array
    {
        $limits = self::limits($requested);
        if (filesize($path) > $limits['xml']) {
            throw new \RuntimeException('XML exceeds configured limit.');
        }
        $xml = (string) file_get_contents($path);
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml) || str_contains($xml, "\0")) {
            throw new \RuntimeException('DTD, entities and non-UTF-8 XML are forbidden.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if (!$doc) {
                throw new \RuntimeException('Malformed WXR document.');
            }
            if (!isset($doc->channel)) {
                throw new \RuntimeException('Not a WXR document (no RSS channel).');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        unset($xml);
        $ns = $doc->getDocNamespaces(true);
        $wp = $ns['wp'] ?? '';
        if (!preg_match('~^https?://wordpress.org/export/1\.[012]/$~', $wp)) {
            throw new \RuntimeException('Not a WXR document (unsupported or missing WordPress export namespace).');
        }
        $contentNs = $ns['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $excerptNs = $ns['excerpt'] ?? 'http://wordpress.org/export/1.2/excerpt/';
        $dcNs = $ns['dc'] ?? 'http://purl.org/dc/elements/1.1/';
        $channel = $doc->channel;
        $site = $channel->children($wp);
        $source = (string) $site->base_blog_url ?: (string) $site->base_site_url;
        if (!in_array(parse_url($source, PHP_URL_SCHEME), ['http', 'https'], true) || !parse_url($source, PHP_URL_HOST)) {
            throw new \RuntimeException('WXR must declare a valid source URL.');
        }

        $authors = [];
        foreach ($site->author as $author) {
            $authors[] = (string) $author->author_login;
        }

        $terms = [];
        foreach ($site->category as $t) {
            $terms[] = ['id' => (string) $t->term_id, 'taxonomy' => 'category', 'slug' => (string) $t->category_nicename, 'name' => (string) $t->cat_name, 'parent' => (string) $t->category_parent];
        }
        foreach ($site->tag as $t) {
            $terms[] = ['id' => (string) $t->term_id, 'taxonomy' => 'post_tag', 'slug' => (string) $t->tag_slug, 'name' => (string) $t->tag_name, 'parent' => ''];
        }
        foreach ($site->term as $t) {
            $terms[] = ['id' => (string) $t->term_id, 'taxonomy' => (string) $t->term_taxonomy, 'slug' => (string) $t->term_slug, 'name' => (string) $t->term_name, 'parent' => (string) $t->term_parent];
        }

        $items = [];
        $ids = [];
        foreach ($channel->item as $item) {
            $w = $item->children($wp);
            $id = (string) $w->post_id;
            if (!ctype_digit($id) || $id === '0' || isset($ids[$id])) {
                throw new \RuntimeException('Missing or duplicate source post ID.');
            }
            $ids[$id] = true;
            $comments = [];
            foreach ($w->comment as $c) {
                $comments[] = [
                    'id' => (string) $c->comment_id, 'parent' => (string) $c->comment_parent,
                    'author' => (string) $c->comment_author, 'email' => (string) $c->comment_author_email, 'url' => (string) $c->comment_author_url,
                    'date' => (string) $c->comment_date, 'date_gmt' => (string) $c->comment_date_gmt,
                    'content' => (string) $c->comment_content, 'approved' => (string) $c->comment_approved, 'type' => (string) $c->comment_type,
                ];
            }
            $meta = [];
            foreach ($w->postmeta as $m) {
                $meta[(string) $m->meta_key] = (string) $m->meta_value;
            }
            $categories = [];
            foreach ($item->category as $c) {
                $categories[] = ['taxonomy' => (string) $c['domain'], 'slug' => (string) $c['nicename'], 'name' => (string) $c];
            }
            $items[] = [
                'id' => $id,
                'title' => (string) $item->title,
                'content' => (string) $item->children($contentNs)->encoded,
                'excerpt' => (string) $item->children($excerptNs)->encoded,
                'author' => (string) $item->children($dcNs)->creator,
                'date' => (string) $w->post_date, 'date_gmt' => (string) $w->post_date_gmt,
                'modified' => (string) $w->post_modified, 'modified_gmt' => (string) $w->post_modified_gmt,
                'status' => (string) $w->status, 'type' => (string) $w->post_type, 'slug' => (string) $w->post_name,
                'parent' => (string) $w->post_parent, 'menu_order' => (int) $w->menu_order,
                'comment_status' => (string) $w->comment_status, 'ping_status' => (string) $w->ping_status,
                'password' => (string) $w->post_password, 'sticky' => (string) $w->is_sticky === '1',
                'link' => (string) $item->link, 'attachment_url' => (string) $w->attachment_url,
                'meta' => $meta, 'categories' => $categories, 'comments' => $comments,
            ];
            if (count($items) > $limits['items']) {
                throw new \RuntimeException('Archive exceeds configured item limit; split the export or use the reviewed operator path.');
            }
        }
        return [
            'source' => rtrim($source, '/'),
            'authors' => array_values(array_unique(array_merge($authors, array_column($items, 'author')))),
            'terms' => $terms,
            'items' => $items,
        ];
    }
}
