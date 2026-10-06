<?php

/** Print the URL of the imported fixture post (used by the browser tests). Development only. */
if (getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Fixture lookup is development only');
}
global $wpdb;
foreach ($wpdb->get_col('SELECT site_id FROM '.$wpdb->base_prefix."nbe_jobs WHERE state IN ('complete','needs_attention') ORDER BY created DESC") as $site) {
    switch_to_blog((int) $site);
    $posts = get_posts(['name' => 'story-101', 'post_status' => 'publish', 'numberposts' => 1]);
    restore_current_blog();
    if ($posts) {
        switch_to_blog((int) $site);
        echo get_permalink($posts[0])."\n";
        restore_current_blog();
        return;
    }
}
throw new RuntimeException('No imported fixture found; run make integration first.');
