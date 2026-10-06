<?php

declare(strict_types=1);

namespace NBE;

/**
 * Migration engine: durable, resumable, idempotent WXR/archive import.
 *
 * Job lifecycle (wp_nbe_jobs.state):
 *
 *   intake ─► awaiting_author_mapping ─► queued ─► running ─► complete
 *                     ▲                                  └──► needs_attention
 *                     └──────── (re-inventory) ◄── failed ◄──┘ (retry)
 *
 * - The uploaded original is kept untouched in a private job directory.
 * - Inventory happens before any content is written; every source author must
 *   be mapped to a destination member (or invited) before import starts.
 * - Items are imported in small committed batches. A deterministic source key
 *   (source URL + source post ID) is recorded per site in wp_nbe_map, so a
 *   restarted or repeated import never duplicates content.
 * - Media must exist as a binary in the archive; nothing is fetched remotely.
 * - URLs are rewritten only after the replacement exists locally, and every
 *   rewrite is recorded in the report.
 *
 * The admin UI lives in {@see MigrationAdmin}.
 */
final class Migration
{
    /** Post types imported as content. */
    private const CONTENT_TYPES = ['post', 'page', 'attachment', 'wp_block', 'wp_navigation'];

    /** Site Editor types, imported only when their theme is in the curated catalog. */
    private const THEME_TYPES = ['wp_template', 'wp_template_part', 'wp_global_styles'];

    /** Presentation items reconstructed after all content exists. */
    private const DEFERRED_TYPES = ['nav_menu_item', 'custom_css'];

