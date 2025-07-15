<?php
if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Core {
    /** @var LLMS_Generator */
    private $generator;

    public function __construct()
    {
        // Register activation hook
        register_activation_hook(LLMS_PLUGIN_FILE, array($this, 'activate'));

        // Initialize core functionality
        add_action('init', array($this, 'init'), 0);

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(LLMS_PLUGIN_FILE), array($this, 'add_settings_link'));

        // Handle cache clearing
        add_action('admin_post_clear_caches', array($this, 'handle_cache_clearing'));

        // Initialize SEO integrations before post type registration
        add_action('init', array($this, 'init_seo_integrations'), -1);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'llms_ai_banner_dismissed'));

        // Add required scripts for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        add_action('wp_head', array($this, 'wp_head'));

        add_action('all_admin_notices', array($this, 'all_admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notice_script'));
        add_action('wp_ajax_dismiss_llms_admin_notice', array($this, 'dismiss_llms_admin_notice'));
        add_action('wp_ajax_dismiss_llms_ai_banner_dismissed', array($this, 'dismiss_llms_ai_banner_dismissed'));
    }
    public function dismiss_llms_ai_banner_dismissed() {
        check_ajax_referer('llms_dismiss_notice', 'nonce');
        update_user_meta(get_current_user_id(), 'llms_ai_banner_dismissed', 1);
        wp_send_json_success();
    }
    public function llms_ai_banner_dismissed() {
        if (get_user_meta(get_current_user_id(), 'llms_ai_banner_dismissed', true)) return;
        $how_it_works_url = admin_url('tools.php?page=llms-file-manager&tab=how-it-works');
        echo '<div class="notice notice-info is-dismissible llms-ai-banner">
            <p><strong>' . esc_html__('AI Crawler Detection is here!','website-llms-txt') . '</strong> — 
            <a href="' . esc_url($how_it_works_url) . '">' . esc_html__('How it works','website-llms-txt') . '</a></p>
        </div>';
    }

    public function all_admin_notices() {
        if (get_user_meta(get_current_user_id(), 'llms_notice_dismissed', true)) {
            return;
        }
        ?>
        <div class="notice updated is-dismissible llms-admin-notice">
            <p><?php esc_html_e('Website LLMs.txt - Want new features? Suggest and vote to shape our plugin development roadmap.', 'website-llms-txt'); ?>
                <a href="https://x.com/ryhowww/status/1909712881387462772" target="_blank"><?php esc_html_e('Twitter', 'website-llms-txt'); ?></a> |
                <a href="https://wordpress.org/support/?post_type=topic&p=18406423"><?php esc_html_e('WP Forums', 'website-llms-txt'); ?></a>
            </p>
        </div>
        <?php
    }

    public function enqueue_notice_script() {
        wp_enqueue_script('llms-notice-script', LLMS_PLUGIN_URL . 'admin/notice-dismiss.js', array('jquery'), LLMS_VERSION, true);
        wp_localize_script('llms-notice-script', 'llmsNoticeAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('llms_dismiss_notice')
        ));
    }

    public function dismiss_llms_admin_notice() {
        check_ajax_referer('llms_dismiss_notice', 'nonce');
        update_user_meta(get_current_user_id(), 'llms_notice_dismissed', 1);
        wp_send_json_success();
    }

    public function wp_head() {
        echo '<link rel="llms-sitemap" href="' . esc_url( home_url( '/llms.txt' ) ) . '" />' . "\n";
    }

    public function get_llms_post() {
        $posts = get_posts(array(
            'post_type' => 'llms_txt',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ));

        return !empty($posts) ? $posts[0] : null;
    }

    public function init() {
        // Register post type
        $this->create_post_type();
        // Initialize generator after post type
        require_once LLMS_PLUGIN_DIR . 'includes/class-llms-generator.php';
        $this->generator = new LLMS_Generator();

        // Add rewrite rules
        $this->add_rewrite_rule();
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_llms_request'));
    }

    public function create_post_type() {
        register_post_type('llms_txt', array(
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_admin_bar' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => array('title', 'editor'),
            'exclude_from_sitemap' => true
        ));
    }

    public function init_seo_integrations() {
        if (class_exists('RankMath')) {
            require_once LLMS_PLUGIN_DIR . 'includes/class-llms-provider.php';
            require_once LLMS_PLUGIN_DIR . 'includes/rank-math.php';
        }

        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps')) {
            require_once LLMS_PLUGIN_DIR . 'includes/yoast.php';
        }
    }

    public function register_settings() {
        register_setting(
            'llms_generator_settings',
            'llms_generator_settings',
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => array(
                    'post_types' => array('page', 'documentation', 'post'),
                    'max_posts' => 100,
                    'max_words' => 250,
                    'include_meta' => false,
                    'include_excerpts' => false,
                    'include_taxonomies' => false,
                    'update_frequency' => 'immediate',
                    'need_check_option' => true,
                    'llms_allow_indexing' => false,
                    'llms_local_log_enabled' => false,
                    'llms_global_telemetry_optin' => false,
                    'include_md_file' => false,
                    'detailed_content' => false,
                    'llms_txt_title' => '',
                    'llms_txt_description' => '',
                    'llms_after_txt_description' => '',
                    'llms_end_file_description' => ''
                )
            )
        );
    }

    public function sanitize_settings($value) {
        global $wpdb;
        if (!is_array($value)) {
            return array();
        }
        $clean = array();

        $settings = $this->generator->get_llms_generator_settings();
        
        // Ensure post_types is an array and contains only valid post types
        $clean['post_types'] = array();
        if (isset($value['post_types']) && is_array($value['post_types'])) {
            $valid_types = get_post_types(array('public' => true));
            foreach ($value['post_types'] as $type) {
                if (in_array($type, $valid_types) && $type !== 'attachment' && $type !== 'llms_txt') {
                    $clean['post_types'][] = sanitize_key($type);
                }
            }
        }
        
        // Sanitize max posts
        $clean['max_posts'] = isset($value['max_posts']) ? 
            min(max(absint($value['max_posts']), 1), 100000) : 100;

        // Sanitize max posts
        $clean['max_words'] = isset($value['max_words']) ?
            min(max(absint($value['max_words']), 1), 100000) : 250;
        
        // Sanitize boolean values
        $clean['llms_allow_indexing'] = !empty($value['llms_allow_indexing']);
        $clean['llms_local_log_enabled'] = !empty($value['llms_local_log_enabled']);
        $clean['llms_global_telemetry_optin'] = !empty($value['llms_global_telemetry_optin']);
        $clean['include_meta'] = !empty($value['include_meta']);
        $clean['noindex_header'] = !empty($value['noindex_header']);
        $clean['include_excerpts'] = !empty($value['include_excerpts']);
        $clean['include_taxonomies'] = !empty($value['include_taxonomies']);
        $clean['llms_txt_title'] = !isset($value['llms_txt_title']) ? '' : $value['llms_txt_title'];
        $clean['llms_txt_description'] = !isset($value['llms_txt_description']) ? '' : $value['llms_txt_description'];
        $clean['llms_after_txt_description'] = !isset($value['llms_after_txt_description']) ? '' : $value['llms_after_txt_description'];
        $clean['llms_end_file_description'] = !isset($value['llms_end_file_description']) ? '' : $value['llms_end_file_description'];
        $clean['include_md_file'] = !empty($value['include_md_file']);
        $clean['detailed_content'] = !empty($value['detailed_content']);

        if(
            ($clean['include_excerpts'] != $settings['include_excerpts']) ||
            ($clean['include_md_file'] != $settings['include_md_file']) ||
            ($clean['include_taxonomies'] != $settings['include_taxonomies']) ||
            ($clean['detailed_content'] != $settings['detailed_content']) ||
            ($clean['include_meta'] != $settings['include_meta'])
        ) {
            $table_cache = $wpdb->prefix . 'llms_txt_cache';
            $wpdb->query("TRUNCATE " . $table_cache);
        }

        // Sanitize update frequency
        $clean['update_frequency'] = isset($value['update_frequency']) && 
            in_array($value['update_frequency'], array('immediate', 'daily', 'weekly')) ? 
            sanitize_key($value['update_frequency']) : 'immediate';

        return $clean;
    }

    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['tools_page_llms-file-manager', 'toplevel_page_llms-file-manager'])) {
            return;
        }

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue admin styles with dashicons dependency
        wp_enqueue_style(
            'llms-admin-styles',
            LLMS_PLUGIN_URL . 'admin/admin-styles.css',
            array('dashicons'),
            LLMS_VERSION
        );

        // Register and enqueue admin script
        wp_register_script(
            'llms-admin-script',
            LLMS_PLUGIN_URL . 'admin/admin-script.js',
            array('jquery', 'jquery-ui-sortable'),
            LLMS_VERSION,
            true
        );

        wp_enqueue_script('llms-admin-script');
    }

    public function activate() {
        flush_rewrite_rules();
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Llms.txt',
            'Llms.txt',
            'manage_options',
            'llms-file-manager',
            array($this, 'render_admin_page')
        );
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="admin.php?page=llms-file-manager">' . __('Settings', 'website-llms-txt') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function render_admin_page() {
        include LLMS_PLUGIN_DIR . 'admin/admin-page.php';
    }

    public function handle_cache_clearing() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('clear_caches', 'clear_caches_nonce');
        do_action('llms_clear_seo_caches');
        $this->add_rewrite_rule();
        flush_rewrite_rules();

        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'llms_txt_cache';
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        wp_clear_scheduled_hook('llms_update_llms_file_cron');
        wp_schedule_single_event(time() + 2, 'llms_update_llms_file_cron');


        wp_safe_redirect(add_query_arg(array(
            'page' => 'llms-file-manager',
            'cache_cleared' => 'true',
            '_wpnonce' => wp_create_nonce('llms_cache_cleared')
        ), admin_url('admin.php')));
        exit;
    }

    public function add_rewrite_rule() {
        global $wp_rewrite;

        if($wp_rewrite) {
            $wp_rewrite->add_rule('llms.txt', 'index.php?llms_txt=1', 'top');
        }
    }

    public function add_query_vars($vars) {
        $vars[] = 'llms_txt';
        return $vars;
    }

    public function handle_llms_request() {
        $settings = apply_filters('get_llms_generator_settings', []);
        $request_uri = isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] ? trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/') : '';

        if ($request_uri === 'llms.txt') {
            $disable_noindex = $settings['noindex_header'] ?? '';
            if ( !$disable_noindex ) {
                header('X-Robots-Tag: noindex');
            }
        }

        if (get_query_var('llms_txt')) {
            $latest_post = apply_filters('get_llms_content', '');
            if ($latest_post) {
                header('Content-Type: text/plain');
                echo esc_html($latest_post);
                exit;
            }
        }
    }
}