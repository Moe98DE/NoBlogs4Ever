<?php

/**
 * Development demo data: two independent sites, every editorial role,
 * posts, a page, an image, nested comments, discovery and analytics.
 *
 *   make seed      (wp eval-file /opt/nbe/scripts/seed.php)
 *
 * Every demo account uses the password in secrets/demo_password.
 */
if (getenv('APP_ENV') === 'production') {
    throw new RuntimeException('Demo seed is prohibited in production.');
}
$pw = trim((string) file_get_contents(getenv('NBE_DEMO_PASSWORD_FILE') ?: '/run/secrets/demo_password'));
if (strlen($pw) < 20) {
    throw new RuntimeException('Set a private demo password file.');
}
require_once ABSPATH.'wp-admin/includes/image.php';

$users = [];
foreach (['alice', 'bob', 'editor', 'author', 'contributor', 'subscriber'] as $login) {
    $id = username_exists($login) ?: wp_create_user($login, $pw, $login.'@example.invalid');
    if (is_wp_error($id)) {
        throw new RuntimeException('Cannot seed user '.$login);
    }
    $users[$login] = (int) $id;
}

$sites = ['garden' => ['owner' => 'alice', 'theme' => 'twentytwentyfive', 'title' => 'Community Garden'], 'journal' => ['owner' => 'bob', 'theme' => 'twentytwentyone', 'title' => 'Field Journal']];
foreach ($sites as $slug => $spec) {
    $domain = $slug.'.'.DOMAIN_CURRENT_SITE;
    $id = domain_exists($domain, '/') ?: wpmu_create_blog($domain, '/', $spec['title'], $users[$spec['owner']], ['public' => 1]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    switch_to_blog((int) $id);
    switch_theme($spec['theme']);
    if (!get_option('nbe_seeded')) {
        update_option('blogdescription', $slug === 'garden' ? 'Notes from the allotment' : 'Observations, slowly');
        $category = wp_insert_term($slug === 'garden' ? 'Seasons' : 'Walks', 'category');
        $categoryId = is_wp_error($category) ? 0 : (int) $category['term_id'];
        $upload = wp_upload_bits('cover.png', null, (function (): string {
            $img = imagecreatetruecolor(1200, 630);
            imagefill($img, 0, 0, imagecolorallocate($img, 46, 125, 50));
            ob_start();
            imagepng($img);
            return (string) ob_get_clean();
        })());
        $attachment = 0;
        if (empty($upload['error'])) {
            $attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Cover', 'post_status' => 'inherit'], $upload['file']);
            wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $upload['file']));
            update_post_meta($attachment, '_wp_attachment_image_alt', 'A green field');
        }
        $post = wp_insert_post([
            'post_title' => $slug === 'garden' ? 'A place to publish together' : 'First walk of autumn',
            'post_content' => '<!-- wp:paragraph --><p>Welcome to our shared publication. Write, revise, and make something worth keeping.</p><!-- /wp:paragraph -->',
            'post_status' => 'publish',
            'post_author' => $users[$spec['owner']],
            'post_category' => $categoryId ? [$categoryId] : [],
            'tags_input' => ['welcome'],
        ]);
        if ($attachment) {
            set_post_thumbnail($post, $attachment);
        }
        $first = wp_insert_comment(['comment_post_ID' => $post, 'comment_author' => 'A reader', 'comment_content' => 'Looking forward to the next article.', 'comment_approved' => 1]);
        wp_insert_comment(['comment_post_ID' => $post, 'comment_author' => 'Another reader', 'comment_content' => 'Me too!', 'comment_approved' => 1, 'comment_parent' => $first]);
        wp_insert_post(['post_title' => 'About', 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $users[$spec['owner']], 'post_content' => '<!-- wp:paragraph --><p>Who we are.</p><!-- /wp:paragraph -->']);
        wp_insert_post(['post_title' => 'Work in progress', 'post_status' => 'draft', 'post_author' => $users[$spec['owner']]]);
        update_option('nbe_discoverable', 1);
        update_option('nbe_analytics', 1);
        update_option('nbe_seeded', 1);
    }
    restore_current_blog();
    \NBE\Discovery::setListed((int) $id, true);
    if ($slug === 'garden') {
        foreach (['editor', 'author', 'contributor', 'subscriber'] as $role) {
            add_user_to_blog((int) $id, $users[$role], $role);
        }
    }
}
\NBE\Discovery::rebuild();
echo "Seeded: sites garden and journal; users alice, bob, editor, author, contributor, subscriber (password: secrets/demo_password).\n";
