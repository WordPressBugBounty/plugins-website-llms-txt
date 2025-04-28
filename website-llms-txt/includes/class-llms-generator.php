<?php
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
    private $write_log;

    public function __construct()
    {
        $this->settings = get_option('llms_generator_settings', array(
            'post_types' => array('page', 'documentation', 'post'),
            'max_posts' => 100,
            'max_words' => 250,
            'include_meta' => true,
            'include_excerpts' => true,
            'include_taxonomies' => true,
            'update_frequency' => 'immediate',
            'need_check_option' => true,
        ));

        // Initialize content cleaner
        $this->content_cleaner = new LLMS_Content_Cleaner();

        // Initialize WP_Filesystem
        $this->init_filesystem();

        // Move initial generation to init hook
        add_action('init', array($this, 'init_generator'), 20);

        // Hook into post updates
        add_action('save_post', array($this, 'handle_post_update'), 10, 3);
        add_action('deleted_post', array($this, 'handle_post_deletion'), 999, 2);
        add_action('wp_update_term', array($this, 'handle_term_update'));
        add_action('llms_scheduled_update', array($this, 'llms_scheduled_update'));
        add_action('schedule_updates', array($this, 'schedule_updates'));
        add_filter('get_llms_content', array($this, 'get_llms_content'));
        add_action('init', array($this, 'llms_maybe_create_ai_sitemap_page'));
        add_action('llms_update_llms_file_cron', array($this, 'update_llms_file'));
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
        if ($this->settings['update_frequency'] !== 'immediate') {
            do_action('schedule_updates');
        }

        if (isset($_POST['llms_generator_settings'], $_POST['llms_generator_settings']['update_frequency']) || $force) {
            $this->update_llms_file();
        }
    }

    private function write_log($content)
    {
        if (!$this->write_log) {
            $upload_dir = wp_upload_dir();
            $this->write_log = $upload_dir['basedir'] . '/log.txt';
        }

        file_put_contents($this->write_log, $content, FILE_APPEND | LOCK_EX);
    }

    private function write_file($content)
    {
        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        if ($this->wp_filesystem) {
            if (!$this->llms_path) {
                $upload_dir = wp_upload_dir();
                $this->llms_path = $upload_dir['basedir'] . '/llms.txt';
            }

            file_put_contents($this->llms_path, $content, FILE_APPEND | LOCK_EX);
        }
    }

    public function get_llms_content($content)
    {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/llms.txt';
        if (file_exists($upload_path)) {
            $content .= file_get_contents($upload_path);
        }
        return $content;
    }

    public function generate_content()
    {
        $this->generate_site_info();
        $this->generate_overview();
        $this->generate_detailed_content();
    }

    private function generate_site_info()
    {
        // Try to get meta description from Yoast or RankMath
        $meta_description = $this->get_site_meta_description();
        $slug = 'ai-sitemap';
        $existing_page = get_page_by_path( $slug );
        $output = "\xEF\xBB\xBF" . "# LLMs.txt - Sitemap for AI content discovery" . "\n\n";
        if(is_a($existing_page,'WP_Post')) {
            $output .= "# Learn more:" . get_permalink($existing_page) . "\n\n";
        }
        $output .= "# " . get_bloginfo('name') . "\n\n";
        if ($meta_description) {
            $output .= "> " . $meta_description . "\n\n";
        } else {
            $output .= "> " . get_bloginfo('description') . "\n\n";
        }
        $output .= "---\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
        unset($output);
        unset($meta_description);
    }

    private function remove_shortcodes($content)
    {
        return strip_tags(preg_replace('/\[[^\]]+\]/', '', $content));
    }

    private function generate_overview()
    {
        $output = "";

        global $wpdb;

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'" ) === "{$wpdb->prefix}aioseo_posts" ) {
            add_filter('posts_where', [$this, 'exclude_noindex_nofollow_from_aioseo']);
            add_filter('posts_join', [$this, 'join_aioseo_table']);
        }

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                $output = "\n" . "## " . $post_type_obj->labels->name . "\n\n";
                $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
            }

            unset($post_type_obj);

            $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

            $paged = 1;
            $i = 0;
            do {
                $query = new WP_Query(array(
                    'post_type' => $post_type,
                    'posts_per_page' => $this->settings['max_posts'],
                    'fields' => 'ids',
                    'post_status' => 'publish',
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'paged' => $paged,
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_yoast_wpseo_meta-robots-nofollow',
                            'compare' => 'NOT EXISTS'
                        ),
                        array(
                            'relation' => 'OR',
                            array(
                                'key' => 'rank_math_robots',
                                'value' => 'noindex',
                                'compare' => 'NOT LIKE'
                            ),
                            array(
                                'key' => 'rank_math_robots',
                                'compare' => 'NOT EXISTS'
                            ),
                        ),
                    )
                ));

                if (!empty($query->posts)) {
                    foreach ($query->posts as $post_id) {
                        $post = get_post($post_id);

                        if (!$this->is_post_indexed($post)) {
                            continue;
                        }

                        $description = $this->get_post_meta_description($post);
                        if (!$description) {
                            $fallback_content = $this->remove_shortcodes(get_the_excerpt($post_id) ?: get_the_content(null, false, $post));
                            $fallback_content = $this->content_cleaner->clean($fallback_content);
                            $description = wp_trim_words(strip_tags($fallback_content), 20, '...');
                        }

                        $output = sprintf("- [%s](%s): %s\n", $post->post_title, get_permalink($post_id), $description);
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

                        unset($description, $fallback_content, $output, $post);

                        if (++$i % 50 === 0) {
                            wp_cache_flush();
                        }
                    }
                }

                $paged++;
            } while ($query->max_num_pages >= $paged);

            wp_reset_postdata();
            unset($query);
        }

        remove_filter('posts_where', [$this, 'exclude_noindex_nofollow_from_aioseo']);
        remove_filter('posts_join', [$this, 'join_aioseo_table']);

        unset($output);
        $this->write_file(mb_convert_encoding("---\n\n", 'UTF-8', 'auto'));
    }

    private function is_post_indexed($post)
    {
        // Check Rank Math
        if (class_exists('RankMath')) {
            $robots = get_post_meta($post->ID, 'rank_math_robots', true);
            if (is_array($robots) && in_array('noindex', $robots)) {
                return false;
            }
        }

        return true;
    }

    private function get_post_seo_title($post)
    {
        if (class_exists('WPSEO_Meta')) {
            $seo_title = WPSEO_Meta::get_value('title', $post->ID);
            if (!empty($seo_title)) {
                // Remove common Yoast variables and clean up
                $seo_title = str_replace(array(
                    '%%sep%%',
                    '%%sitename%%',
                    ' - ' . get_bloginfo('name'),
                    ' | ' . get_bloginfo('name'),
                    ' » ' . get_bloginfo('name')
                ), '', $seo_title);
                return trim(wpseo_replace_vars($seo_title, $post));
            }
        } elseif (class_exists('RankMath\Post\Post')) {
            $seo_title = RankMath\Post\Post::get_meta('title', $post->ID);
            if (!empty($seo_title)) {
                // Remove common RankMath variables and clean up
                $seo_title = str_replace(array(
                    '%sep%',
                    '%sitename%',
                    ' - ' . get_bloginfo('name'),
                    ' | ' . get_bloginfo('name'),
                    ' » ' . get_bloginfo('name')
                ), '', $seo_title);
                return trim(RankMath\Helper::replace_vars($seo_title, $post));
            }
        }
        return false;
    }

    public function join_aioseo_table( $join ) {
        global $wpdb;
        return $join . " LEFT JOIN {$wpdb->prefix}aioseo_posts AS aioseo ON {$wpdb->posts}.ID = aioseo.post_id ";
    }

    public function exclude_noindex_nofollow_from_aioseo( $where ) {
        return $where . " AND (aioseo.robots_noindex != 1 AND aioseo.robots_nofollow != 1 OR aioseo.post_id IS NULL)";
    }

    private function generate_detailed_content()
    {
        $output = "#\n" . "# Detailed Content\n\n";
        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));

        global $wpdb;

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}aioseo_posts'" ) === "{$wpdb->prefix}aioseo_posts" ) {
            add_filter('posts_where', [$this, 'exclude_noindex_nofollow_from_aioseo']);
            add_filter('posts_join', [$this, 'join_aioseo_table']);
        }

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $post_type_obj = get_post_type_object($post_type);
            $output = "\n" . "## " . $post_type_obj->labels->name . "\n\n";
            $this->write_file(mb_convert_encoding($output, 'UTF-8', 'auto'));
            unset($post_type_obj, $output);

            $paged = 1;
            do {
                $query = new WP_Query(array(
                    'post_type' => $post_type,
                    'posts_per_page' => $this->settings['max_posts'],
                    'post_status' => 'publish',
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'paged' => $paged,
                    'fields' => 'ids',
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_yoast_wpseo_meta-robots-nofollow',
                            'compare' => 'NOT EXISTS'
                        ),
                        array(
                            'relation' => 'OR',
                            array(
                                'key' => 'rank_math_robots',
                                'value' => 'noindex',
                                'compare' => 'NOT LIKE'
                            ),
                            array(
                                'key' => 'rank_math_robots',
                                'compare' => 'NOT EXISTS'
                            ),
                        ),
                    )
                ));

                if (!empty($query->posts)) {
                    $i = 0;
                    foreach ($query->posts as $post_id) {
                        $post = get_post($post_id);

                        if (!$this->is_post_indexed($post)) {
                            unset($post);
                            continue;
                        }

                        $content = $this->format_post_content($post);
                        $this->write_file(mb_convert_encoding($content, 'UTF-8', 'auto'));

                        unset($post, $content);

                        if (++$i % 50 === 0) {
                            wp_cache_flush();
                        }
                    }
                }

                $paged++;
            } while ($query->max_num_pages >= $paged);

            wp_reset_postdata();
            unset($query);
        }

        remove_filter('posts_where', [$this, 'exclude_noindex_nofollow_from_aioseo']);
        remove_filter('posts_join', [$this, 'join_aioseo_table']);
    }

    private function format_post_content($post)
    {
        $output = "### " . $post->post_title . "\n\n";

        if ($this->settings['include_meta']) {
            $meta_description = $this->get_post_meta_description($post);
            if ($meta_description) {
                $output .= "> " . wp_trim_words($meta_description, $this->settings['max_words'] ?? 250, '...') . "\n\n";
            }

            $output .= "- Published: " . get_the_date('Y-m-d', $post) . "\n";
            $output .= "- Modified: " . get_the_modified_date('Y-m-d', $post) . "\n";
            $output .= "- URL: " . get_permalink($post) . "\n";

            if (isset($post->post_type) && $post->post_type === 'product') {
                $sku = get_post_meta($post->ID, '_sku', true);
                if (!empty($sku)) {
                    $output .= '- SKU: ' . esc_html($sku) . "\n";
                }
            }

            if ($this->settings['include_taxonomies']) {
                $taxonomies = get_object_taxonomies($post->post_type, 'objects');
                foreach ($taxonomies as $tax) {
                    $terms = get_the_terms($post, $tax->name);
                    if ($terms && !is_wp_error($terms)) {
                        $term_names = wp_list_pluck($terms, 'name');
                        $output .= "- " . $tax->labels->name . ": " . implode(', ', $term_names) . "\n";
                    }
                }
            }
        }

        $output .= "\n";

        if ($this->settings['include_excerpts'] && !empty($post->post_excerpt)) {
            $output .= $this->remove_shortcodes($post->post_excerpt) . "\n\n";
        }

        // Clean and add the content
        $content = wp_trim_words($this->content_cleaner->clean($this->remove_shortcodes($post->post_content)), $this->settings['max_words'] ?? 250, '...');
        $output .= $content . "\n\n";
        $output .= "---\n\n";

        return $output;
    }

    private function get_site_meta_description()
    {
        if (class_exists('WPSEO_Options')) {
            return WPSEO_Options::get('metadesc');
        } elseif (class_exists('RankMath')) {
            return get_option('rank_math_description');
        }
        return false;
    }

    private function get_post_meta_description($post)
    {
        if (class_exists('WPSEO_Meta')) {
            return WPSEO_Meta::get_value('metadesc', $post->ID);
        } elseif (class_exists('RankMath')) {
            // Try using RankMath's helper class first
            if (class_exists('RankMath\Helper')) {
                $desc = RankMath\Helper::get_post_meta('description', $post->ID);
                if (!empty($desc)) {
                    return $desc;
                }
            }

            // Fallback to Post class if Helper doesn't work
            if (class_exists('RankMath\Post\Post')) {
                return RankMath\Post\Post::get_meta('description', $post->ID);
            }
        }
        return false;
    }

    public function handle_post_update($post_id, $post, $update)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!in_array($post->post_type, $this->settings['post_types'])) {
            return;
        }

        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_post_deletion($post_id, $post)
    {
        if (!$post || $post->post_type === 'revision') {
            return;
        }

        if ($this->settings['update_frequency'] === 'immediate') {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    public function handle_term_update($term_id)
    {
        if ($this->settings['update_frequency'] === 'immediate') {
            $this->update_llms_file();
        }
    }

    public function update_llms_file()
    {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'] . '/llms.txt';
        if (file_exists($upload_path)) {
            unlink($upload_path);
        }
        $this->generate_content();
        if(defined('FLYWHEEL_PLUGIN_DIR')) {
            $file_path = dirname(ABSPATH) . 'www/' . 'llms.txt';
            $file_ai_path = dirname(ABSPATH) . 'www/' . 'ai.txt';
        } else {
            $file_path = ABSPATH . 'llms.txt';
            $file_ai_path = ABSPATH . 'ai.txt';
        }

        if (file_exists($upload_path)) {
            $this->wp_filesystem->copy($upload_path, $file_path, true);
            $this->wp_filesystem->copy($upload_path, $file_ai_path, true);
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

        do_action('llms_clear_seo_caches');
    }

    public function schedule_updates()
    {
        if (!wp_next_scheduled('llms_scheduled_update')) {
            $interval = ($this->settings['update_frequency'] === 'daily') ? 'daily' : 'weekly';
            wp_schedule_event(time(), $interval, 'llms_scheduled_update');
        }
    }
}