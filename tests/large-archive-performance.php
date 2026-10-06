<?php

declare(strict_types=1);
require __DIR__.'/../app/mu-plugins/nbe/Policy.php';
require __DIR__.'/../app/mu-plugins/nbe/Archive.php';
$items = 2000;
$path = tempnam(sys_get_temp_dir(), 'nbe-perf-wxr-');
$head = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:base_site_url>https://source.example</wp:base_site_url><wp:base_blog_url>https://source.example</wp:base_blog_url>';
$handle = fopen($path, 'wb');
fwrite($handle, $head);
for ($i = 1; $i <= $items; $i++) {
    fwrite($handle, '<item><title>Item '.$i.'</title><link>https://source.example/'.$i.'</link><dc:creator>author</dc:creator><content:encoded><![CDATA[<p>Bounded content '.$i.'</p>]]></content:encoded><excerpt:encoded></excerpt:encoded><wp:post_id>'.$i.'</wp:post_id><wp:post_date>2020-01-01 00:00:00</wp:post_date><wp:post_date_gmt>2020-01-01 00:00:00</wp:post_date_gmt><wp:post_modified>2020-01-01 00:00:00</wp:post_modified><wp:post_modified_gmt>2020-01-01 00:00:00</wp:post_modified_gmt><wp:comment_status>closed</wp:comment_status><wp:status>publish</wp:status><wp:post_name>item-'.$i.'</wp:post_name><wp:post_parent>0</wp:post_parent><wp:menu_order>0</wp:menu_order><wp:post_type>post</wp:post_type></item>');
}
fwrite($handle, '</channel></rss>');
fclose($handle);
$start = hrtime(true);
$before = memory_get_peak_usage(true);
$parsed = \NBE\Archive::wxr($path);
$elapsed = (hrtime(true) - $start) / 1e9;
$peak = memory_get_peak_usage(true);
$result = ['items' => count($parsed['items']), 'input_bytes' => filesize($path), 'elapsed_seconds' => round($elapsed, 3), 'incremental_peak_bytes' => max(0, $peak - $before), 'php_memory_limit' => ini_get('memory_limit'), 'parser' => 'bounded SimpleXML; operator XML ceiling remains 128 MiB'];
unlink($path);
if ($result['items'] !== $items) {
    throw new RuntimeException('Large archive parser lost items.');
}
echo json_encode($result, JSON_UNESCAPED_SLASHES)."\n";
