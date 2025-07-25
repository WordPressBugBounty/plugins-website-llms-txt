<?php
/**
 * Plugin Name: Website LLMs.txt
 * Description: Manages and automatically generates LLMS.txt files for LLM/AI consumption and integrates with SEO plugins (Yoast SEO, RankMath)
 * Version: 8.0.7
 * Author: Website LLM
 * Author URI: https://wordpress.org/plugins/website-llms-txt/
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
define('LLMS_VERSION', '8.0.7');
define('LLMS_PLUGIN_FILE', __FILE__);
define('LLMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LLMS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Initialize plugin
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-md.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-core.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-crawler.php';
require_once LLMS_PLUGIN_DIR . 'includes/class-llms-cache-manager.php';

// Initialize the plugin
function llms_init() {
    new LLMS_MD();
    new LLMS_Core();
    new LLMS_Crawler();
    new LLMS_Cache_Manager();
}

// Hook the initialization function
add_action('plugins_loaded', 'llms_init');

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('llms_update_llms_file_cron');
    wp_clear_scheduled_hook('llms_scheduled_update');
    $file = ABSPATH . 'llms.txt';
    if (file_exists($file)) {
        unlink($file);
    }
});