    /** Source post statuses preserved as-is. */
    private const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'inherit'];

    public static function root(): string
    {
        return getenv('NBE_JOB_DIR') ?: '/var/lib/nbe/jobs';
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->base_prefix.'nbe_jobs';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta('CREATE TABLE '.self::table()." (
 id char(36) NOT NULL,
 site_id bigint unsigned NOT NULL,
 user_id bigint unsigned NOT NULL,
 checksum char(64) NOT NULL,
 state varchar(24) NOT NULL,
 `cursor` int NOT NULL DEFAULT 0,
 total int NOT NULL DEFAULT 0,
 created datetime NOT NULL,
 updated datetime NOT NULL,
 report longtext NOT NULL,
 PRIMARY KEY  (id),
 KEY site_state (site_id,state),
 UNIQUE KEY site_checksum (site_id,checksum)
) $collate;");
        dbDelta('CREATE TABLE '.$wpdb->base_prefix."nbe_map (
 site_id bigint unsigned NOT NULL,
 source_key char(64) NOT NULL,
 object_id bigint unsigned NOT NULL,
 PRIMARY KEY  (site_id,source_key)
) $collate;");
    }

    /** A new, empty report with every field the UI and validators expect. */
    public static function emptyReport(string $checksum, int $size, int $user, array $limits = []): array
    {
        return [
            'archive_checksum' => $checksum,
            'input_size' => $size,
            'author_mappings' => [],
            'fallback_author' => $user,
            'operator_limits' => $limits,
            'warnings' => [],
            'errors' => [],
            'media_discovered' => 0,
            'media_imported' => 0,
            'media_missing' => [],
            'imported_posts' => 0,
            'imported_by_type' => [],
            'imported_comments' => 0,
            'imported_taxonomies' => 0,
            'imported_menus' => 0,
            'unsupported_post_types' => [],
            'unsupported_taxonomies' => [],
            'unsupported_blocks' => [],
            'unsupported_shortcodes' => [],
            'url_rewrites' => [],
            'unresolved_source_references' => [],
            'theme_compatibility' => [],
            'presentation' => [],
            'external_integrations' => [
                'Reconnect external accounts after migration: social/OAuth connections, API credentials, application passwords and federation identity are never part of WXR and are not imported.',
                'Encrypted-contact public keys must be configured again under Settings → Encrypted contact.',
            ],
        ];
    }

    // ---------------------------------------------------------------- intake

    /**
     * Register an archive for import. Returns the job ID; the same archive for
     * the same site returns the existing job.
     *
     * @param array<string, int|string> $authors optional pre-supplied source author → user ID map
     * @param array<string, mixed> $limits operator-approved limits
     */
    public static function intake(string $file, int $site, int $user, array $authors = [], array $limits = []): string
    {
        if (!user_can_for_site($user, $site, 'manage_options')) {
            throw new \RuntimeException('Importer must administer destination site.');
        }
        foreach ($authors as $id) {
            if (!is_numeric($id) || !is_user_member_of_blog((int) $id, $site)) {
                throw new \RuntimeException('Author mapping must target existing site members.');
            }
        }
        $limits = $limits ? $limits : [];
        $maxInput = Archive::limits($limits)['input'];
        if (!is_file($file) || filesize($file) > $maxInput) {
            throw new \RuntimeException('Archive exceeds size limit.');
        }
        global $wpdb;
        $checksum = (string) hash_file('sha256', $file);
        $prior = $wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table().' WHERE site_id = %d AND checksum = %s', $site, $checksum));
        if ($prior) {
            self::handoffToWorker(self::root().'/'.$prior);
            return (string) $prior;
        }
        $id = wp_generate_uuid4();
        $dir = self::root().'/'.$id;
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Private job storage unavailable.');
        }
        if (!copy($file, $dir.'/original')) {
            throw new \RuntimeException('Cannot retain original.');
        }
        chmod($dir.'/original', 0600);
        self::writePrivate($dir.'/authors.json', (string) wp_json_encode($authors));
        self::writePrivate($dir.'/limits.json', (string) wp_json_encode($limits));
        if ($authors) {
            self::writePrivate($dir.'/mapping-ready', '');
        }
        self::handoffToWorker($dir);
        $report = self::emptyReport($checksum, (int) filesize($file), $user, $limits);
        $report['author_mappings'] = $authors;
        $now = current_time('mysql', true);
        if (!$wpdb->insert(self::table(), ['id' => $id, 'site_id' => $site, 'user_id' => $user, 'checksum' => $checksum, 'state' => 'intake', 'created' => $now, 'updated' => $now, 'report' => wp_json_encode($report)])) {
            throw new \RuntimeException('Could not create migration record.');
        }
        EventLog::record('migration_queued', ['job' => $id, 'site' => $site]);
        return $id;
    }

    private static function writePrivate(string $path, string $contents): void
    {
        file_put_contents($path, $contents, LOCK_EX);
        chmod($path, 0600);
    }

    /**
     * Browser uploads are written by the web server user, operator imports by
     * root; make sure the worker (same owner as the job root) can read them.
     */
    private static function handoffToWorker(string $dir): void
    {
        $owner = fileowner(self::root());
        $group = filegroup(self::root());
        if ($owner === false || $group === false || !is_dir($dir) || is_link($dir)) {
            throw new \RuntimeException('Private job storage ownership is unavailable.');
        }
        foreach ([$dir, $dir.'/original', $dir.'/authors.json', $dir.'/limits.json', $dir.'/mapping-ready'] as $path) {
            if (!file_exists($path)) {
                continue;
            }
            if (is_link($path) || (fileowner($path) !== $owner && !@chown($path, $owner)) || (filegroup($path) !== $group && !@chgrp($path, $group))) {
                throw new \RuntimeException('Cannot give the migration worker access to its private job.');
            }
        }
    }

    public static function job(string $id): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id = %s', $id));
        return $row ?: null;
    }

    /**
     * Save a complete author map and queue the import.
     *
     * @param array<string, int> $map source author → destination user ID
     */
    public static function saveAuthorMap(object $job, array $map): void
    {
        $report = json_decode($job->report, true) ?: [];
        foreach ((array) ($report['authors'] ?? []) as $source) {
            if (!isset($map[$source]) || !is_user_member_of_blog((int) $map[$source], (int) $job->site_id)) {
                throw new \RuntimeException('Every source author must map to a member of the destination site.');
            }
        }
        $dir = self::root().'/'.$job->id;
        self::writePrivate($dir.'/authors.json', (string) wp_json_encode($map));
        self::writePrivate($dir.'/mapping-ready', '');
        $report['author_mappings'] = $map;
        global $wpdb;
        $wpdb->update(self::table(), ['state' => 'queued', 'report' => wp_json_encode($report), 'updated' => current_time('mysql', true)], ['id' => $job->id, 'site_id' => (int) $job->site_id]);
        EventLog::record('migration_author_map_saved', ['job' => $job->id]);
    }

    /** Queue a job again. Already imported items are skipped by the idempotency map. */
    public static function retry(object $job): void
    {
        global $wpdb;
        $parsed = is_file(self::root().'/'.$job->id.'/parsed.json');
        $wpdb->update(self::table(), ['state' => $parsed ? 'queued' : 'intake', 'cursor' => 0, 'updated' => current_time('mysql', true)], ['id' => $job->id]);
        EventLog::record('migration_retried', ['job' => $job->id]);
    }

    // ---------------------------------------------------------------- worker

    /** Advance the oldest runnable job by one batch. Returns whether work was done. */
    public static function runNext(): bool
    {
        if (Security::readonly()) {
            return false;
        }
        global $wpdb;
        $id = $wpdb->get_var('SELECT id FROM '.self::table()." WHERE state IN ('intake','queued','running') ORDER BY created LIMIT 1");
        return $id ? self::run((string) $id) : false;
    }

    public static function run(string $id, int $batch = 20): bool
    {
        global $wpdb;
        if (Security::readonly()) {
            return false;
        }
        $table = self::table();
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', 'nbe-'.$id)) !== 1) {
            return false;
        }
        $oldUser = get_current_user_id();
        $switched = false;
        $report = null;
        try {
            $job = self::job($id);
            if (!$job || !in_array($job->state, ['intake', 'queued', 'running'], true)) {
                return false;
            }
            if (!user_can_for_site((int) $job->user_id, (int) $job->site_id, 'manage_options')) {
                throw new \RuntimeException('Importer no longer administers destination.');
            }
            switch_to_blog((int) $job->site_id);
            $switched = true;
            wp_set_current_user((int) $job->user_id);
            $dir = self::root().'/'.$id;
            $report = json_decode($job->report, true, 512, JSON_THROW_ON_ERROR);
            if ($job->state === 'intake') {
                $data = self::inventory($dir, $report);
                $job->cursor = 0;
                $wpdb->update($table, ['state' => 'queued', 'cursor' => 0, 'total' => count($data['items']), 'report' => wp_json_encode($report), 'updated' => current_time('mysql', true)], ['id' => $id]);
            } else {
                $data = json_decode((string) file_get_contents($dir.'/parsed.json'), true, 512, JSON_THROW_ON_ERROR);
            }
            $map = json_decode((string) file_get_contents($dir.'/authors.json'), true, 512, JSON_THROW_ON_ERROR) ?: [];
            if ($data['authors'] && (!is_file($dir.'/mapping-ready') || array_diff($data['authors'], array_keys($map)))) {
                // Inventory is done; nothing is imported until every source author has a destination.
                @unlink($dir.'/mapping-ready');
                $wpdb->update($table, ['state' => 'awaiting_author_mapping', 'report' => wp_json_encode($report), 'updated' => current_time('mysql', true)], ['id' => $id]);
                return true;
            }
            if (!is_file($dir.'/terms-ready')) {
                self::terms($data['terms'], $report);
                self::writePrivate($dir.'/terms-ready', '');
            }
            $total = count($data['items']);
            $end = min((int) $job->cursor + $batch, $total);
            for ($i = (int) $job->cursor; $i < $end; $i++) {
                $wpdb->query('START TRANSACTION');
                try {
                    self::item($data['items'][$i], $data, $dir, $map, (int) $job->user_id, $report);
                    $wpdb->update($table, ['cursor' => $i + 1, 'state' => 'running', 'updated' => current_time('mysql', true), 'report' => wp_json_encode($report)], ['id' => $id]);
                    $wpdb->query('COMMIT');
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    throw new \RuntimeException('Item '.$data['items'][$i]['id'].': '.$e->getMessage(), 0, $e);
                }
            }
            if ($end >= $total) {
                self::finish($data, $dir, $report);
                $report['errors'] = [];
                $state = ($report['media_missing'] || $report['unresolved_source_references'] || $report['unsupported_post_types']) ? 'needs_attention' : 'complete';
                $wpdb->update($table, ['state' => $state, 'report' => wp_json_encode($report), 'updated' => current_time('mysql', true)], ['id' => $id]);
                EventLog::record('migration_validated', ['job' => $id, 'state' => $state]);
                self::notify($job, $state);
            }
            return true;
        } catch (\Throwable $e) {
            $report = $report ?? ['errors' => []];
            $report['errors'][] = $e->getMessage();
            $wpdb->update($table, ['state' => 'failed', 'report' => wp_json_encode($report), 'updated' => current_time('mysql', true)], ['id' => $id]);
            EventLog::record('migration_failed', ['job' => $id, 'code' => 'processing']);
            if (isset($job)) {
                self::notify($job, 'failed');
            }
            return true;
        } finally {
            if ($switched) {
                restore_current_blog();
            }
            wp_set_current_user($oldUser);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'nbe-'.$id));
        }
    }

    /**
     * Extract, parse and inventory the original. Writes parsed.json.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed> parsed data
     */
    private static function inventory(string $dir, array &$report): array
    {
        if (is_dir($dir.'/extracted')) {
            self::removeTree($dir.'/extracted');
        }
        @unlink($dir.'/terms-ready');
        $limits = is_readable($dir.'/limits.json') ? (json_decode((string) file_get_contents($dir.'/limits.json'), true) ?: []) : [];
        $inventory = Archive::extract($dir.'/original', $dir.'/extracted', $limits);
        $data = Archive::wxrSet(array_map(fn ($xml) => $dir.'/extracted/'.$xml, $inventory['xml']), $limits);
        $data['site_config'] = self::readSiteConfig($dir, $inventory);
        $types = array_count_values(array_column($data['items'], 'type'));
        $content = implode("\n", array_column($data['items'], 'content'));
        preg_match_all('/<!--\s+wp:([a-z0-9\/-]+)/i', $content, $blocks);
        preg_match_all('/\[([a-zA-Z][\w-]*)(?=[\s\]\/])/', $content, $shortcodes);
        $host = (string) parse_url($data['source'], PHP_URL_HOST);
        $customFields = [];
        foreach ($data['items'] as $item) {
            foreach (array_keys($item['meta']) as $key) {
                if (!str_starts_with($key, '_')) {
                    $customFields[$key] = true;
                }
            }
        }
        $report['inventory'] = $inventory + [
            'wxr_files' => $data['files'],
            'skipped_xml' => $data['skipped'],
            'post_types' => $types,
            'posts' => $types['post'] ?? 0,
            'pages' => $types['page'] ?? 0,
            'attachments' => $types['attachment'] ?? 0,
            'comments' => array_sum(array_map(fn ($i) => count($i['comments']), $data['items'])),
            'authors' => $data['authors'],
            'categories' => count(array_filter($data['terms'], fn ($t) => $t['taxonomy'] === 'category')),
            'tags' => count(array_filter($data['terms'], fn ($t) => $t['taxonomy'] === 'post_tag')),
            'terms' => $data['terms'],
            'custom_post_types' => array_values(array_diff(array_keys($types), array_merge(self::CONTENT_TYPES, self::THEME_TYPES, self::DEFERRED_TYPES))),
            'custom_fields' => array_keys($customFields),
            'blocks' => array_values(array_unique(array_map(fn ($b) => str_contains($b, '/') ? $b : 'core/'.$b, $blocks[1]))),
            'shortcodes' => array_values(array_unique($shortcodes[1])),
            'source_domain_references' => count(Policy::sourceReferences($content, $host)),
            'legacy_files_paths' => substr_count($content, '/files/'),
            'remote_media' => array_values(array_filter(array_map(fn ($i) => $i['type'] === 'attachment' ? $i['attachment_url'] : null, $data['items']))),
            'site_config' => $data['site_config'] ? 'NoBlogs4Ever site.json found' : 'none',
        ];
        $report['source'] = $data['source'];
        $report['authors'] = $data['authors'];
        $report['media_discovered'] = count($inventory['media']);
        $report['unsupported_post_types'] = $report['inventory']['custom_post_types'];
        self::writePrivate($dir.'/parsed.json', (string) wp_json_encode($data));
        return $data;
    }

    /**
     * Safe subset of a NoBlogs4Ever site.json, or null.
     *
     * @param array<string, mixed> $inventory
     * @return array<string, mixed>|null
     */
    private static function readSiteConfig(string $dir, array $inventory): ?array
    {
        $name = $inventory['config']['site.json'] ?? null;
        if (!$name || !is_file($dir.'/extracted/'.$name) || filesize($dir.'/extracted/'.$name) > 1048576) {
            return null;
        }
        $config = json_decode((string) file_get_contents($dir.'/extracted/'.$name), true, 16);
        if (!is_array($config) || !str_starts_with((string) ($config['format'] ?? ''), 'noblogs4ever-site-archive/')) {
            return null;
        }
        return [
            'theme' => is_array($config['theme'] ?? null) ? ['stylesheet' => sanitize_key((string) ($config['theme']['stylesheet'] ?? ''))] : null,
            'custom_css' => is_string($config['custom_css'] ?? null) ? $config['custom_css'] : '',
            'menu_locations' => is_array($config['menu_locations'] ?? null) ? array_map(fn ($v) => sanitize_title((string) $v), $config['menu_locations']) : [],
            'show_on_front' => in_array($config['show_on_front'] ?? '', ['posts', 'page'], true) ? $config['show_on_front'] : null,
            'page_on_front' => (int) ($config['page_on_front'] ?? 0),
            'page_for_posts' => (int) ($config['page_for_posts'] ?? 0),
        ];
    }

    private static function removeTree(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private static function sourceKey(string $source, string $id): string
    {
        return hash('sha256', $source.'|'.$id);
    }

    private static function mapped(string $source, string $id): int
    {
        global $wpdb;
        if ($id === '' || $id === '0') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT object_id FROM '.$wpdb->base_prefix.'nbe_map WHERE site_id = %d AND source_key = %s', get_current_blog_id(), self::sourceKey($source, $id)));
    }

    private static function remember(string $source, string $id, int $dest): void
    {
        global $wpdb;
        $ok = $wpdb->replace($wpdb->base_prefix.'nbe_map', ['site_id' => get_current_blog_id(), 'source_key' => self::sourceKey($source, $id), 'object_id' => $dest]);
        if (!$ok) {
            throw new \RuntimeException('Cannot persist idempotency map.');
        }
    }

    /**
     * @param list<array<string, string>> $terms
     * @param array<string, mixed> $report
     */
    private static function terms(array $terms, array &$report): void
    {
        $unsupported = [];
        foreach ($terms as $term) {
            if (!in_array($term['taxonomy'], ['category', 'post_tag'], true)) {
                if (!in_array($term['taxonomy'], ['nav_menu', 'wp_theme', 'wp_template_part_area', 'post_format'], true)) {
                    $unsupported[$term['taxonomy']] = true;
                }
                continue;
            }
            if (!term_exists($term['slug'], $term['taxonomy'])) {
                $created = wp_insert_term($term['name'] ?: $term['slug'], $term['taxonomy'], ['slug' => $term['slug']]);
                if (!is_wp_error($created)) {
                    $report['imported_taxonomies']++;
                }
            }
        }
        foreach ($terms as $term) {
            if ($term['parent'] && $term['taxonomy'] === 'category') {
                $child = get_term_by('slug', $term['slug'], 'category');
                $parent = get_term_by('slug', $term['parent'], 'category');
                if ($child && $parent) {
                    wp_update_term($child->term_id, 'category', ['parent' => $parent->term_id]);
                }
            }
        }
        $report['unsupported_taxonomies'] = array_keys($unsupported);
    }

    /** @return list<string> stylesheets of curated, installed themes */
    private static function curatedThemes(): array
    {
        $allowed = array_keys(array_filter((array) get_site_option('allowedthemes', [])));
        return array_values(array_filter($allowed, fn ($slug) => wp_get_theme((string) $slug)->exists()));
    }

    /**
     * Import one source item inside the caller's transaction.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     * @param array<string, int> $authors
     * @param array<string, mixed> $report
     */
    private static function item(array $item, array $data, string $dir, array $authors, int $fallback, array &$report): void
    {
        $source = $data['source'];
        $existing = self::mapped($source, $item['id']);
        if ($existing && get_post($existing)) {
            return;
        }
        $type = $item['type'];
        if (in_array($type, self::DEFERRED_TYPES, true)) {
            return; // handled in finish() once every referenced object exists
        }
        $themeTerm = '';
        if (in_array($type, self::THEME_TYPES, true)) {
            foreach ($item['categories'] as $term) {
                if ($term['taxonomy'] === 'wp_theme') {
                    $themeTerm = $term['slug'];
                }
            }
            if (!in_array($themeTerm, self::curatedThemes(), true)) {
                $report['presentation'][] = sprintf('Site Editor %s "%s" belongs to theme "%s", which is not in the curated catalog; kept in the original archive only.', $type, $item['slug'], $themeTerm ?: 'unknown');
                return;
            }
        } elseif (!in_array($type, self::CONTENT_TYPES, true)) {
            $report['unsupported_post_types'] = array_values(array_unique(array_merge($report['unsupported_post_types'], [$type])));
            return;
        }
        $author = (int) ($authors[$item['author']] ?? $fallback);
        if (!is_user_member_of_blog($author, get_current_blog_id())) {
            throw new \RuntimeException('Mapped author no longer belongs to site.');
        }
        $report['author_mappings'][$item['author']] = $author;
        $content = $type === 'wp_global_styles' ? $item['content'] : wp_kses_post($item['content']);
        $post = [
            'post_title' => $item['title'],
            'post_content' => $content,
            'post_excerpt' => wp_kses_post($item['excerpt']),
            'post_type' => $type,
            'post_status' => in_array($item['status'], self::STATUSES, true) ? $item['status'] : 'draft',
            'post_name' => $item['slug'],
            'post_author' => $author,
            'post_password' => (string) ($item['password'] ?? ''),
            'post_date' => $item['date'],
            'post_date_gmt' => $item['date_gmt'],
            'post_modified' => $item['modified'] ?: $item['date'],
            'post_modified_gmt' => $item['modified_gmt'] ?: $item['date_gmt'],
            'menu_order' => $item['menu_order'],
            'comment_status' => $item['comment_status'] === 'open' ? 'open' : 'closed',
            'ping_status' => ($item['ping_status'] ?? '') === 'open' ? 'open' : 'closed',
        ];
        if ($type === 'attachment') {
            $dest = self::attachment($item, $dir, $post, $report);
            if (!$dest) {
                return;
            }
        } else {
            $dest = wp_insert_post(wp_slash($post), true);
            if (is_wp_error($dest)) {
                throw new \RuntimeException('Post insertion failed: '.$dest->get_error_message());
            }
            $report['imported_posts']++;
            $report['imported_by_type'][$type] = ($report['imported_by_type'][$type] ?? 0) + 1;
            if (!empty($item['sticky']) && $type === 'post') {
                stick_post((int) $dest);
            }
        }
        self::remember($source, $item['id'], (int) $dest);
        foreach ($item['meta'] as $key => $value) {
            if (Policy::safeMeta($key)) {
                update_post_meta((int) $dest, $key, wp_slash(sanitize_textarea_field($value)));
            } elseif (!in_array($key, ['_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata', '_edit_last', '_edit_lock', '_wp_old_date'], true) && !str_starts_with($key, '_menu_item_')) {
                $report['warnings'][] = 'Skipped non-portable metadata: '.$key;
            }
        }
        if ($item['link'] && $type !== 'attachment') {
            $report['url_rewrites'][$item['link']] = get_permalink((int) $dest);
        }
        foreach ($item['categories'] as $term) {
            if (in_array($term['taxonomy'], ['wp_theme', 'wp_template_part_area'], true) && in_array($type, self::THEME_TYPES, true)) {
                wp_set_object_terms((int) $dest, [$term['slug']], $term['taxonomy'], false);
                continue;
            }
            if (!in_array($term['taxonomy'], ['category', 'post_tag'], true)) {
                continue;
            }
            $exists = term_exists($term['slug'], $term['taxonomy']);
            if (!$exists) {
                $exists = wp_insert_term($term['name'] ?: $term['slug'], $term['taxonomy'], ['slug' => $term['slug']]);
                if (!is_wp_error($exists)) {
                    $report['imported_taxonomies']++;
                }
            }
            if (!is_wp_error($exists)) {
                wp_set_object_terms((int) $dest, [(int) $exists['term_id']], $term['taxonomy'], true);
            }
        }
        self::comments((int) $dest, $item['comments'], $report);
        preg_match_all('/<!--\s+wp:([a-z0-9\/-]+)/i', $item['content'], $blocks);
        foreach ($blocks[1] as $block) {
            $name = str_contains($block, '/') ? $block : 'core/'.$block;
            if (!\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                $report['unsupported_blocks'][] = $name;
            }
        }
        preg_match_all('/\[([a-zA-Z][\w-]*)(?=[\s\]\/])/', $item['content'], $shortcodes);
        foreach ($shortcodes[1] as $shortcode) {
            if (!shortcode_exists($shortcode)) {
                $report['unsupported_shortcodes'][] = $shortcode;
            }
        }
    }

    /**
     * @param list<array<string, string>> $comments
     * @param array<string, mixed> $report
     */
    private static function comments(int $post, array $comments, array &$report): void
    {
        $map = [];
        foreach ($comments as $comment) {
            $id = wp_insert_comment([
                'comment_post_ID' => $post,
                'comment_author' => sanitize_text_field($comment['author']),
                'comment_author_email' => sanitize_email($comment['email']),
                'comment_author_url' => esc_url_raw($comment['url']),
                'comment_date' => $comment['date'],
                'comment_date_gmt' => $comment['date_gmt'],
                'comment_content' => wp_kses_post($comment['content']),
                'comment_approved' => in_array($comment['approved'], ['0', '1', 'spam', 'trash'], true) ? $comment['approved'] : '0',
                'comment_type' => in_array($comment['type'], ['', 'comment', 'pingback', 'trackback'], true) ? ($comment['type'] ?: 'comment') : 'comment',
                'comment_author_IP' => '',
                'comment_agent' => '',
            ]);
            if (!$id) {
                throw new \RuntimeException('Comment insertion failed.');
            }
            $map[$comment['id']] = $id;
            $report['imported_comments']++;
        }
        foreach ($comments as $comment) {
            if (isset($map[$comment['parent']])) {
                wp_update_comment(['comment_ID' => $map[$comment['id']], 'comment_parent' => $map[$comment['parent']]]);
            }
        }
    }

    /**
     * Import an attachment from a bundled binary. Returns the new ID or 0 when missing.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $post
     * @param array<string, mixed> $report
     */
    private static function attachment(array $item, string $dir, array $post, array &$report): int
    {
        $path = self::findMedia($item, $dir, $report['inventory']['media']);
        if (!$path) {
            $report['media_missing'][$item['id']] = $item['attachment_url'];
            return 0;
        }
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';
        $tmp = wp_tempnam(basename($path));
        copy($path, $tmp);
        $file = ['name' => basename($path), 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => 0];
        $checked = Media::check($file);
        if (!empty($checked['error'])) {
            @unlink($tmp);
            $report['media_missing'][$item['id']] = $item['attachment_url'].' (rejected: '.$checked['error'].')';
            return 0;
        }
        unset($post['post_type']);
        $dest = media_handle_sideload($file, 0, $item['title'], $post);
        if (is_wp_error($dest)) {
            @unlink($tmp);
            throw new \RuntimeException('Media insertion failed: '.$dest->get_error_message());
        }
        // Large images are stored as "-scaled" copies; the untouched original must match the archive byte for byte.
        $local = wp_get_original_image_path($dest) ?: get_attached_file($dest);
        if (!$local || !is_file($local) || !hash_equals((string) hash_file('sha256', $path), (string) hash_file('sha256', $local))) {
            throw new \RuntimeException('Local media integrity check failed.');
        }
        unset($report['media_missing'][$item['id']]);
        $report['media_imported']++;
        $report['imported_by_type']['attachment'] = ($report['imported_by_type']['attachment'] ?? 0) + 1;
        $report['url_rewrites'][$item['attachment_url']] = wp_get_attachment_url($dest);
        $report['attachment_ids'][$item['attachment_url']] = (int) $dest;
        if (!empty($item['meta']['_wp_attachment_image_alt'])) {
            update_post_meta($dest, '_wp_attachment_image_alt', wp_slash(sanitize_text_field($item['meta']['_wp_attachment_image_alt'])));
        }
        return (int) $dest;
    }

    /**
     * Locate the archive file for an attachment record. Ambiguous matches are
     * treated as missing rather than guessed.
     *
     * @param array<string, mixed> $item
     * @param list<string> $media
     */
    private static function findMedia(array $item, string $dir, array $media): ?string
    {
        $urlPath = rawurldecode((string) parse_url((string) $item['attachment_url'], PHP_URL_PATH));
        $relative = $item['meta']['_wp_attached_file'] ?? preg_replace('~^.*?/(?:files|uploads)/~', '', $urlPath);
        $relative = ltrim((string) $relative, '/');
        $matches = [];
        foreach ($media as $file) {
            if ($relative && ($file === $relative || str_ends_with($file, '/'.$relative))) {
                $matches[] = $file;
            }
        }
        if (count($matches) !== 1) {
            $matches = array_values(array_filter($media, fn ($file) => basename($file) === basename($urlPath)));
        }
        return count($matches) === 1 ? $dir.'/extracted/'.$matches[0] : null;
    }

    // ---------------------------------------------------------------- finish

    /**
     * Second pass after every item exists: relationships, URL rewriting,
     * presentation state and source-independence validation.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $report
     */
    private static function finish(array $data, string $dir, array &$report): void
    {
        global $wpdb;
        $source = $data['source'];
        $host = (string) parse_url($source, PHP_URL_HOST);
        $rewrites = array_filter((array) $report['url_rewrites']);
        uksort($rewrites, fn ($a, $b) => strlen($b) <=> strlen($a));
        $report['unresolved_source_references'] = [];
        foreach ($data['items'] as $item) {
            $id = self::mapped($source, $item['id']);
            $post = $id ? get_post($id) : null;
            if (!$post || in_array($item['type'], self::DEFERRED_TYPES, true)) {
                continue;
            }
            $priorHash = get_post_meta($id, '_nbe_migration_hash', true);
            if ($priorHash && !hash_equals((string) $priorHash, hash('sha256', $post->post_content))) {
                $report['warnings'][] = 'Post '.$id.' was edited after import; automatic rewriting skipped.';
                continue;
            }
            $parent = self::mapped($source, (string) $item['parent']);
            $fields = ['post_parent' => $parent, 'post_modified' => $item['modified'] ?: $item['date'], 'post_modified_gmt' => $item['modified_gmt'] ?: $item['date_gmt']];
            $content = $post->post_content;
            if ($item['type'] !== 'wp_global_styles') {
                $content = self::rewriteContent(wp_kses_post($item['content']), $rewrites, $source, $report);
                if (Embeds::hosts()) {
                    $content = Embeds::filter($content);
                }
                $fields['post_content'] = $content;
            }
            // Write directly: wp_update_post() would overwrite the historical modification dates.
            $wpdb->update($wpdb->posts, $fields, ['ID' => $id]);
            clean_post_cache($id);
            update_post_meta($id, '_nbe_migration_hash', hash('sha256', $content));
            $featured = self::mapped($source, (string) ($item['meta']['_thumbnail_id'] ?? '0'));
            if ($featured && is_file((string) get_attached_file($featured))) {
                set_post_thumbnail($id, $featured);
            }
            $references = Policy::sourceReferences($content.' '.$post->post_excerpt, $host);
            if ($references) {
                $report['unresolved_source_references'][(string) $id] = $references;
            }
            if (preg_match('~["\'(]/files/~', $content)) {
                $report['warnings'][] = 'Post '.$id.' still contains a legacy /files/ reference with no local copy; inspect it.';
            }
        }
        self::menus($data, $report, $rewrites);
        self::customCss($data, $report);
        self::applySiteConfig($data, $report);
        $report['theme_compatibility'] = self::themeReport($data);
        foreach (['warnings', 'unsupported_blocks', 'unsupported_shortcodes', 'presentation'] as $key) {
            $report[$key] = array_values(array_unique($report[$key] ?? []));
        }
        $report['source_independence'] = $report['unresolved_source_references']
            ? 'failed: content still references the source host'
            : 'content scan passed; run `make migration-validate` for rendered/link verification';
        $report['validated_at'] = gmdate('c');
    }

    /**
     * @param array<string, string> $rewrites old URL → new URL, longest first
     * @param array<string, mixed> $report
     */
    private static function rewriteContent(string $content, array $rewrites, string $source, array &$report): string
    {
        $content = self::rewriteDerivatives($content, $report);
        $content = strtr($content, $rewrites);
        // Root-relative legacy Multisite paths ("/files/2020/05/a.jpg") are rewritten only when that binary was imported.
        foreach ($rewrites as $old => $new) {
            $path = (string) parse_url($old, PHP_URL_PATH);
            if ($path !== '' && (str_starts_with($path, '/files/') || preg_match('~^/wp-content/blogs\.dir/\d+/files/~', $path))) {
                $content = str_replace(['"'.$path, "'".$path, '('.$path], ['"'.$new, "'".$new, '('.$new], $content);
            }
        }
        $content = (string) preg_replace_callback('/\[gallery([^\]]*?)ids=["\x27]([0-9, ]+)["\x27]([^\]]*)\]/', function ($m) use ($source) {
            $ids = array_map(fn ($v) => self::mapped($source, trim($v)), explode(',', $m[2]));
            return '[gallery'.$m[1].'ids="'.implode(',', array_filter($ids)).'"'.$m[3].']';
        }, $content);
        // Block attributes and classes that carry attachment/post IDs.
        $content = (string) preg_replace_callback('/"(id|mediaId|ref|featuredImage)"\s*:\s*([0-9]+)/', function ($m) use ($source) {
            $new = self::mapped($source, $m[2]);
            return $new ? '"'.$m[1].'":'.$new : $m[0];
        }, $content);
        return (string) preg_replace_callback('/\bwp-image-([0-9]+)\b/', function ($m) use ($source) {
            $new = self::mapped($source, $m[1]);
            return $new ? 'wp-image-'.$new : $m[0];
        }, $content);
    }

    /**
     * Point references to source image derivatives ("photo-300x200.jpg") at
     * the matching local derivative, or the full-size image when no local
     * size matches.
     *
     * @param array<string, mixed> $report
     */
    private static function rewriteDerivatives(string $content, array &$report): string
    {
        $byUrl = (array) ($report['attachment_ids'] ?? []);
        if (!$byUrl) {
            return $content;
        }
        return (string) preg_replace_callback('~(?:https?:)?//[^\s"\'<>()]+-\d{1,5}x\d{1,5}\.[A-Za-z0-9]{2,5}|/files/[^\s"\'<>()]+-\d{1,5}x\d{1,5}\.[A-Za-z0-9]{2,5}~', function ($m) use ($byUrl, &$report) {
            $old = $m[0];
            $parts = Policy::derivative($old);
            if (!$parts) {
                return $old;
            }
            $original = null;
            foreach ($byUrl as $url => $id) {
                if ($url === $parts['base'] || (str_starts_with($parts['base'], '/') && str_ends_with((string) $url, $parts['base']))
                    || (str_starts_with($parts['base'], '//') && str_ends_with((string) $url, $parts['base']))) {
                    $original = (int) $id;
                    break;
                }
            }
            if (!$original) {
                return $old;
            }
            $new = (string) wp_get_attachment_url($original);
            $meta = wp_get_attachment_metadata($original);
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if ((int) $size['width'] === $parts['width'] && (int) $size['height'] === $parts['height']) {
                    $new = path_join(dirname($new), $size['file']);
                    break;
                }
            }
            $report['url_rewrites'][$old] = $new;
            return $new;
        }, $content);
    }

    /**
     * Rebuild classic navigation menus from nav_menu_item records.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $report
     * @param array<string, string> $rewrites
     */
    private static function menus(array $data, array &$report, array $rewrites): void
    {
        $source = $data['source'];
        $items = array_values(array_filter($data['items'], fn ($i) => $i['type'] === 'nav_menu_item'));
        if (!$items) {
            return;
        }
        usort($items, fn ($a, $b) => $a['menu_order'] <=> $b['menu_order']);
        $termIds = [];
        foreach ($data['terms'] as $term) {
            if ($term['id'] !== '') {
                $termIds[$term['taxonomy'].':'.$term['id']] = $term['slug'];
            }
        }
        $menus = [];
        for ($pass = 0; $pass < 2; $pass++) { // second pass resolves children listed before their parents
            foreach ($items as $item) {
                if (self::mapped($source, $item['id'])) {
                    continue;
                }
                $menuTerm = null;
                foreach ($item['categories'] as $term) {
                    if ($term['taxonomy'] === 'nav_menu') {
                        $menuTerm = $term;
                    }
                }
                if (!$menuTerm) {
                    continue;
                }
                if (!isset($menus[$menuTerm['slug']])) {
                    $menu = wp_get_nav_menu_object($menuTerm['slug']);
                    $menuId = $menu ? (int) $menu->term_id : wp_create_nav_menu($menuTerm['name'] ?: $menuTerm['slug']);
                    if (is_wp_error($menuId)) {
                        $report['warnings'][] = 'Could not create menu '.$menuTerm['slug'];
                        continue;
                    }
                    if (!$menu) {
                        $report['imported_menus']++;
                    }
                    $menus[$menuTerm['slug']] = (int) $menuId;
                }
                $meta = $item['meta'];
                $parentSource = (string) ($meta['_menu_item_menu_item_parent'] ?? '0');
                $parent = $parentSource !== '0' ? self::mapped($source, $parentSource) : 0;
                if ($parentSource !== '0' && !$parent && $pass === 0) {
                    continue;
                }
                $args = [
                    'menu-item-title' => $item['title'],
                    'menu-item-position' => $item['menu_order'],
                    'menu-item-parent-id' => $parent,
                    'menu-item-status' => 'publish',
                    'menu-item-target' => ($meta['_menu_item_target'] ?? '') === '_blank' ? '_blank' : '',
                    'menu-item-classes' => implode(' ', array_map('sanitize_html_class', (array) maybe_unserialize($meta['_menu_item_classes'] ?? ''))),
                ];
                $kind = (string) ($meta['_menu_item_type'] ?? 'custom');
                $object = (string) ($meta['_menu_item_object'] ?? '');
                $objectSource = (string) ($meta['_menu_item_object_id'] ?? '0');
                if ($kind === 'post_type') {
                    $objectId = self::mapped($source, $objectSource);
                    if (!$objectId) {
                        $report['presentation'][] = 'Menu item "'.$item['title'].'" points to content that was not imported; skipped.';
                        continue;
                    }
                    $args += ['menu-item-type' => 'post_type', 'menu-item-object' => get_post_type($objectId) ?: $object, 'menu-item-object-id' => $objectId];
                } elseif ($kind === 'taxonomy') {
                    $slug = $termIds[$object.':'.$objectSource] ?? '';
                    $term = $slug ? get_term_by('slug', $slug, $object) : null;
                    if (!$term) {
                        $report['presentation'][] = 'Menu item "'.$item['title'].'" points to a term that was not imported; skipped.';
                        continue;
                    }
                    $args += ['menu-item-type' => 'taxonomy', 'menu-item-object' => $object, 'menu-item-object-id' => (int) $term->term_id];
                } else {
                    $url = (string) ($meta['_menu_item_url'] ?? '');
                    $url = $rewrites[$url] ?? $url;
                    $args += ['menu-item-type' => 'custom', 'menu-item-url' => esc_url_raw($url)];
                }
                $new = wp_update_nav_menu_item($menus[$menuTerm['slug']], 0, $args);
                if (is_wp_error($new)) {
                    $report['warnings'][] = 'Menu item "'.$item['title'].'" could not be created.';
                    continue;
                }
                self::remember($source, $item['id'], (int) $new);
            }
        }
        if ($menus) {
            $report['presentation'][] = sprintf('%d navigation menu(s) rebuilt. Assign them to theme locations under Appearance → Menus (classic themes) or in the Navigation block (block themes) unless a NoBlogs4Ever site archive restored the locations.', count($menus));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $report
     */
    private static function customCss(array $data, array &$report): void
    {
        $curated = self::curatedThemes();
        foreach ($data['items'] as $item) {
            if ($item['type'] !== 'custom_css') {
                continue;
            }
            $theme = sanitize_key((string) $item['slug']);
            $css = (string) $item['content'];
            if (!Policy::safeCss($css)) {
                $report['presentation'][] = 'Additional CSS for theme "'.$theme.'" contains markup or script-capable constructs and was not imported.';
            } elseif (!in_array($theme, $curated, true)) {
                $report['presentation'][] = 'Additional CSS for theme "'.$theme.'" was not applied because that theme is not in the curated catalog. It remains in the original archive; adapt and paste it under Appearance → Customize → Additional CSS or Site Editor → Styles.';
            } elseif (trim(wp_get_custom_css($theme)) === '') {
                wp_update_custom_css_post($css, ['stylesheet' => $theme]);
                $report['presentation'][] = 'Additional CSS restored for theme "'.$theme.'".';
            } else {
                $report['presentation'][] = 'Theme "'.$theme.'" already had Additional CSS; the imported CSS was not merged automatically.';
            }
        }
    }

    /**
     * Restore theme, Additional CSS, menu locations and reading settings from
     * a NoBlogs4Ever full site archive.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $report
     */
    private static function applySiteConfig(array $data, array &$report): void
    {
        $config = $data['site_config'] ?? null;
        if (!$config) {
            return;
        }
        $theme = $config['theme']['stylesheet'] ?? '';
        if ($theme && in_array($theme, self::curatedThemes(), true)) {
            if (get_stylesheet() !== $theme) {
                switch_theme($theme);
                $report['presentation'][] = 'Theme set to "'.$theme.'" from the site archive.';
            }
        } elseif ($theme) {
            $report['presentation'][] = 'The archive used theme "'.$theme.'", which is not in the curated catalog. The current theme was kept; choose a curated theme under Appearance → Themes.';
        }
        if ($config['custom_css'] !== '' && Policy::safeCss($config['custom_css']) && trim(wp_get_custom_css()) === '') {
            wp_update_custom_css_post($config['custom_css']);
            $report['presentation'][] = 'Additional CSS restored from the site archive.';
        }
        $locations = [];
        foreach ($config['menu_locations'] as $location => $slug) {
            $menu = wp_get_nav_menu_object($slug);
            if ($menu && array_key_exists($location, get_registered_nav_menus())) {
                $locations[$location] = (int) $menu->term_id;
            }
        }
        if ($locations) {
            set_theme_mod('nav_menu_locations', $locations + (array) get_theme_mod('nav_menu_locations', []));
            $report['presentation'][] = 'Menu locations restored: '.implode(', ', array_keys($locations)).'.';
        }
        if ($config['show_on_front'] === 'page') {
            $front = self::mapped($data['source'], (string) $config['page_on_front']);
            $posts = self::mapped($data['source'], (string) $config['page_for_posts']);
            if ($front) {
                update_option('show_on_front', 'page');
                update_option('page_on_front', $front);
                if ($posts) {
                    update_option('page_for_posts', $posts);
                }
                $report['presentation'][] = 'Static front page restored.';
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function themeReport(array $data): array
    {
        $configTheme = $data['site_config']['theme']['stylesheet'] ?? null;
        return [
            'current_theme' => get_stylesheet(),
            'curated_catalog' => self::curatedThemes(),
            'source_theme' => $configTheme ?: 'unknown (standard WXR does not record the active theme)',
            'note' => $configTheme && !in_array($configTheme, self::curatedThemes(), true)
                ? 'The source theme is unavailable here. A curated theme was kept; expect layout, widget and font differences.'
                : 'Review the site with your chosen curated theme: widgets, theme-specific settings and fonts are not part of WXR.',
        ];
    }

    private static function notify(object $job, string $state): void
    {
        $user = get_userdata((int) $job->user_id);
        if (!$user || !is_email($user->user_email)) {
            return;
        }
        $labels = ['complete' => 'completed', 'needs_attention' => 'completed and needs your attention', 'failed' => 'failed'];
        $url = get_admin_url((int) $job->site_id, 'tools.php?page=nbe-import');
        wp_mail(
            $user->user_email,
            sprintf('[%s] Import %s', Config::brand(), $labels[$state] ?? $state),
            "Your publication import has {$labels[$state]}.\n\nReview the migration report:\n$url\n\nThe report lists imported content, missing media, unsupported content and any links that still point to the old site."
        );
    }

    // ------------------------------------------------------------- retention

    /** Remove workspaces of finished jobs after MIGRATION_RETENTION_DAYS. Source-ID maps are kept for idempotency. */
    public static function purge(): int
    {
        global $wpdb;
        $days = Config::int('MIGRATION_RETENTION_DAYS', 30, 1);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM '.self::table()." WHERE state IN ('complete','needs_attention','failed') AND updated < %s LIMIT 20", gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS)));
        foreach ($ids as $id) {
            if (!preg_match('/\A[a-f0-9-]{36}\z/', (string) $id)) {
                continue;
            }
            $dir = self::root().'/'.$id;
            if (is_dir($dir) && !is_link($dir)) {
                self::removeTree($dir);
            }
            $wpdb->delete(self::table(), ['id' => $id]);
        }
        return count($ids);
    }
}
