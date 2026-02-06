<?php

use RankMath\Helper;
use RankMath\Paper\Paper;

if (!defined('ABSPATH')) {
    exit;
}

require_once LLMS_PLUGIN_DIR . 'includes/class-llms-content-cleaner.php';

class LLMS_Generator
{
    private $settings;
    private $content_cleaner;
    private $wp_filesystem;
    private $llms_path;
    private $write_log_path;
    private $llms_name;
    private $limit = 500;
    // New property for temporary file path
    private $temp_llms_path;
    private $batch_size = 5;

    public function __construct()
    {
        $this->settings = get_option('llms_generator_settings', array(
            'post_types' => array('page', 'documentation', 'post'),
            'post_name' => array(),
            'max_posts' => 100,
            'max_words' => 250,
            'include_meta' => false,
            'include_excerpts' => false,
            'include_taxonomies' => false,
            'update_frequency' => 'immediate',
            'need_check_option' => true,
            'noindex_header' => false,
            'gform_include' => false,
            'llms_allow_indexing' => false,
            'llms_local_log_enabled' => false,
            'llms_global_telemetry_optin' => false,
            'include_md_file' => false,
            'detailed_content' => false,
            'llms_txt_title' => '',
            'llms_txt_description' => '',
            'llms_after_txt_description' => '',
            'llms_end_file_description' => ''
        ));

        // Initialize content cleaner
        $this->content_cleaner = new LLMS_Content_Cleaner();

        // Initialize WP_Filesystem
        $this->init_filesystem();

        // Move initial generation to init hook
        add_action('init', array($this, 'init_generator'), 20);

        add_action('wp_ajax_run_llms_txt_reset_file',  [$this, 'ajax_reset_gen_init']);
        add_action('wp_ajax_llms_gen_init',  [$this, 'ajax_gen_init']);
        add_action('wp_ajax_llms_gen_step',  [$this, 'ajax_gen_step']);
        add_action('wp_ajax_llms_update_file',  [$this, 'ajax_update_file']);

        // Hook into post updates
        add_action('save_post', array($this, 'handle_post_update'), 10, 3);
        add_action('deleted_post', array($this, 'handle_post_deletion'), 999, 2);
        add_action('wp_update_term', array($this, 'handle_term_update'));
        add_action('llms_scheduled_update', array($this, 'llms_scheduled_update'));
        add_action('schedule_updates', array($this, 'schedule_updates'));
        add_filter('get_llms_content', array($this, 'get_llms_content'));
        add_action('init', array($this, 'llms_maybe_create_ai_sitemap_page'));
        add_action('llms_update_llms_file_cron', array($this, 'update_llms_file'));
        add_action('admin_post_run_manual_update_llms_file', array($this, 'run_manual_update_llms_file'));
        add_action('init', array($this, 'llms_create_txt_cache_table_if_not_exists'), 999);
        add_action('updates_all_posts', array($this, 'updates_all_posts'), 999);
        add_filter('get_llms_generator_settings', array($this, 'get_llms_generator_settings'));
        add_action('single_llms_generator_hook', array($this, 'single_llms_generator_hook'));
    }

    public function ajax_update_file(){
        if(!current_user_can('manage_options')) wp_send_json_error('denied');
        check_ajax_referer('llms_gen_nonce');
        $this->update_llms_file();
        wp_send_json_success();
    }

    public function clean_html_text( $html ) {
        $text = wp_strip_all_tags( $html );
        $text = preg_replace( '/\s{2,}/', ' ', $text );
        $text = preg_replace( '/^\s*$(\r\n|\n|\r)/m', '', $text );
        $text = trim( $text );
        return $text;
    }

    public function single_llms_generator_hook( $post_id )
    {
        global $wpdb;
        $post_url = get_permalink( $post_id );

        if ( ! $post_url ) {
            return;
        }

        $parsed   = parse_url( $post_url );
        $host     = $parsed['host'] ?? '';

        $response = wp_remote_get( $post_url, [
            'timeout' => 10,
            'sslverify' => false,
            'headers' => [
                'Host' => $host
            ]
        ]);

        if ( is_wp_error( $response ) ) {
            return;
        }

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) {
            return;
        }

