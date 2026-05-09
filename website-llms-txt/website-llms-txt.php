<?php
/**
 * Plugin Name: Website LLMs.txt
 * Description: Generates and manages an llms.txt file, a structured, AI-ready index that helps large language models like ChatGPT, Claude, and Perplexity understand your site's most important content.
 * Version: 8.4.0
 * Author: Ryan Howard
 * Author URI: https://completeseo.com/author/ryan-howard/
 * Text Domain: website-llms-txt
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LLMS_VERSION', '8.4.0');
define('LLMS_PLUGIN_FILE', __FILE__);
define('LLMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LLMS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-md.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-core.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-crawler.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-cache-manager.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-visibilitykit.php';

// Initialize the plugin
function llms_init() {
    new LLMS_MD();
    new LLMS_Core();
    new LLMS_Crawler();
    new LLMS_Cache_Manager();
    new LLMS_VisibilityKit();
}

// Hook the initialization function
add_action('plugins_loaded', 'llms_init');

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('llms_update_llms_file_cron');
    wp_clear_scheduled_hook('llms_scheduled_update');

    // Delete root llms.txt — handle Flywheel split directory layout
    if (defined('FLYWHEEL_PLUGIN_DIR')) {
        $root_file = trailingslashit(dirname(ABSPATH)) . 'www/llms.txt';
    } else {
        $root_file = ABSPATH . 'llms.txt';
    }
    if (file_exists($root_file)) {
        wp_delete_file($root_file);
    }

    // Delete uploads copies ({domain}.llms.txt and {domain}.temp.llms.txt)
    $siteurl = get_option('siteurl');
    if ($siteurl) {
        $domain = wp_parse_url($siteurl, PHP_URL_HOST);
        if ($domain) {
            $upload_dir = wp_upload_dir();
            $basedir = $upload_dir['basedir'];
            $uploads_file = $basedir . '/' . $domain . '.llms.txt';
            $uploads_temp = $basedir . '/' . $domain . '.temp.llms.txt';
            if (file_exists($uploads_file)) {
                wp_delete_file($uploads_file);
            }
            if (file_exists($uploads_temp)) {
                wp_delete_file($uploads_temp);
            }
        }
    }

    flush_rewrite_rules();
});