<?php

// Post-migration validation for one destination site. It never fetches third-party URLs.
if (!defined('ABSPATH') || !defined('WP_CLI')) {
    throw new RuntimeException('Run through WP-CLI.');
}

/** Print an error and stop with a non-zero exit code (no stack trace). */
function nbe_cli_fail(string $message): void
{
    fwrite(STDERR, 'Error: '.$message."\n");
    exit(1);
}
$siteId = (int)(getenv('NBE_VALIDATION_SITE_ID') ?: get_current_blog_id());
$sourceHost = strtolower(trim((string)getenv('NBE_VALIDATION_SOURCE_HOST')));
if (!$siteId || !$sourceHost || !filter_var('https://'.$sourceHost, FILTER_VALIDATE_URL)) {
    nbe_cli_fail('Set NBE_VALIDATION_SITE_ID and NBE_VALIDATION_SOURCE_HOST.');
}
$allowedThirdParty = array_values(array_filter(array_map('trim', explode(',', strtolower((string)getenv('NBE_VALIDATION_ALLOWED_EMBEDS'))))));
$maxPages = min(500, max(1, (int)(getenv('NBE_VALIDATION_MAX_PAGES') ?: 100)));
switch_to_blog($siteId);
$siteHost = strtolower((string)parse_url(home_url('/'), PHP_URL_HOST));
function nbe_validation_request(string $url, string $siteHost, string $method = 'GET'): array|WP_Error
{
    if (getenv('APP_ENV') === 'production') {
        return wp_safe_remote_request($url, ['method' => $method, 'timeout' => 15, 'redirection' => 3, 'limit_response_size' => 2097152]);
    }
    // lvh.me resolves to loopback *inside* the CLI container. Route only the
    // selected tenant through the fixed app service, retaining its public Host.
    // Never route arbitrary URLs supplied by imported content to this target.
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $parts = wp_parse_url($url);
        if (!$parts || strtolower((string)($parts['host'] ?? '')) !== $siteHost) {
            return new WP_Error('nbe_validation_host', 'Validation URL is outside the selected tenant.');
        }
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $hostHeader = $siteHost.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $response = wp_remote_request('http://app'.$path.$query, ['method' => $method, 'headers' => ['Host' => $hostHeader], 'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 2097152]);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = wp_remote_retrieve_response_code($response);
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            return $response;
        }
        $location = wp_remote_retrieve_header($response, 'location');
        if (!$location) {
            return $response;
        }
        $url = $location;
    }
    return new WP_Error('nbe_validation_redirect', 'Validation exceeded its redirect limit.');
}
$report = [
    'generated_at' => gmdate('c'),
    'site_id' => $siteId,
    'site_url' => home_url('/'),
    'source_host' => $sourceHost,
    'remaining_source_references' => [],
    'legacy_files_references' => [],
    'missing_media' => [],
    'broken_internal_links' => [],
    'render_failures' => [],
    'canonical_failures' => [],
    'feed' => [],
    'intentional_third_party_embeds' => [],
    'unreviewed_third_party_embeds' => [],
    'migration_job_attention' => [],
];
global $wpdb;
foreach ($wpdb->get_results($wpdb->prepare('SELECT id,state,report FROM '.$wpdb->base_prefix.'nbe_jobs WHERE site_id=%d', $siteId)) as $job) {
    if (!in_array($job->state, ['needs_attention', 'failed'], true)) {
        continue;
    }
    $jobReport = json_decode($job->report, true) ?: [];
    $report['migration_job_attention'][] = [
        'job' => $job->id,
        'state' => $job->state,
        'missing_media' => count($jobReport['media_missing'] ?? []),
        'unsupported_post_types' => count($jobReport['unsupported_post_types'] ?? []),
        'unresolved_source_references' => count($jobReport['unresolved_source_references'] ?? []),
    ];
}
$posts = get_posts(['post_type' => ['post','page'], 'post_status' => 'publish', 'numberposts' => $maxPages, 'orderby' => 'ID', 'order' => 'ASC']);
$internal = [];
foreach ($posts as $post) {
    $combined = $post->post_content.' '.$post->post_excerpt;
    $refs = \NBE\Policy::sourceReferences($combined, $sourceHost);
    if ($refs) {
        $report['remaining_source_references'][(string)$post->ID] = $refs;
    }
    if (str_contains($combined, '/files/')) {
        $report['legacy_files_references'][] = $post->ID;
    }
    preg_match_all('~(?:href|src)=["\x27](https?://[^"\x27#]+)~i', html_entity_decode($combined, ENT_QUOTES | ENT_HTML5), $matches);
    foreach (array_unique($matches[1] ?? []) as $url) {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === $siteHost || str_ends_with($host, '.'.$siteHost)) {
            $internal[$url] = true;
        } elseif (in_array($host, $allowedThirdParty, true)) {
            $report['intentional_third_party_embeds'][$host][] = $url;
        } else {
            $report['unreviewed_third_party_embeds'][$host][] = $url;
        }
    }
    $url = get_permalink($post);
    $response = nbe_validation_request($url, $siteHost);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $report['render_failures'][] = $url;
        continue;
    }
    $body = wp_remote_retrieve_body($response);
    if (\NBE\Policy::sourceReferences($body, $sourceHost)) {
        $report['remaining_source_references']['rendered:'.$post->ID] = \NBE\Policy::sourceReferences($body, $sourceHost);
    }
    preg_match('~<link[^>]+rel=["\x27]canonical["\x27][^>]+href=["\x27]([^"\x27]+)~i', $body, $canonical);
    if (empty($canonical[1]) || strtolower((string)parse_url(html_entity_decode($canonical[1]), PHP_URL_HOST)) !== strtolower((string)parse_url($url, PHP_URL_HOST))) {
        $report['canonical_failures'][] = $url;
    }
}
foreach (array_keys($internal) as $url) {
    $response = nbe_validation_request($url, $siteHost, 'HEAD');
    $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) {
        $report['broken_internal_links'][$url] = $code;
    }
}
foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1]) as $attachment) {
    $path = get_attached_file($attachment->ID);
    $url = wp_get_attachment_url($attachment->ID);
    if (!$path || !is_file($path) || !$url || strtolower((string)parse_url($url, PHP_URL_HOST)) !== $siteHost) {
        $report['missing_media'][(string)$attachment->ID] = ['path_present' => (bool)($path && is_file($path)), 'url' => $url ?: null];
    }
}
foreach ([get_feed_link(), get_bloginfo('comments_rss2_url')] as $feed) {
    $response = nbe_validation_request($feed, $siteHost);
    $report['feed'][$feed] = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
}
foreach (['intentional_third_party_embeds','unreviewed_third_party_embeds'] as $key) {
    foreach ($report[$key] as &$urls) {
        $urls = array_values(array_unique($urls));
    }
}
$hardFailures = count($report['remaining_source_references']) + count($report['legacy_files_references']) + count($report['missing_media']) + count($report['broken_internal_links']) + count($report['render_failures']) + count($report['canonical_failures']) + count($report['migration_job_attention']) + count(array_filter($report['feed'], fn ($code) => $code !== 200));
$report['result'] = $hardFailures ? 'needs_attention' : ($report['unreviewed_third_party_embeds'] ? 'passed_with_embed_review_required' : 'passed');
echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
restore_current_blog();
if ($hardFailures) {
    WP_CLI::halt(1);
}