        $text = $this->clean_html_text( $html );
        $table = $wpdb->prefix . 'llms_txt_cache';
        $wpdb->update($table, [
            'content' => $text,
        ], [
            'post_id' => $post_id,
        ], [
            '%s',
        ], [
            '%d'
        ]);
    }

    public function run_manual_update_llms_file()
    {
        set_time_limit(0);
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        check_admin_referer('generate_llms_txt_nonce');
        $this->update_llms_file();
        wp_safe_redirect(admin_url('tools.php?page=llms-file-manager'));
        exit;
    }

    public function get_llms_generator_settings( $settings = [] )
    {
        return $this->settings;
    }

    public function llms_create_txt_cache_table_if_not_exists()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'llms_txt_cache';
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        ));

        if ($table_exists !== $table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $table (
                `post_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                `show` TINYINT NULL DEFAULT NULL,
                `status` VARCHAR(20) DEFAULT NULL,
                `type` VARCHAR(20) DEFAULT NULL,
                `title` TEXT DEFAULT NULL,
                `link` VARCHAR(255) DEFAULT NULL,
                `sku` VARCHAR(255) DEFAULT NULL,
                `price` VARCHAR(125) DEFAULT NULL,
                `excerpts` TEXT DEFAULT NULL,
                `overview` TEXT DEFAULT NULL,
                `meta` TEXT DEFAULT NULL,
                `content` LONGTEXT DEFAULT NULL,
                `published` DATETIME DEFAULT NULL,
                `modified` DATETIME DEFAULT NULL
            ) $charset_collate;";

            dbDelta($sql);
        }
    }

    public function llms_maybe_create_ai_sitemap_page()
    {
        if (!isset($this->settings['removed_ai_sitemap']))
        {
            $page = get_page_by_path('ai-sitemap');
            if ($page && $page->post_type === 'page')
            {
                wp_delete_post($page->ID, true);
                $this->settings['removed_ai_sitemap'] = true;
                update_option('llms_generator_settings', $this->settings);
            }
        }
    }

    public function llms_scheduled_update()
    {
        $this->init_generator(true);
    }

    private function init_filesystem()
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        $this->wp_filesystem = $wp_filesystem;
    }

    public function init_generator($force = false)
    {

        $siteurl = get_option('siteurl');
        if($siteurl) {
            $this->llms_name = parse_url($siteurl)['host'];
        }

        if (isset($this->settings['update_frequency']) && $this->settings['update_frequency'] !== 'immediate') {
            do_action('schedule_updates');
        }

        if (isset($_POST['llms_generator_settings'], $_POST['llms_generator_settings']['update_frequency']) || $force) {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    /**
     * Writes the content to a log file using WP_Filesystem.
     *
     * @param string $content Content for recording.
     */
    private function write_log($content)
    {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        if ($this->wp_filesystem) {
            if (!$this->write_log_path) {
                $upload_dir = wp_upload_dir();
                $this->write_log_path = $upload_dir['basedir'] . '/log.txt';
            }

            // Use append mode if supported, otherwise read, append and write.
            // For log, it's less critical for memory as logs generally don't become *that* large
            // and frequent reads/writes are less performance sensitive than the main LLMS file.
            if ($this->wp_filesystem->exists($this->write_log_path)) {
                $current_content = $this->wp_filesystem->get_contents($this->write_log_path);
                $this->wp_filesystem->put_contents($this->write_log_path, $current_content . $content, FS_CHMOD_FILE);
            } else {
                $this->wp_filesystem->put_contents($this->write_log_path, $content, FS_CHMOD_FILE);
            }
        }
    }

    /**
     * Writes the content to an LLMS file using WP_Filesystem,
     * with an optimized approach for large files.
     *
     * @param string $content Content for recording.
     */
    private function write_file($content)
    {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        if ($this->wp_filesystem) {
            if (!$this->temp_llms_path) {
                $upload_dir = wp_upload_dir();
                $this->temp_llms_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.temp.llms.txt';
            }

            // Attempt to write using native PHP functions if direct method is available
            // This is more memory efficient for large files as it appends without reading the whole file
            if ($this->wp_filesystem->method == 'direct') {
                $file_handle = @fopen($this->temp_llms_path, 'a'); // 'a' for append mode, creates file if it doesn't exist
                if ($file_handle) {
                    @fwrite($file_handle, (string)$content);
                    @fclose($file_handle);
                    // Set permissions as fopen doesn't handle them
                    $this->wp_filesystem->chmod($this->temp_llms_path, FS_CHMOD_FILE, false);
                } else {
                    // Fallback to WP_Filesystem's put_contents if fopen fails (e.g., permissions)
                    if ($this->wp_filesystem->exists($this->temp_llms_path)) {
                        $current_content = $this->wp_filesystem->get_contents($this->temp_llms_path);
                        $this->wp_filesystem->put_contents($this->temp_llms_path, $current_content . (string)$content, FS_CHMOD_FILE);
                    } else {
                        $this->wp_filesystem->put_contents($this->temp_llms_path, (string)$content, FS_CHMOD_FILE);
                    }
                }
            } else {
                // If not direct method, use WP_Filesystem's put_contents (which involves read+write for append)
                // This will still have memory issues for extremely large files, but is the only option for non-direct methods.
                if ($this->wp_filesystem->exists($this->temp_llms_path)) {
                    $current_content = $this->wp_filesystem->get_contents($this->temp_llms_path);
                    $this->wp_filesystem->put_contents($this->temp_llms_path, $current_content . (string)$content, FS_CHMOD_FILE);
                } else {
                    $this->wp_filesystem->put_contents($this->temp_llms_path, (string)$content, FS_CHMOD_FILE);
                }
            }
        }
    }

    public function get_llms_content($content)
    {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        $upload_temp_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.temp.llms.txt';
        if ($this->wp_filesystem && $this->wp_filesystem->exists($upload_path)) {
            $content .= $this->wp_filesystem->get_contents($upload_path);
        } elseif($this->wp_filesystem && $this->wp_filesystem->exists($upload_temp_path)) {
            $content .= $this->wp_filesystem->get_contents($upload_temp_path);
        }
        return $content;
    }

    public function updates_all_posts()
    {
        global $wpdb;
        $table_cache = $wpdb->prefix . 'llms_txt_cache';
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Processing type: ' . $post_type);
            }

            $offset = 0;
            do {
                $params = [$post_type];
                $params[] = $this->limit;
                $params[] = $offset;
                $params = [$post_type, $this->limit, $offset];
                $conditions = "WHERE p.post_type = %s AND cache.post_id IS NULL";
                $joins = " LEFT JOIN {$table_cache} cache ON p.ID = cache.post_id ";
                $posts = $wpdb->get_results($wpdb->prepare("SELECT p.ID, cache.* FROM {$wpdb->posts} p $joins $conditions ORDER BY p.post_date DESC LIMIT %d OFFSET %d", ...$params));

                if (!empty($posts)) {
                    foreach ($posts as $cache_post) {
                        if(!$cache_post->post_id) {
                            $post = get_post($cache_post->ID);
                            if(function_exists('wpml_object_id_filter')) {
                                $lang = apply_filters('wpml_element_language_code', null, [
                                    'element_id' => $post->ID,
                                    'element_type' => 'post_' . $post->post_type
                                ]);

                                if ($lang) {
                                    do_action('wpml_switch_language', $lang);
                                }
                            }
                            $this->handle_post_update($cache_post->ID, $post, 'manual');
                            if(function_exists('wpml_object_id_filter')) {
                                wp_reset_postdata();
                            }
                            unset($post);
                        }
                    }
                }

                $offset = $offset + $this->limit;
            } while (!empty($posts));

            if(function_exists('wpml_object_id_filter')) {
                do_action('wpml_switch_language', apply_filters('wpml_default_language', null));
            }

            unset($posts);

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('END processing type: ' . $post_type);
            }
        }
    }

    public function generate_content()
    {
        $this->updates_all_posts();
        $this->generate_site_info();
        $this->generate_overview();
        $this->generate_detailed_content();
    }

    private function generate_site_info()
    {
        // Try to get meta description from Yoast or RankMath

        $settings = apply_filters('get_llms_generator_settings', []);
        if(isset($settings['llms_txt_description']) && $settings['llms_txt_description']) {
            $meta_description = $settings['llms_txt_description'];
        } else {
            $meta_description = $this->get_site_meta_description();
        }
        $slug = 'ai-sitemap';
        $existing_page = get_page_by_path( $slug );
        $output = "\xEF\xBB\xBF"; // UTF-8 BOM
        if(is_a($existing_page,'WP_Post')) {
            $output .= "# Learn more:" . get_permalink($existing_page) . "\n\n";
        }
        $output .= "# " . (isset($settings['llms_txt_title']) && $settings['llms_txt_title'] ? $settings['llms_txt_title'] : get_bloginfo('name')) . "\n\n";
        if ($meta_description) {
            $output .= "> " . $meta_description . "\n\n";
        }

        if (isset($settings['llms_after_txt_description']) && $settings['llms_after_txt_description']) {
            $output .= "> " . $settings['llms_after_txt_description'] . "\n\n";
        }
        $output .= "---\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
        unset($output);
        unset($meta_description);
    }

    private function remove_shortcodes($content)
    {
        $settings = apply_filters('get_llms_generator_settings', []);
        $clean = preg_replace('/\[[^\]]+\]/', '', $content);

        if(!isset($settings['gform_include']) || !$settings['gform_include']) {
            $clean = preg_replace('/<form[^>]+id=("|\')gform_\d+("|\')[\s\S]*?<\/form>/i', '', $clean);

            $clean = preg_replace('/<div[^>]+class=("|\')[^"\']*gform_wrapper[^"\']*("|\')[\s\S]*?<\/div>/i', '', $clean);
        }

        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $clean);
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $clean);

        $clean = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', $clean);

        $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $clean = preg_replace('/[ \t]+/', ' ', $clean);
        $clean = preg_replace('/\s{2,}/u', ' ', $clean);
        $clean = preg_replace('/[\r\n]+/', "\n", $clean);

        return trim(strip_tags($clean));
    }

    private function generate_overview()
    {
        global $wpdb;
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start generate overview');
        }

        $table_cache = $wpdb->prefix . 'llms_txt_cache';
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {

                $name = $post_type_obj->labels->name;
                if(isset($this->settings['post_name'][$post_type_obj->labels->name]) && $this->settings['post_name'][$post_type_obj->labels->name]) {
                    $name = $this->settings['post_name'][$post_type_obj->labels->name];
                }

                $this->write_file(mb_convert_encoding("\n## {$name}\n\n", 'UTF-8', 'UTF-8'));
            }

            $offset = 0;
            $i = 0;
            $exit = false;

            do {
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $posts = $wpdb->get_results($wpdb->prepare("SELECT `post_id`, `overview` FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::log('Count: ' . count($posts));
                    \WP_CLI::log($wpdb->prepare("SELECT `post_id`, `overview` FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                }
                if (!empty($posts)) {
                    $output = '';
                    foreach ($posts as $data) {
                        if($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if($data->overview) {
                            $output .= $data->overview;
                            $i++;
                        }

                        unset($data);
                    }

                    $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                    unset($output);
                }

                $offset += $this->limit;

            } while (!empty($posts) && !$exit);

            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'UTF-8'));

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate overview');
            }
        }
    }

    private function generate_detailed_content()
    {
        global $wpdb;

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start generate detailed content');
        }

        if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
            $output = "#\n" . "# Detailed Content\n\n";
            $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
        }

        $table_cache = $wpdb->prefix . 'llms_txt_cache';

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                $post_type_obj = get_post_type_object($post_type);
                if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                    $name = $post_type_obj->labels->name;
                    if(isset($this->settings['post_name'][$post_type_obj->labels->name]) && $this->settings['post_name'][$post_type_obj->labels->name]) {
                        $name = $this->settings['post_name'][$post_type_obj->labels->name];
                    }
                    $output = "\n## " . $name . "\n\n";
                    $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                }
            }

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Generate detailed: ' . $post_type);
            }

            $offset = 0;
            $exit = false;
            $i = 0;

            do {
                $output = '';
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $posts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_cache $conditions ORDER BY `published` DESC LIMIT %d OFFSET %d", ...$params));
                if (!empty($posts)) {
                    foreach ($posts as $data) {
                        if (!$data->content) continue;
                        if ($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if (isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                            if ($data->meta) {
                                $output .= "> " . wp_trim_words($data->meta, $this->settings['max_words'] ?? 250, '...') . "\n\n";
                            }

                            $output .= "- Published: " . esc_html(date('Y-m-d', strtotime($data->published))) . "\n";
                            $output .= "- Modified: " . esc_html(date('Y-m-d', strtotime($data->modified))) . "\n";
                            $output .= "- URL: " . esc_html($data->link) . "\n";

                            if ($data->sku) {
                                $output .= '- SKU: ' . esc_html($data->sku) . "\n";
                            }

                            if ($data->price) {
                                $output .= '- Price: ' . esc_html($data->price) . "\n";
                            }
                        }

                        if (isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies']) {
                            $taxonomies = get_object_taxonomies($data->type, 'objects');
                            foreach ($taxonomies as $tax) {
                                $terms = get_the_terms($data->post_id, $tax->name);
                                if ($terms && !is_wp_error($terms)) {
                                    $term_names = wp_list_pluck($terms, 'name');
                                    $output .= "- " . $tax->labels->name . ": " . implode(', ', $term_names) . "\n";
                                }
                            }
                        }

                        $content = '';
                        if (isset($this->settings['detailed_content']) && $this->settings['detailed_content']) {
                            $content = wp_trim_words($data->content, $this->settings['max_words'] ?? 250, '...');
                            $output .= "\n";
                        }


                        if (isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] && $data->excerpts) {
                            $output .= $data->excerpts . "\n\n";
                        }

                        if ($content) {
                            $output .= $content . "\n\n";
                        }

                        if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                            $output .= "---\n\n";
                        }
                        unset($data);

                        $i++;
                    }
                }

                if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                    $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                }
                unset($output);

                $offset += $this->limit;

            } while (!empty($posts) && !$exit);

            if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'UTF-8'));
            }

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate detailed content');
            }
        }

        $settings = apply_filters('get_llms_generator_settings', []);
        if (isset($settings['llms_end_file_description']) && $settings['llms_end_file_description']) {
            $this->write_file(mb_convert_encoding('> ' . $settings['llms_end_file_description'] . "\n\n", 'UTF-8', 'UTF-8'));
            $this->write_file(mb_convert_encoding("\n---\n\n", 'UTF-8', 'UTF-8'));
        }
    }

    public function remove_emojis($text) {
        return preg_replace('/[\x{1F600}-\x{1F64F}'
            . '\x{1F300}-\x{1F5FF}'
            . '\x{1F680}-\x{1F6FF}'
            . '\x{1F1E0}-\x{1F1FF}'
            . '\x{2600}-\x{26FF}'
            . '\x{2700}-\x{27BF}'
            . '\x{FE00}-\x{FE0F}'
            . '\x{1F900}-\x{1F9FF}'
            . '\x{1F018}-\x{1F270}'
            . '\x{238C}-\x{2454}'
            . '\x{20D0}-\x{20FF}]/u', '', $text);
    }

    private function get_site_meta_description()
    {
        $description = get_bloginfo('description');
        if ($description) {
            return get_bloginfo('description');
        } else {
            $front_page_id = get_option('page_on_front');
            $description = '';
            if ($front_page_id) {
                $description = get_the_excerpt($front_page_id);
                if (empty($description)) {
                    $description = get_post_field('post_content', $front_page_id);
                }
            }

            $description = $this->remove_shortcodes(str_replace(']]>', ']]&gt;', apply_filters('the_content', $description)));
            $description = wp_trim_words(strip_tags(preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', html_entity_decode($description))), 30, '');
        }

        return apply_filters('llms_generator_get_site_meta_description', $description);
    }

    private function get_post_meta_description( $post )
    {
        $meta_description = apply_filters('llms_generator_get_post_meta_description', false, $post);
        if($meta_description) {
            return $meta_description;
        }

        return $meta_description;
    }

    public function ajax_reset_gen_init() {
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Permission denied');
        check_ajax_referer('llms_gen_nonce');

        global $wpdb;
        $table_cache = $wpdb->prefix . 'llms_txt_cache';

        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        $upload_dir = wp_upload_dir();
        $old_upload_path = $upload_dir['basedir'] . '/llms.txt';
        $new_upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        $this->temp_llms_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.temp.llms.txt';

        if ($this->wp_filesystem->exists($old_upload_path)) {
            $this->wp_filesystem->delete($old_upload_path);
        }
        if ($this->wp_filesystem->exists($new_upload_path)) {
            $this->wp_filesystem->delete($new_upload_path);
        }
        if ($this->wp_filesystem->exists($this->temp_llms_path)) {
            $this->wp_filesystem->delete($this->temp_llms_path);
        }

        $wpdb->query( "TRUNCATE TABLE {$table_cache}" );

        $ids = [];
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;
            $sql = $wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$table_cache} c ON p.ID=c.post_id WHERE p.post_type=%s AND c.post_id IS NULL", $post_type);
            $ids = array_merge($ids, array_map('intval', $wpdb->get_col($sql)));
        }
        $ids = array_values(array_unique($ids));

        $qid = 'llms_q_' . wp_generate_uuid4();
        set_transient($qid, [
            'ids'   => $ids,
            'done'  => 0,
            'total' => count($ids),
        ], HOUR_IN_SECONDS);

        wp_send_json_success(['queue_id'=>$qid,'total'=>count($ids)]);
    }

    public function ajax_gen_init() {
        if ( ! current_user_can('manage_options') ) wp_send_json_error('Permission denied');
        check_ajax_referer('llms_gen_nonce');

        global $wpdb;
        $table_cache = $wpdb->prefix . 'llms_txt_cache';

        $ids = [];
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;
            $sql = $wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$table_cache} c ON p.ID=c.post_id WHERE p.post_type=%s AND c.post_id IS NULL", $post_type);
            $ids = array_merge($ids, array_map('intval', $wpdb->get_col($sql)));
        }
        $ids = array_values(array_unique($ids));

        $qid = 'llms_q_' . wp_generate_uuid4();
        set_transient($qid, [
            'ids'   => $ids,
            'done'  => 0,
            'total' => count($ids),
        ], HOUR_IN_SECONDS);

        wp_send_json_success(['queue_id'=>$qid,'total'=>count($ids)]);
    }

    private function get_remote_title( int $post_id ): string {
        $url = get_permalink( $post_id );
        if ( ! $url ) {
            return '';
        }

        $parsed = parse_url( $url );
        $host   = $parsed['host'] ?? '';

        $resp = wp_remote_get( $url, [
            'timeout'   => 12,
            'sslverify' => false,
            'headers'   => [
                'Host'        => $host,
                'User-Agent'  => 'LLMS-Generator/1.0 (+'. home_url('/') .')',
                'Accept'      => 'text/html',
            ],
        ] );

        if ( is_wp_error( $resp ) ) {
            return '';
        }

        $html = wp_remote_retrieve_body( $resp );
        if ( ! $html ) {
            return '';
        }

        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            $title = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $title = wp_strip_all_tags( $title );
            $title = trim( preg_replace( '/\s+/', ' ', $title ) );

            return $title;
        }

        return '';
    }

    public function ajax_gen_step()
    {
        set_time_limit(0);
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        check_ajax_referer('llms_gen_nonce');

        $qid = sanitize_text_field($_POST['queue_id'] ?? '');
        if (!$qid) wp_send_json_error('Missing queue_id');

        $state = get_transient($qid);
        if (!$state) {
            wp_send_json_success([
                'done' => 0,
                'total' => 0
            ]);
        }

        $batch = array_splice($state['ids'], 0, $this->batch_size);

        foreach ($batch as $post_id) {
            $post = get_post($post_id);
            if ($post instanceof WP_Post) {
                $this->handle_post_update($post_id, $post, 'manual');
            }
            $state['done']++;
        }

        set_transient($qid, $state, HOUR_IN_SECONDS);
        if (empty($state['ids'])) {
            delete_transient($qid);
            $this->update_llms_file();
        }

        wp_send_json_success([
            'done' => $state['done'],
            'total' => $state['total']
        ]);
    }

    /**
     * @param int $post_id
     * @param WP_Post $post
     * @param $update
     * @return void
     */
    public function handle_post_update($post_id, $post, $update)
    {
        global $wpdb, $product;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!in_array($post->post_type, $this->settings['post_types'])) {
            return;
        }

        if (function_exists('wc_get_product') &&  ! $product && $post instanceof WP_Post ) {
            $product = wc_get_product( $post->ID );
        }

        $table = $wpdb->prefix . 'llms_txt_cache';
        $price = '';
        $sku = '';

        if(defined('ICL_LANGUAGE_CODE')) {
            $permalink = apply_filters(
                'wpml_permalink',
                get_permalink($post->ID),
                ICL_LANGUAGE_CODE
            );
        } else {
            $permalink = get_permalink($post->ID);
        }

        $description = isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] ? $this->get_post_meta_description( $post ) : '';
        $markdown = '';
        $md_toggle = get_post_meta( $post->ID, '_llmstxt_page_md', true );

        $md_url = get_post_meta( $post->ID, '_md_url', true );
        if ( ! empty( $md_url ) ) {
            $markdown = " → [Markdown](" . esc_url( $md_url ) . ")";
        }

        if (!$description) {
            if($this->settings['include_excerpts']) {
                $fallback_content = $this->remove_shortcodes(apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ?: get_the_content(null, false, $post));
                $fallback_content = $this->content_cleaner->clean($fallback_content);
                $description = wp_trim_words(strip_tags($fallback_content), 20, '...');
            }

            $overview = sprintf("- [%s](%s)%s\n", $post->post_title, $permalink, $markdown . ($this->settings['include_excerpts'] && $description ? ': ' . preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $description) : ''));
        } else {
            $overview = sprintf("- [%s](%s)%s\n", $post->post_title, $permalink, $markdown . ($this->settings['include_excerpts'] ? ': ' . preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $description) : ''));
        }

        $show = 1;
        if (isset($post->post_type) && $post->post_type === 'product') {
            $sku = get_post_meta($post->ID, '_sku', true);
            $price = get_post_meta($post->ID, '_price', true);
            $currency = get_option('woocommerce_currency');
            if (!empty($price)) {
                $price = number_format((float)$price, 2) . " " . $currency;
            }

            $terms           = get_the_terms( $post->ID, 'product_visibility' );
            $term_names      = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
            $exclude_search  = in_array( 'exclude-from-search', $term_names, true );
            $exclude_catalog = in_array( 'exclude-from-catalog', $term_names, true );

            if ( $exclude_search && $exclude_catalog ) {
                $show = 0;
            } elseif ( $exclude_search ) {
                $show = 0;
            } elseif ( $exclude_catalog ) {
                $show = 0;
            }

        }

        $clean_description = '';
        $meta_description = $this->get_post_meta_description( $post );
        if ($meta_description) {
            $clean_description = preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', $meta_description);
        }

        $use_yoast = class_exists('WPSEO_Meta');
        $use_rankmath = function_exists('rank_math');
        if($use_yoast) {
            $robots_noindex = get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
            $robots_nofollow = get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);
            if($robots_noindex || $robots_nofollow) {
                $show = 0;
            }
        } else {
            if(defined('SEOPRESS_VERSION')) {
                $robots_noindex = get_post_meta($post_id, '_seopress_robots_index', true);
                $robots_nofollow = get_post_meta($post_id, '_seopress_robots_follow', true);
                if($robots_noindex || $robots_nofollow) {
                    $show = 0;
                }
            }
        }

        $title = $post->post_title;

        if ($use_rankmath) {
            rank_math()->variables->setup();
            $robots_noindex = get_post_meta($post_id, 'rank_math_robots', true);
            $rank_math_title = get_post_meta($post_id, 'rank_math_title', true);
            $title = Helper::replace_vars( $rank_math_title, $post );

            if(is_array($robots_noindex) && (in_array('nofollow', $robots_noindex) || in_array('noindex', $robots_noindex))) {
                $show = 0;
            }
        } else {
            $remote_title = $this->get_remote_title( $post->ID );
            if ( $remote_title !== '' ) {
                $title = $remote_title;
            }
        }

        $aioseo_enabled = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'") === "{$wpdb->prefix}aioseo_posts";
        if($aioseo_enabled) {
            $row = $wpdb->get_row("SELECT robots_noindex, robots_nofollow FROM {$wpdb->prefix}aioseo_posts WHERE post_id=" . intval($post_id));
            if(isset($row->robots_noindex) && $row->robots_noindex) {
                $show = 0;
            }

            if(isset($row->robots_nofollow) && $row->robots_nofollow) {
                $show = 0;
            }
        }

        $excerpts = $this->remove_shortcodes($post->post_excerpt);
        $custom_txt = get_post_meta($post->ID, '_llmstxt_custom_note', true);
        if($custom_txt) {
            $content = $custom_txt;
        } else {
            ob_start();
            echo $this->content_cleaner->clean($this->remove_emojis( $this->remove_shortcodes(do_shortcode(get_the_content(null, false, $post)))));
            $content = ob_get_clean();
        }

        if ( $md_toggle ) {
            $show = 0;
        }

        $template = get_post_meta( $post->ID, '_wp_page_template', true );
        if ( $template && $template !== 'default' && !trim($content)) {

            $hook      = 'single_llms_generator_hook';
            $post_id   = $post->ID;
            $timestamp = wp_next_scheduled( $hook, [ $post_id ] );

            if ( ! $timestamp ) {
                wp_schedule_single_event( time() + 10, $hook, [ $post_id ] );
            }
        }

        $replace_data = [
            'post_id' => $post_id,
            'show' => $show,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'title' => $title,
            'link' => $permalink,
            'sku' => $sku,
            'price' => $price,
            'meta' => $clean_description,
            'excerpts' => $excerpts,
            'overview' => $overview,
            'content' => $content,
            'published' => get_the_date('Y-m-d', $post),
            'modified' => get_the_modified_date('Y-m-d', $post),
        ];

        $replace_format = [
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        ];

        $replace_data = apply_filters('llms_handle_post_update_replace_data', $replace_data, $post);
        $replace_format = apply_filters('llms_handle_post_update_replace_format', $replace_format, $post);

        $wpdb->replace(
            $table,
            $replace_data,
            $replace_format
        );

        if ($this->settings['update_frequency'] === 'immediate' && $update !== 'manual') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_post_deletion($post_id, $post)
    {
        global $wpdb;
        if (!$post || $post->post_type === 'revision') {
            return;
        }

        $table = $wpdb->prefix . 'llms_txt_cache';
        $wpdb->delete($table, [
            'post_id' => $post_id
        ], [
            '%d'
        ]);

        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_term_update($term_id)
    {
        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function update_llms_file()
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start');
        }

        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        $upload_dir = wp_upload_dir();
        $old_upload_path = $upload_dir['basedir'] . '/llms.txt';
        $new_upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        $this->temp_llms_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.temp.llms.txt';

        // Delete existing files using WP_Filesystem
        if ($this->wp_filesystem->exists($old_upload_path)) {
            $this->wp_filesystem->delete($old_upload_path);
        }
        if ($this->wp_filesystem->exists($new_upload_path)) {
            $this->wp_filesystem->delete($new_upload_path);
        }
        // Ensure the temporary file is deleted before starting new generation
        if ($this->wp_filesystem->exists($this->temp_llms_path)) {
            $this->wp_filesystem->delete($this->temp_llms_path);
        }


        $file_path = '';
        if (defined('FLYWHEEL_PLUGIN_DIR')) {
            $file_path = trailingslashit(dirname(ABSPATH)) . 'www/' . 'llms.txt';
        } else {
            $file_path = trailingslashit(ABSPATH) . 'llms.txt';
        }

        // Delete existing root file using WP_Filesystem
        if ($this->wp_filesystem->exists($file_path)) {
            $this->wp_filesystem->delete($file_path);
        }

        $this->generate_content();

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('End generate_content event');
        }

        if (!is_multisite()) {
            // After generation, rename/move the temporary file to the final destination
            if ($this->wp_filesystem->exists($this->temp_llms_path)) {
                // Ensure the final destination file is removed before moving the temp file
                if ($this->wp_filesystem->exists($new_upload_path)) {
                    $this->wp_filesystem->delete($new_upload_path);
                }
                $this->wp_filesystem->move($this->temp_llms_path, $new_upload_path, true);

                // Copy the generated file to the root directory if not multisite
                if ($this->wp_filesystem->exists($new_upload_path)) {
                    $this->wp_filesystem->copy($new_upload_path, $file_path, true);
                }
            }
        }

        // Update the hidden post
        $core = new LLMS_Core();
        $existing_post = $core->get_llms_post();

        $post_data = array(
            'post_title' => 'LLMS.txt',
            'post_content' => 'content',
            'post_status' => 'publish',
            'post_type' => 'llms_txt'
        );

        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            wp_update_post($post_data);
        } else {
            wp_insert_post($post_data);
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Clear cache');
        }

        do_action('wpseo_cache_clear_sitemap');
        do_action('llms_clear_seo_caches_rank_math');
    }

    public function schedule_updates()
    {
        if (!wp_next_scheduled('llms_scheduled_update')) {
            $interval = ($this->settings['update_frequency'] === 'daily') ? 'daily' : 'weekly';
            wp_schedule_event(time(), $interval, 'llms_scheduled_update');
        }
    }
}