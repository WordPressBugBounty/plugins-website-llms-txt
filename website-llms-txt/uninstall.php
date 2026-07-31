<?php
// Exit if not called by WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options
// llms_db_version must go with them. Leaving it behind would tell a later
// reinstall that the schema ladder had already run, and the steps would be
// skipped against a database that no longer has the table.
delete_option('llms_db_version');
delete_option('llms_db_condition');
delete_option('llms_rebuild_pending');
delete_option('llms_artifact_stamp');
// The two lease locks. Neither should outlive the request that took it, and
// both do when that request is killed rather than unwound: an OOM or a
// max_execution_time kill during a generation leaves llms_generation_lock
// behind, and the same during the artifact cleanup leaves llms_artifact_lock.
// A reinstall would then wait out LLMS_Lock::STALE_AFTER before it could
// generate or clean anything, for no reason anybody could see.
delete_option('llms_generation_lock');
delete_option('llms_artifact_lock');
delete_option('llms_generator_settings');
// The two records the settings screen reads back: when the file was last built,
// and what the last run left out of it. Both are this plugin's, both are written
// on every install that has ever generated anything, and neither was removed
// until now. Found by running this file against a real install and listing what
// survived it. Neither name belongs to LifterLMS, which also writes llms_*
// options into the same table; checked against 10.0.10 before adding them.
delete_option('llms_last_generated');
delete_option('llms_last_excluded');
delete_option('llms_local_log');
delete_option('llms_bot_hits_today');
delete_option('llms_site_log_enabled_status');
delete_option('vk_embed_token');
delete_option('vk_client_id');
delete_option('vk_connected_email');
delete_option('vk_summary_data');
delete_transient('vk_summary_fresh');

// The schema ladder's lease locks go with the version. A site uninstalled while
// an exclusive step was running leaves llms_db_version_step_N behind, and a
// later reinstall then has to wait out LLMS_Lock::STALE_AFTER before it can run
// that step. Names come from LLMS_DB::option_name() . '_step_' . N, so this
// pattern has to change if that does. The underscores are escaped because they
// are LIKE wildcards.
//
// Single site, like every delete_option() above it, and like the DROP TABLE
// below. See the note there for why the network loop is not being added here.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like('llms_db_version_step_') . '%'
));

// The cache table goes with the options. It is the plugin's own table, it holds
// a copy of every post's content, and until now nothing ever removed it: a site
// that installed the plugin once was left carrying that copy for good.
//
// Two things worth knowing about this file, because neither is obvious and both
// leave the table behind:
//
//  - It only runs on delete through the admin, Plugins -> Delete. Removing the
//    plugin directory over FTP or SSH, or having a host's file manager do it,
//    never runs it, and the table is then orphaned with no path back to it. That
//    is WordPress behaviour and there is nothing the plugin can do about it.
//  - Like every delete_option() above, this is the current site only. On a
//    network, deleting the plugin runs this once and each other site keeps its
//    own wp_N_llms_txt_cache. Looping the network here is the obvious fix and is
//    deliberately not being made in 8.5.4: uninstall runs in one request with no
//    resumption, and a get_sites() loop doing a DROP, a rewrite flush and a
//    wp_delete_post() sweep per site is exactly the shape that times out half way
//    on a large network and leaves a worse mess than the one it cleans up.
$wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}llms_txt_cache`");

// Initialize WP_Filesystem
global $wp_filesystem;
require_once(ABSPATH . 'wp-admin/includes/file.php');
WP_Filesystem();

// Delete root llms.txt, handling the Flywheel split directory layout
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