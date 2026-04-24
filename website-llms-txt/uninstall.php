<?php
// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
delete_option('llms_generator_settings');
delete_option('llms_local_log');
delete_option('llms_bot_hits_today');
delete_option('llms_site_log_enabled_status');
delete_option('vk_embed_token');
delete_option('vk_client_id');
delete_option('vk_connected_email');
delete_option('vk_summary_data');
delete_transient('vk_summary_fresh');

// Initialize WP_Filesystem
global $wp_filesystem;
require_once(ABSPATH . 'wp-admin/includes/file.php');
WP_Filesystem();

// Delete root llms.txt — handle Flywheel split directory layout
if (defined('FLYWHEEL_PLUGIN_DIR')) {
    $root_file = trailingslashit(dirname(ABSPATH)) . 'www/llms.txt';
} else {
    $root_file = ABSPATH . 'llms.txt';
}
if (file_exists($root_file)) {
    if ($wp_filesystem && $wp_filesystem->exists($root_file)) {
        $wp_filesystem->delete($root_file);
    } else {
        wp_delete_file($root_file);
    }
}

// Delete uploads copies ({domain}.llms.txt and {domain}.temp.llms.txt)
$siteurl = get_option('siteurl');
if ($siteurl) {
    $domain = wp_parse_url($siteurl, PHP_URL_HOST);
    if ($domain) {
        $upload_dir = wp_upload_dir();
        $basedir = $upload_dir['basedir'];
        $files_to_delete = array(
            $basedir . '/' . $domain . '.llms.txt',
            $basedir . '/' . $domain . '.temp.llms.txt',
            $basedir . '/llms.txt', // legacy path
        );
        foreach ($files_to_delete as $file) {
            if (file_exists($file)) {
                if ($wp_filesystem && $wp_filesystem->exists($file)) {
                    $wp_filesystem->delete($file);
                } else {
                    wp_delete_file($file);
                }
            }
        }
    }
}

// Delete all posts of type llms_txt
$posts = get_posts([
    'post_type' => 'llms_txt',
    'posts_per_page' => -1,
    'post_status' => 'any'
]);

foreach ($posts as $post) {
    wp_delete_post($post->ID, true);
}

// Flush rewrite rules to remove the llms.txt rule from the database
flush_rewrite_rules();