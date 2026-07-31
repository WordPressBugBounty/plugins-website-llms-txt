<?php

use RankMath\Helper;
use RankMath\Paper\Paper;

if (!defined('ABSPATH')) {
    exit;
}

// WEBSITE_LLMS_TXT_DIR, never LLMS_PLUGIN_DIR. LifterLMS defines LLMS_PLUGIN_DIR too,
// guarded by !defined(), so whichever plugin loads first owns the name and the other one
// requires out of the wrong directory. require_once on a missing file is fatal, not a
// warning, so that is a white screen on every request. The legacy name is still defined
// for third parties by the compatibility shim on plugins_loaded:0, but it points at
// LifterLMS on a site where LifterLMS loaded first, and this file is required from
// init:0, which is later.
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-content-cleaner.php';

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

    /**
     * The generation lock.
     *
     * One option name for the whole site, claimed for the length of an
     * update_llms_file() run. LLMS_Lock is atomic where add_option() is not, and the
     * race it closes is two runs appending to the same temp file, which is the
     * mechanism behind the 21 byte file in the original reproduction.
     */
    private $lock_name = 'llms_generation_lock';

    /** @var string Current lease token, empty when this instance holds no lock. */
    private $lock_token = '';

    /**
     * @var bool Whether this run's lease was stolen while it was running.
     *
     * Kept apart from the token because it changes what the failure path is allowed to
     * touch: a run that lost its lease is no longer the authority over any of these
     * files and must not delete the run that took over.
     */
    private $lock_lost = false;

    /**
     * @var bool Whether the shutdown handler is registered for this instance.
     *
     * register_shutdown_function() has no unregister, so the handler is registered at
     * most once and decides what to do from the two flags below.
     */
    private $shutdown_registered = false;

    /**
     * @var bool Whether a generation is in flight whose end state is not yet decided.
     *
     * True from the moment a run starts producing a document until the moment both
     * destinations have been settled, either by a completed promote or by the removal
     * on the failure path. Only while this is true may the shutdown handler unlink
     * anything, and that window is what makes the file integrity invariant hold for a
     * failure that never unwinds.
     */
    private $generation_armed = false;

    /** @var string[] The destinations a run that does not finish has to remove. */
    private $shutdown_remove = array();

    /**
     * @var bool Whether this instance is inside update_llms_file() right now.
     *
     * There is one LLMS_Generator per request and update_llms_file() is reachable
     * from a public action, from save_post through wp_update_post(), and from
     * every admin entry point, so a third party hook can re-enter it while a run
     * is in flight. Re-entry used to clear the running run's lease token before
     * discovering it could not have the lock, which left the outer run unable to
     * refresh or release its own lease and orphaned the generation lock for the
     * whole 300 second steal window: adversarial F4. The guard is here rather
     * than only in the lock, because it says what is actually true and holds even
     * where LLMS_Lock is not loaded.
     */
    private $running = false;

    public function __construct()
    {
        /*
         * Every key this class reads has a default, and the defaults are applied to a
         * stored option as well as to a missing one.
         *
         * They used to be the second argument to get_option(), which WordPress only
         * uses when the option does not exist at all, so a stored option missing a key
         * meant an undefined index. Two shapes of that are live: three unguarded reads
         * of include_excerpts, which are a PHP 8 warning on every post save, and
         * handle_post_update()'s in_array($post->post_type, $this->settings
         * ['post_types']), which on PHP 8 is a TypeError on null and therefore a fatal
         * on save_post, taking the editor down with it. A partial option is not
         * hypothetical: an importer, a migration tool or an older version of this
         * plugin's own settings screen can write one.
         *
         * wp_parse_args is not recursive, which is what is wanted here: post_types is
         * either the site's list or the default list, never a merge of the two.
         */
        $llms_settings_defaults = array(
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
            'include_md_file' => false,
            'detailed_content' => false,
            'llms_txt_title' => '',
            'llms_txt_description' => '',
            'llms_after_txt_description' => '',
            'llms_end_file_description' => ''
        );

        $llms_stored_settings = get_option('llms_generator_settings', array());

        $this->settings = wp_parse_args(
            is_array($llms_stored_settings) ? $llms_stored_settings : array(),
            $llms_settings_defaults
        );

        // Six places iterate this and one passes it to in_array(). A stored value of
        // the wrong type is corruption rather than a setting, and it is answered the
        // same way a missing one is.
        if (!is_array($this->settings['post_types'])) {
            $this->settings['post_types'] = $llms_settings_defaults['post_types'];
        }

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

        // Priority 11, after LLMS_Core::handle_llms_request(), which serves the document
        // and exits when there is one. See the method for why this exists at all.
        add_action('template_redirect', array($this, 'llms_txt_absent_response'), 11);
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

    /**
     * Re-read one post's rendered page over HTTP and cache the text.
     *
     * Not gated, and that is deliberate rather than an oversight. The fetch is
     * structurally anonymous: wp_remote_get() sends no cookies and no authentication,
     * so what it stores is exactly what an anonymous visitor is served, which is the
     * same ground truth the access gate is measured against. A post an anonymous
     * visitor cannot read stores that refusal page rather than its content, and the
     * gate in the read queries decides separately whether the row is emitted at all.
     *
     * Nothing in this plugin fires the action. It is a hook a site can schedule.
     *
     * @param int $post_id
     * @return void
     */
    public function single_llms_generator_hook( $post_id )
    {
        global $wpdb;
        $post_url = get_permalink( $post_id );

        if ( ! $post_url ) {
            return;
        }

        $parsed   = wp_parse_url( $post_url );
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
        // The table definition and every later change to it live in LLMS_DB, so
        // that an install which already has the table can still receive schema
        // updates. Creating the table is step 1 of that ladder.
        LLMS_DB::maybe_upgrade();
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

    /**
     * Take a WP_Filesystem transport, or nothing at all.
     *
     * The property is null unless there is a transport that can actually be used, so
     * every "if (!$this->wp_filesystem)" guard in this class means what it says.
     *
     * A truthy global is not evidence of anything. WP_Filesystem() constructs the
     * transport, assigns it to $GLOBALS['wp_filesystem'], and only then returns false if
     * the constructor recorded an error or connect() failed. So on a host with
     * FS_METHOD set to ftpext, ssh2 or ftpsockets and no working credentials, which is
     * the ordinary shape of a locked-down or half-configured host, the global holds an
     * unconnected object. 8.5.3 took it, and every ->exists() call on it throws:
     *
     *   TypeError: ftp_nlist(): Argument #1 ($ftp) must be of type FTP\Connection,
     *   null given, in class-wp-filesystem-ftpext.php:438
     *
     * Reproduced on WordPress 7.0.2 and PHP 8.5.4 by defining FS_METHOD as ftpext with
     * no credentials, which is one constant in a mu-plugin.
     *
     * Both failure shapes land in the transport's own WP_Error: the constructor adds
     * no_ftp_ext or a missing-credential code, and connect() adds "connect". Reading
     * that is what makes this correct whether or not this call is the one that built
     * the transport, which matters because another plugin may have populated the global
     * before us.
     *
     * @return void
     */
    private function init_filesystem()
    {
        global $wp_filesystem;

        $this->wp_filesystem = null;

        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            if (!WP_Filesystem()) {
                return;
            }
        }

        if (empty($wp_filesystem) || !is_object($wp_filesystem)) {
            return;
        }

        if (isset($wp_filesystem->errors)
            && is_wp_error($wp_filesystem->errors)
            && $wp_filesystem->errors->has_errors()) {
            return;
        }

        $this->wp_filesystem = $wp_filesystem;
    }

    public function init_generator($force = false)
    {

        $siteurl = get_option('siteurl');
        if($siteurl) {
            $this->llms_name = wp_parse_url($siteurl)['host'];
        }

        if (isset($this->settings['update_frequency']) && $this->settings['update_frequency'] !== 'immediate') {
            do_action('schedule_updates');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only flag check; nonce is verified by WP core's options.php handler before settings are persisted.
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

        // Only the promoted copy is ever served.
        //
        // There used to be a fallback to {domain}.temp.llms.txt here. The temp file is
        // the file a generation run appends to, so it is by definition incomplete until
        // the run finishes, and serving it is exactly the partial document the file
        // integrity invariant exists to prevent: a run that dies half way used to leave
        // a truncated file being served as if it were the whole thing. Absent is a
        // permitted state for this document, partial is not.
        //
        // update_llms_file() now promotes on multisite as well, so removing this
        // fallback does not leave a network serving nothing.
        if ($this->wp_filesystem && $this->wp_filesystem->exists($upload_path)) {
            $content .= $this->wp_filesystem->get_contents($upload_path);
        }
        return $content;
    }

    /**
     * Answer /llms.txt with 404 when there is no document.
     *
     * Absent is a legitimate state in 8.5.4 and was not really one before it: the file
     * integrity invariant says a run leaves the complete output or nothing, the
     * migration removes the 8.5.3 file before anything rebuilds it, and a run that
     * cannot finish now removes both copies. So "there is no document right now" is
     * something the plugin says on purpose, and it has to be legible from outside.
     *
     * What it did instead was fall through to WordPress, which resolved the rewrite rule
     * to nothing in particular and rendered the site's home page. Measured: HTTP 200,
     * text/html, 51,573 bytes of the front page at a URL whose whole contract is a plain
     * text document. A crawler cannot tell that from a document, an uptime check cannot
     * tell it from success, and anybody diffing the file gets the home page.
     *
     * 404 rather than 503. 503 says the resource exists and to come back later, and on a
     * fully gated site the document does exist as a header only file, so absence here
     * really does mean not present rather than not ready.
     *
     * This belongs next to LLMS_Core::handle_llms_request(), which is the handler that
     * serves the document, and it is here because that file is not mine to edit this
     * round. It does not depend on that handler's ordering: it asks for the content
     * itself and returns if there is any, so it is correct whether that handler runs
     * before it, is moved, or is unhooked.
     *
     * @return void
     */
    public function llms_txt_absent_response()
    {
        if (!get_query_var('llms_txt')) {
            return;
        }

        if ('' !== (string) apply_filters('get_llms_content', '')) {
            return;
        }

        status_header(404);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo esc_html("llms.txt has not been generated on this site yet.\n");
        exit;
    }

    /**
     * Fill the cache table for every post that has no row yet.
     *
     * Known defect, deliberately not fixed in 8.5.4. The WHERE clause is self
     * consuming: each pass fills the rows it just read, which removes them from the
     * next pass's result set, while OFFSET advances past another chunk that was never
     * processed. One run fills roughly half the backlog and the caller has to keep
     * calling until the row count stops moving. Keyset pagination on p.ID is the fix.
     *
     * It is invisible on an install whose cache is already full, which is every install
     * running 8.5.4, because the cache fills once and stays filled. 8.6.0's mandatory
     * truncate makes it visible on every install at once, which is why the build spec
     * records it as a blocker on that release ("4.2 BLOCKER, updates_all_posts()
     * self-consuming query. This must be fixed and verified before the truncate
     * ships.") and not on this one. Changing the fill loop here would put an unforced
     * regression risk into a security release that does not need it.
     *
     * @return void
     */
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

                // Keep the generation lease alive. This loop is the longest phase of a
                // run on a site whose cache is cold, and a lease that expires under it
                // lets a second run start appending to the same temp file.
                $this->refresh_lock();
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

    /**
     * Whether an anonymous, cookie-less visitor can read each of these posts.
     *
     * One call site for the whole generator, so there is one place to read to know how
     * this file asks the question. LLMS_Access is the only thing that answers it; this
     * method adds nothing to the decision beyond normalising the return so a missing
     * key cannot read as "publicly readable".
     *
     * Fails CLOSED when the class is absent. It is required unconditionally by the main
     * plugin file, so an absence means the plugin is half loaded, and generating a file
     * with no access control at all is the failure this release exists to remove.
     *
     * @param int[] $post_ids
     * @return array [ post_id => bool ]
     */
    private function publicly_readable(array $post_ids)
    {
        $out = array();
        foreach ($post_ids as $raw) {
            $id = absint($raw);
            if ($id) {
                $out[$id] = false;
            }
        }

        if (!$out) {
            return array();
        }

        if (!class_exists('LLMS_Access')) {
            return $out;
        }

        $verdict = LLMS_Access::filter_publicly_readable(array_keys($out));
        foreach ($out as $id => $unused) {
            $out[$id] = !empty($verdict[$id]);
        }

        return $out;
    }

    /**
     * Whether this run should hold back a section heading until something survives to go
     * under it.
     *
     * A heading with nothing under it is harmless, but a fully withheld run should leave
     * a header only file rather than a skeleton of empty headings, and that file is what
     * tells whoever looks that the site is gated rather than that the plugin is broken.
     *
     * Deliberately false on a site with no gating plugin and no site wide gate, which is
     * what keeps that site's output byte identical to 8.5.3 including its empty sections.
     *
     * @return bool
     */
    private function defer_empty_headings()
    {
        if (!class_exists('LLMS_Access')) {
            return true;
        }

        return LLMS_Access::site_is_gated() || (bool) LLMS_Access::gated_post_types();
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
        // UTF-8 BOM. This is the only in-band charset signal for the physical
        // llms.txt served directly by the web server (which often omits a
        // charset on .txt), so without it non-ASCII content (Cyrillic, CJK,
        // etc.) is mis-sniffed as Latin-1 and renders as mojibake. A BOM does
        // not affect the "missing H1" validators (those were tripped by the
        // `---` separators, removed in 8.4.1), so it is safe to keep.
        $output = "\xEF\xBB\xBF";
        if(is_a($existing_page,'WP_Post')) {
            $output .= "# Learn more:" . get_permalink($existing_page) . "\n\n";
        }
        $output .= "# " . esc_html((isset($settings['llms_txt_title']) && $settings['llms_txt_title'] ? $settings['llms_txt_title'] : get_bloginfo('name'))) . "\n\n";
        if ($meta_description) {
            $output .= "> " . esc_html($meta_description) . "\n\n";
        }

        if (isset($settings['llms_after_txt_description']) && $settings['llms_after_txt_description']) {
            $output .= "> " . esc_html($settings['llms_after_txt_description']) . "\n\n";
        }
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

        return trim(wp_strip_all_tags($clean));
    }

    private function generate_overview()
    {
        global $wpdb;
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start generate overview');
        }

        // The read time gate, first of its two halves. This method and
        // generate_detailed_content() are the only two readers of the six sensitive
        // columns, so gating both of them mediates everything that reaches the file.
        //
        // All four clauses of the gate run through the batch predicate per chunk:
        // published and not password protected, both read from wp_posts, then
        // restriction evidence and the AND only filter. Nothing in this file decides
        // access on its own; LLMS_Access is the only place that decision is made.
        $defer_headings = $this->defer_empty_headings();

        $table_cache = $wpdb->prefix . 'llms_txt_cache';
        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $heading = '';
            $post_type_obj = get_post_type_object($post_type);
            if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {

                $name = esc_html($post_type_obj->labels->name);
                if(isset($this->settings['post_name'][$post_type_obj->labels->name]) && $this->settings['post_name'][$post_type_obj->labels->name]) {
                    $name = esc_html($this->settings['post_name'][$post_type_obj->labels->name]);
                }

                $heading = "\n## {$name}\n\n";
            }

            if ($heading !== '' && !$defer_headings) {
                $this->write_file(mb_convert_encoding($heading, 'UTF-8', 'UTF-8'));
                $heading = '';
            }

            $offset = 0;
            $i = 0;
            $exit = false;
            $emitted = false;

            do {
                // The cached status column is NOT the authority on whether a row is
                // eligible. wp_posts is, and post_status and post_password are read from
                // it for every row of every chunk by the batch predicate below, which
                // drops anything that is not published or that carries a password before
                // a byte of it is written.
                //
                // This query keeps its 8.5.3 shape apart from the tiebreaker. The obvious
                // alternative, folding the two clauses in as an INNER JOIN on wp_posts,
                // was written, measured and taken back out: MySQL 8.0.45 drives that join
                // from wp_posts with a full table scan plus a temporary table and a
                // filesort, once per chunk. At 51,904 rows it cost 61 ms per chunk
                // against 2.9 ms for this form. The join would have been redundant as
                // well as expensive, because the batch predicate reads exactly the same
                // two columns out of wp_posts for the same ids in one primary key
                // lookup. Measured on MySQL 8.0.45; do not fold it back in without
                // re-measuring on a table of that size.
                //
                // ORDER BY carries `post_id` DESC as a tiebreaker and it is load bearing.
                // `published` is written as get_the_date('Y-m-d'), so every row carries
                // 00:00:00 and every post published on the same calendar day ties on the
                // only other sort column.
                //
                // 8.5.3 had neither the index nor a tiebreaker, so its tie order was
                // whatever filesort happened to produce, which is not a defined order.
                // Measured on MySQL 8.0.45, the 8.5.3 query returns a different tie order
                // on different tables, and the inputs that move it include the row count,
                // the SELECT column list, whether a LIMIT is present, and deleting two
                // unrelated rows. Six distinct arrangements were recorded across two
                // independent verification passes, including orders that are neither
                // ascending nor descending. So no tiebreaker reproduces 8.5.3, and any
                // comment claiming one direction preserves it is wrong whichever
                // direction it names. Two have already been wrong here: the revision
                // that chose ASC because it believed 8.5.3 returned ties ascending, and
                // the first version of this comment, which chose DESC partly because it
                // believed DESC was the closer match. It is not, in general.
                //
                // DESC is chosen on the query plan, which is the argument that does
                // generalise. llms_read already produces this order: an InnoDB secondary
                // index carries the primary key, so a backward scan yields published
                // DESC, post_id DESC and MySQL satisfies this ORDER BY straight from the
                // index. ASC cannot use it. Measured on MySQL 8.0.45, 20,000 rows, over
                // the 500 row chunk this code actually issues:
                //
                //     ASC                 15.9 - 16.3 ms   filesort
                //     DESC                 0.77 - 0.86 ms  backward index scan
                //     8.5.3's bare form    0.72 ms         backward index scan
                //
                // DESC therefore buys a defined order for almost nothing against 8.5.3,
                // where ASC cost about 20x and discarded the index this release adds. On
                // a small table all three converge on a filesort, so this is a large-site
                // property.
                //
                // A site with posts sharing a calendar day sees those entries reordered
                // once on upgrade. That is unavoidable in any direction, it is cosmetic,
                // and it is the only output change on a site with no gating plugin.
                //
                // What the cached status column can still do is hide a row whose cache is
                // stale in the other direction. That is 8.5.3's behaviour unchanged, it
                // costs fidelity rather than disclosure, and the next save of the post
                // corrects it.
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $sql = "SELECT `post_id`, `overview` FROM $table_cache $conditions ORDER BY `published` DESC, `post_id` DESC LIMIT %d OFFSET %d";

                $posts = $wpdb->get_results($wpdb->prepare($sql, ...$params));
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::log('Count: ' . count($posts));
                    \WP_CLI::log($wpdb->prepare($sql, ...$params));
                }
                if (!empty($posts)) {
                    // One batch call per chunk. The predicate is asked about every row
                    // read, including the ones the max_posts cap would stop at, because
                    // asking about the whole chunk is one query and asking per row is
                    // not.
                    $chunk_ids = array();
                    foreach ($posts as $data) {
                        $chunk_ids[] = $data->post_id;
                    }
                    $readable = $this->publicly_readable($chunk_ids);
                    unset($chunk_ids);

                    $output = '';
                    foreach ($posts as $data) {
                        if($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if (empty($readable[(int) $data->post_id])) {
                            unset($data);
                            continue;
                        }

                        if($data->overview) {
                            $output .= $data->overview;
                            $i++;
                        }

                        unset($data);
                    }

                    if ($output !== '') {
                        if ($heading !== '') {
                            $this->write_file(mb_convert_encoding($heading, 'UTF-8', 'UTF-8'));
                            $heading = '';
                        }
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                        $emitted = true;
                    }
                    unset($output);
                    unset($readable);
                }

                $offset += $this->limit;

                $this->refresh_lock();

            } while (!empty($posts) && !$exit);

            // A section that emitted nothing and whose heading was held back gets no
            // trailing newline either, so a fully withheld run leaves the header alone.
            if (!$defer_headings || $emitted) {
                $this->write_file(mb_convert_encoding("\n", 'UTF-8', 'UTF-8'));
            }

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

        // The read time gate, second half, and the reader that emits the bodies. Same
        // two clauses in the query, same batch predicate per chunk.
        //
        // Two separate holdbacks, and keeping them apart is the fix for a defect the
        // single $pending string had. The banner is written once for the whole section
        // and the heading is written once per post type, so a post type that emits
        // nothing has to drop its own heading while leaving the banner still owed. With
        // one string the excluded post type's heading stayed in it and the next post type
        // that emitted anything flushed both, so Detailed Content printed an empty
        // "## Posts" above the one real entry while the Overview half of the same file
        // correctly showed only "## Pages". Both stay empty on a site with no gating
        // plugin, which is what keeps that site's output byte identical to 8.5.3.
        $defer_headings = $this->defer_empty_headings();
        $pending_banner  = '';
        $pending_heading = '';

        if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
            $output = "#\n" . "# Detailed Content\n\n";
            if ($defer_headings) {
                $pending_banner .= $output;
            } else {
                $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
            }
        }

        $table_cache = $wpdb->prefix . 'llms_txt_cache';

        foreach ($this->settings['post_types'] as $post_type) {
            if ($post_type === 'llms_txt') continue;

            $emitted = false;

            // Per post type, the way generate_overview()'s $heading already is. An
            // excluded post type leaves its heading here and the next iteration
            // overwrites it, so it can never be flushed under a later post type's
            // entries.
            $pending_heading = '';

            if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                $post_type_obj = get_post_type_object($post_type);
                if (is_object($post_type_obj) && isset($post_type_obj->labels->name)) {
                    $name = $post_type_obj->labels->name;
                    if(isset($this->settings['post_name'][$post_type_obj->labels->name]) && $this->settings['post_name'][$post_type_obj->labels->name]) {
                        $name = $this->settings['post_name'][$post_type_obj->labels->name];
                    }
                    $output = "\n## " . $name . "\n\n";
                    if ($defer_headings) {
                        $pending_heading = $output;
                    } else {
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                    }
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
                // Same shape and the same reasoning as generate_overview(). wp_posts
                // decides published and password protected, for every row, through the
                // batch predicate below. The INNER JOIN form was measured and rejected
                // and the `post_id` tiebreaker is what keeps tied rows in 8.5.3's order;
                // the note over there has the numbers for both.
                $conditions = " WHERE `type` = %s AND `show`=1 AND `status`='publish' ";
                $params = [
                    $post_type,
                    $this->limit,
                    $offset
                ];

                $posts = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_cache $conditions ORDER BY `published` DESC, `post_id` DESC LIMIT %d OFFSET %d", ...$params));
                if (!empty($posts)) {
                    $chunk_ids = array();
                    foreach ($posts as $data) {
                        $chunk_ids[] = $data->post_id;
                    }
                    $readable = $this->publicly_readable($chunk_ids);
                    unset($chunk_ids);

                    foreach ($posts as $data) {
                        if (!$data->content) continue;
                        if (empty($readable[(int) $data->post_id])) continue;
                        if ($i > $this->settings['max_posts']) {
                            $exit = true;
                            break;
                        }

                        if (isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                            if ($data->meta) {
                                $output .= "> " . wp_trim_words($data->meta, $this->settings['max_words'] ?? 250, '...') . "\n\n";
                            }

                            $output .= "- Published: " . esc_html(gmdate('Y-m-d', strtotime($data->published))) . "\n";
                            $output .= "- Modified: " . esc_html(gmdate('Y-m-d', strtotime($data->modified))) . "\n";
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
                            $output .= "\n";
                        }
                        unset($data);

                        $i++;
                    }

                    unset($readable);
                }

                if(isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) {
                    if ($output !== '') {
                        // Banner first, then this post type's heading, then the entries.
                        if ($pending_banner !== '') {
                            $this->write_file(mb_convert_encoding($pending_banner, 'UTF-8', 'UTF-8'));
                            $pending_banner = '';
                        }
                        if ($pending_heading !== '') {
                            $this->write_file(mb_convert_encoding($pending_heading, 'UTF-8', 'UTF-8'));
                            $pending_heading = '';
                        }
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                        $emitted = true;
                    } elseif (!$defer_headings) {
                        // 8.5.3 wrote here unconditionally. Appending an empty string
                        // changes no byte of the file, so this branch exists only to
                        // keep the call shape identical on the path that is measured
                        // against 8.5.3.
                        $this->write_file(mb_convert_encoding($output, 'UTF-8', 'UTF-8'));
                    }
                }
                unset($output);

                $offset += $this->limit;

                $this->refresh_lock();

            } while (!empty($posts) && !$exit);

            if((isset($this->settings['detailed_content']) && $this->settings['detailed_content'] || isset($this->settings['include_excerpts']) && $this->settings['include_excerpts'] || isset($this->settings['include_taxonomies']) && $this->settings['include_taxonomies'] || isset($this->settings['include_meta']) && $this->settings['include_meta']) && (!$defer_headings || $emitted)) {
                $this->write_file(mb_convert_encoding("\n", 'UTF-8', 'UTF-8'));
            }

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate detailed content');
            }
        }

        $settings = apply_filters('get_llms_generator_settings', []);
        if (isset($settings['llms_end_file_description']) && $settings['llms_end_file_description']) {
            $this->write_file(mb_convert_encoding('> ' . esc_html($settings['llms_end_file_description']) . "\n\n", 'UTF-8', 'UTF-8'));
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
            $front_page_id = (int) get_option('page_on_front');
            $description = '';

            // The tenth write_file() call site. With the tagline empty this reads the
            // front page's own excerpt and then its post_content, so a front page an
            // anonymous visitor cannot read would be quoted in the header of a file
            // anyone can fetch. It is the one sensitive read that does not come from the
            // cache table, so the two read queries below do not cover it.
            if ($front_page_id) {
                $readable = $this->publicly_readable(array($front_page_id));
                if (empty($readable[$front_page_id])) {
                    $front_page_id = 0;
                }
            }

            if ($front_page_id) {
                $description = get_the_excerpt($front_page_id);
                if (empty($description)) {
                    $description = get_post_field('post_content', $front_page_id);
                }
            }

            $description = $this->remove_shortcodes(str_replace(']]>', ']]&gt;', apply_filters('the_content', $description)));
            $description = wp_trim_words(wp_strip_all_tags(preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}\x{202A}-\x{202E}\x{2060}]/u', ' ', html_entity_decode($description))), 30, '');
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

    /**
     * Return the WPML language codes marked as hidden (WPML → Languages →
     * Hide languages). Posts in these languages are excluded from llms.txt.
     * Uses WPML's own setting filter, falling back to the raw option.
     *
     * @return array Array of hidden language codes (e.g. ['de', 'fr']).
     */
    private function get_wpml_hidden_languages()
    {
        $hidden = apply_filters('wpml_setting', null, 'hidden_languages');
        if (!is_array($hidden)) {
            $settings = get_option('icl_sitepress_settings');
            $hidden = (is_array($settings) && isset($settings['hidden_languages']) && is_array($settings['hidden_languages']))
                ? $settings['hidden_languages']
                : [];
        }
        return $hidden;
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

        // Through the shared helper, which tolerates a null transport and falls back to
        // wp_delete_file(). This dereferenced $this->wp_filesystem with no guard, and
        // init_filesystem() returns null on exactly the hosts the rest of this class was
        // taught to survive this round, so "Delete and recreate" answered HTTP 500 and a
        // progress bar that never moved on any host whose WP_Filesystem transport cannot
        // connect. Adversarial F2. It also means the button now works on those hosts
        // rather than merely failing politely.
        $this->remove_generated_files(array($old_upload_path, $new_upload_path, $this->temp_llms_path));

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

    /**
     * get_remote_title() used to live here.
     *
     * It fetched every post's own URL over HTTP, once per post, on every install that
     * does not run Rank Math, to read the <title> element into the cache table's title
     * column. Nothing reads that column: the file is built from overview and content,
     * and title is written and never selected. So the request bought nothing and cost a
     * self request per post per generation, a concurrency race against the run that
     * triggered it (which truncated a generated file to 21 bytes during the original
     * reproduction), and, on any post the fetch 404ed, a cached copy of the 404 page's
     * markup.
     *
     * The title column and the 'title' key in $replace_data stay exactly as they were.
     * $title still falls through from $post->post_title, so the published filters
     * llms_handle_post_update_replace_data and llms_handle_post_update_replace_format
     * see the same keys in the same order as 8.5.3. The Rank Math branch that overrides
     * the title, and sets $show = 0 for noindex, is untouched.
     */

    public function ajax_gen_step()
    {
        set_time_limit(0);
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        check_ajax_referer('llms_gen_nonce');

        $qid = isset($_POST['queue_id']) ? sanitize_text_field(wp_unslash($_POST['queue_id'])) : '';
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

        // Storage hygiene. This is NOT the gate, and the distinction matters.
        //
        // A post that is not published, or that carries a password, gets its row written
        // with the same keys as always but with content, overview, excerpts, meta, sku
        // and price left empty, and the content pipeline is skipped entirely.
        //
        // What keeps that post out of llms.txt is the read query in generate_overview()
        // and generate_detailed_content(), which decides from wp_posts and not from
        // anything written here. Delete this branch tomorrow and the generated file is
        // byte for byte the same; what changes is that the cache table goes back to
        // holding the full body of every draft on the site. The failure mode here is
        // fidelity, never disclosure. Nobody should later read this as an access
        // control, and nothing should be allowed to depend on it as one.
        //
        // The row is written, never deleted. A row that is absent is permanently
        // eligible under "cache.post_id IS NULL" in updates_all_posts() and
        // ajax_gen_init(), so deleting it would make every generation re-fetch and
        // re-write every draft on the site, for ever.
        //
        // It is also the largest single saving in this release on an editorial site:
        // 8.5.3 runs do_shortcode twice plus about 22 regex passes over every draft,
        // pending, private and trashed post to produce columns the read queries
        // guarantee are never emitted.
        //
        // Placement is load bearing. This sits ABOVE the WPML language switch below.
        // Returning between that switch and its restore would leave WPML switched for
        // the rest of the admin request, which is what the comment on the switch warns
        // about. Sitting here means it cannot reach the $wpdb->replace at the end of the
        // method, so it does its own write.
        if ('publish' !== $post->post_status || '' !== (string) $post->post_password) {
            $this->write_blank_cache_row($post_id, $post);

            // Still schedule the regeneration. A post going from published to draft
            // arrives here, and that is exactly the transition whose entry has to come
            // out of the file.
            //
            // An auto-draft is the exception, and it is not a rare one. Loading
            // /wp-admin/ renders the Quick Draft widget, which calls
            // get_default_post_to_edit('post', true), which inserts a post with status
            // auto-draft and fires save_post for it. Nobody wrote anything, nothing in
            // the file can have changed, and this used to queue a full regeneration 30
            // seconds later on every dashboard load that had to mint one. Traced from
            // the scheduling call: wp_dashboard_quick_press -> get_default_post_to_edit
            // -> wp_insert_post -> save_post -> here. It is what put an
            // llms_update_llms_file_cron event 30 seconds after the migration rebuild in
            // the end to end verification, and wp_clear_scheduled_hook() was innocent:
            // the dashboard renders after admin_init, so there was nothing to clear at
            // the moment it ran. The row is still written, so the post is not left
            // permanently eligible for a re-fetch.
            if ('auto-draft' !== $post->post_status
                && $this->settings['update_frequency'] === 'immediate'
                && $update !== 'manual') {
                wp_clear_scheduled_hook('llms_update_llms_file_cron');
                wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
            }

            return;
        }

        // Point the WooCommerce global at the post being processed, always, and put back
        // whatever was there afterwards.
        //
        // 8.5.3 set it only "if ( ! $product )", so a product left in the global by the
        // previous iteration of the batch, or by another plugin earlier in the request,
        // survived into this post's processing. Everything below that renders content
        // reads that global: do_shortcode(), the_content filters, WooCommerce blocks and
        // shortcodes. So the previous product's data could be rendered into this post's
        // content column, and out into the file inside an entry that is itself perfectly
        // public. No access gate in this release closes that, because the post being
        // emitted is not restricted; the data riding inside it is.
        //
        // wc_get_product() returns false for anything that is not a product, which is
        // the correct value for this global while a non-product is being processed.
        $llms_product_swapped = false;
        $llms_previous_product = null;
        if (function_exists('wc_get_product') && $post instanceof WP_Post) {
            $llms_previous_product = $product;
            $llms_product_swapped  = true;
            $product = wc_get_product( $post->ID );
        }

        // Put WPML into this post's language before any permalink is built.
        //
        // get_permalink() resolves against the *current* language, so without this
        // the URL cached for a translation is built from the default language's slug
        // and then only has the language prefix swapped on, producing hybrids like
        // /en/kontakt/ (English prefix, German slug) while the front end shows
        // /en/contact/ correctly.
        //
        // This switch previously lived only in updates_all_posts(), so the cron path
        // was correct while the other two callers, the save_post hook and the
        // "Delete and recreate" batch, were not. Whichever ran last won, which is
        // why re-saving a page could corrupt a URL that the cron had gotten right.
        // Doing it here covers all three callers.
        $llms_restore_lang = null;
        if (function_exists('wpml_object_id_filter') && $post instanceof WP_Post) {
            $llms_post_lang = apply_filters('wpml_element_language_code', null, array(
                'element_id'   => $post->ID,
                'element_type' => 'post_' . $post->post_type,
            ));
            if ($llms_post_lang) {
                // Restore afterwards: on the save_post path this runs mid-request,
                // and leaving WPML switched would leak into the rest of the request.
                $llms_restore_lang = apply_filters('wpml_current_language', null);
                do_action('wpml_switch_language', $llms_post_lang);
            }
        }

        $table = $wpdb->prefix . 'llms_txt_cache';
        $price = '';
        $sku = '';

        $is_hidden_language = false;
        if(defined('ICL_LANGUAGE_CODE')) {
            $language_code = $wpdb->get_var("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_id=" . intval($post->ID) . " AND element_type LIKE '%_" . $post->post_type . "'");
            $permalink = apply_filters(
                'wpml_permalink',
                get_permalink($post->ID),
                $language_code
            );

            // Exclude posts belonging to a WPML language marked as hidden
            // (WPML → Languages → Hide languages) so unfinished or intentionally
            // hidden translations are not exposed in llms.txt.
            $hidden_languages = $this->get_wpml_hidden_languages();
            if ($language_code && in_array($language_code, $hidden_languages, true)) {
                $is_hidden_language = true;
            }
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
                $description = wp_trim_words(wp_strip_all_tags($fallback_content), 20, '...');
            }

            $overview = sprintf("- [%s](%s)%s\n", esc_html($post->post_title), esc_url($permalink), esc_html($markdown) . ($this->settings['include_excerpts'] && $description ? ': ' . preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', esc_html($description)) : ''));
        } else {
            $overview = sprintf("- [%s](%s)%s\n", esc_html($post->post_title), esc_url($permalink), esc_html($markdown) . ($this->settings['include_excerpts'] ? ': ' . preg_replace('/[\x{00A0}\x{200B}\x{200C}\x{200D}\x{FEFF}]/u', ' ', esc_html($description)) : ''));
        }

        $show = 1;
        if ($is_hidden_language) {
            $show = 0;
        }
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
        $use_rankmath = function_exists('rank_math') && isset(rank_math()->variables);
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
        }
        // There is no else branch any more. It called get_remote_title(), which fetched
        // this post's own URL over HTTP to read a column nothing selects. See the note
        // where that method used to be.

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

        if (defined('SLIM_SEO_VER')) {
            $slim_seo = get_post_meta($post_id, 'slim_seo', true);
            if (!empty($slim_seo['noindex'])) {
                $show = 0;
            }
        }

        $excerpts = $this->remove_shortcodes($post->post_excerpt);
        $custom_txt = wp_kses_post(get_post_meta($post->ID, '_llmstxt_custom_note', true));
        if($custom_txt) {
            $content = $custom_txt;
        } else {
            ob_start();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is captured via ob_get_clean() for further processing, not sent to the browser.
            echo $this->content_cleaner->clean($this->remove_emojis( $this->remove_shortcodes(do_shortcode(get_the_content(null, false, $post)))));
            $content = ob_get_clean();
        }

        // Allow integrations (e.g. ACF) or site owners to append custom field
        // content that lives outside post_content, which get_the_content() does
        // not include. Return the string unchanged to opt out.
        $content = apply_filters('llms_generator_post_content', $content, $post);

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

        // Put WPML back where we found it (see the switch at the top of this method).
        if ($llms_restore_lang) {
            do_action('wpml_switch_language', $llms_restore_lang);
        }

        // Put the WooCommerce global back too. Leaving our own product behind would be
        // the same defect from the other end: the next thing in this request to render a
        // template would see it.
        if ($llms_product_swapped) {
            $product = $llms_previous_product;
        }
    }

    /**
     * Write the cache row for a post an anonymous visitor cannot read, with every
     * content bearing column empty.
     *
     * Storage hygiene, not an access control. See the branch that calls it.
     *
     * The key order and the format array are identical to the full write below, and both
     * published filters run here exactly as they do there, so a third party integration
     * sees the same $replace_data shape it has always seen on a draft save.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return void
     */
    private function write_blank_cache_row($post_id, $post)
    {
        global $wpdb;

        $replace_data = [
            'post_id' => $post_id,
            // Nothing about this row is eligible for the file. The read query decides
            // that from wp_posts on its own; this is only so the row does not describe
            // itself as shown.
            'show' => 0,
            'status' => $post->post_status,
            'type' => $post->post_type,
            'title' => $post->post_title,
            'link' => (string) get_permalink($post->ID),
            'sku' => '',
            'price' => '',
            'meta' => '',
            'excerpts' => '',
            'overview' => '',
            'content' => '',
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
            $wpdb->prefix . 'llms_txt_cache',
            $replace_data,
            $replace_format
        );
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

        // Same exception as the save path, for the same reason and with a second one of
        // its own. An auto-draft was never in the file, so removing it cannot change the
        // file. WordPress deletes week-old auto-drafts on its own daily schedule, in one
        // sweep, so without this a site would pay a full regeneration for a batch of
        // posts nobody ever wrote.
        if ('auto-draft' === $post->post_status) {
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
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
            wp_schedule_single_event(time() + 30, 'llms_update_llms_file_cron');
        }
    }

    /**
     * Generate the document and put it in place, or leave nothing behind.
     *
     * The invariant this method exists to hold:
     *
     *   after any generation attempt, /llms.txt and the uploads copy are either the
     *   complete output of that attempt, or absent. Never partial, never the previous
     *   file.
     *
     * The last clause is the security-carrying one. Every "keep the old file if the new
     * one looks wrong" rule reads as prudent and is the opposite here, because the old
     * file is the one with the restricted content in it. There is no circuit breaker and
     * no minimum size check. An empty but complete document is always promoted:
     * generate_site_info() writes the header unconditionally, so a fully gated site ends
     * up with a valid header only file, which is safe and is self evidently not a normal
     * file to whoever looks at it.
     *
     * @return void
     */
    public function update_llms_file()
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log('Start');
        }

        // Re-entry. A run is already in flight in this request and this call is inside
        // it, so there is nothing here to do and, more to the point, nothing here that
        // may touch the outer run's lease state. See $running.
        if ($this->running) {
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('A generation is already running in this request, nothing to do');
            }
            return;
        }

        // Generation walks every post type in the settings and runs for minutes on a
        // large site. 8.5.3 has this on ajax_gen_step() and on the manual admin_post
        // handler but not on this path, which is the path cron uses, so a cron
        // generation is the one that dies at max_execution_time part way through.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!$this->wp_filesystem) {
            $this->init_filesystem();
        }

        // WP_Filesystem() failed. write_file() silently writes nothing in that state and
        // every ->exists() call below would fatal on a boolean, which is what 8.5.3 does
        // here. Nothing can be written and nothing can be removed, so leave the
        // filesystem exactly as it is and let the next run try again.
        //
        // Nothing was touched, so nothing is recorded: no stamp, and the rebuild flag is
        // left exactly as it was. The one thing that does need doing is re-arming the
        // scheduled event, because this run consumed it by firing. Without that, a
        // cron-driven attempt on a host with no transport is the last attempt that route
        // ever makes.
        if (!$this->wp_filesystem) {
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('WP_Filesystem is unavailable, nothing was generated');
            }
            $this->rearm_owed_rebuild();
            return;
        }

        // One generation at a time. Losing the claim is not a failure: the run that holds
        // it is producing a complete document, so this one returns without touching a
        // single file.
        //
        // Nothing on this instance is written until the lease is actually held. The
        // order matters and it is adversarial F4: clearing lock_token first meant that a
        // re-entrant call, which is a call that CANNOT get the lease, wiped the token of
        // the run that had it.
        $token = '';
        if (class_exists('LLMS_Lock')) {
            $acquired = LLMS_Lock::acquire($this->lock_name);
            if (false === $acquired) {
                if (defined('WP_CLI') && WP_CLI) {
                    \WP_CLI::log('Another generation holds the lock, nothing to do');
                }
                // The other run owns the outcome, including the rebuild flag and the
                // stamp. Nothing here is touched except the scheduled event, and only
                // when this call consumed it and left no route behind: a lease orphaned
                // by a killed run refuses every generation for up to 300 seconds, and a
                // site whose only route is WP-Cron would otherwise spend its one
                // scheduled event inside that window and never schedule another.
                $this->rearm_owed_rebuild();
                return;
            }
            $token = $acquired;
        }

        // From here this run owns the lease and this instance's lease state.
        $this->running    = true;
        $this->lock_token = $token;
        $this->lock_lost  = false;

        $upload_dir = wp_upload_dir();
        $old_upload_path = $upload_dir['basedir'] . '/llms.txt';
        $new_upload_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.llms.txt';
        $this->temp_llms_path = $upload_dir['basedir'] . '/' . $this->llms_name . '.temp.llms.txt';

        $file_path = '';
        if (defined('FLYWHEEL_PLUGIN_DIR')) {
            $file_path = trailingslashit(dirname(ABSPATH)) . 'www/' . 'llms.txt';
        } else {
            $file_path = trailingslashit(ABSPATH) . 'llms.txt';
        }

        $complete = false;

        // What a run that does not finish has to remove, shared with the shutdown
        // handler so that a hard stop and a caught throw settle the files identically.
        //
        // The root copy is single site only, both to write and to remove. Every site on
        // a network shares one ABSPATH, so a failed run on blog 2 removing the root file
        // would be removing a file blog 2 never wrote.
        $this->shutdown_remove = array($this->temp_llms_path, $new_upload_path, $old_upload_path);
        if (!is_multisite()) {
            $this->shutdown_remove[] = $file_path;
        }

        try {
            // Only the temp file is removed up front, and only because it is this run's
            // own scratch space. 8.5.3 deleted the destinations here as well, which left
            // /llms.txt missing for the whole of a long generation and missing for good
            // if the run died. The destinations are now either replaced at the end or
            // removed at the end.
            if ($this->wp_filesystem->exists($this->temp_llms_path)) {
                $this->wp_filesystem->delete($this->temp_llms_path);
            }

            // From here the run is producing a document, and from here until the end
            // state is settled a stop that never unwinds must not be able to leave the
            // previous file in place. catch (\Throwable) does not see an OOM, a kill, a
            // max_execution_time or php-fpm request_terminate_timeout stop, wp_die(), or
            // a fatal in a third party hook, and without this the whole failure path
            // below is simply skipped: both destinations keep the document from before
            // the run, which on a site that has just installed a membership plugin is
            // the leaking one. Shutdown functions do run for all of those. They do not
            // run for SIGKILL, and after an OOM they run with no memory left to work in,
            // which is the honest residual.
            $this->generation_armed = true;
            if (!$this->shutdown_registered) {
                $this->shutdown_registered = true;
                register_shutdown_function(array($this, 'on_generation_shutdown'));
            }

            $this->generate_content();

            // The completion sentinel. Reaching this line is the only thing that makes
            // this run's output eligible for promotion, so a throw, a fatal or an
            // execution timeout anywhere above leaves it false and the document is
            // removed instead.
            $complete = $this->wp_filesystem->exists($this->temp_llms_path);

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('End generate_content event');
            }

            if ($complete) {
                $complete = $this->promote_generated_file($new_upload_path, $file_path, $old_upload_path);
            }
        } catch (\Throwable $e) {
            $complete = false;
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Generation failed: ' . $e->getMessage());
            }
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a failed generation, WP_DEBUG only.
                error_log('website-llms-txt: generation failed, no file was promoted: ' . $e->getMessage());
            }
        }

        // A run whose lease was stolen deletes nothing at all. The run that took the
        // lease over is appending to the same temp file and owns both destinations, so
        // removing them here would truncate somebody else's document, which is the
        // failure this whole method is about. The holder decides the end state.
        if (!$complete && !$this->lock_lost) {
            // The run did not finish, or the promote did not. Either way the rule is the
            // same and it is the whole point of this method: what is on disk is never a
            // partial document and never the previous one.
            $this->remove_generated_files($this->shutdown_remove);
        }

        /*
         * The outcome, recorded here and nowhere else, before a single line of third
         * party code runs.
         *
         * This is the fix for the whole class of defect this mechanism kept producing.
         * The stamp used to be written by a side effect of update_option
         * ('llms_last_generated'), which is several statements, one wp_update_post() and
         * every save_post callback on the site further down; and it did not fire at all
         * when two generations landed in the same second, because the value did not
         * change. Both gaps end the same way: the disk holds a good document, the stamp
         * describes the one before it, and the next ordinary request reads that as a
         * foreign file and deletes the document that was just generated.
         *
         * Written before generation_armed is cleared, so that a hard stop in the gap is
         * settled by the shutdown handler rather than leaving a stamp that disagrees
         * with the disk.
         */
        if ($complete) {
            // Only a complete, promoted document advances this. The settings screen
            // reads it as "Last generated", and LLMS_Core reads it to tell a run that
            // did work from one that returned without doing any, so a run that produced
            // nothing must not claim to have generated anything.
            update_option('llms_last_generated', time(), false);

            if (class_exists('LLMS_DB')) {
                LLMS_DB::note_document_promoted();
            }
        } elseif (!$this->lock_lost && class_exists('LLMS_DB')) {
            // The destinations were removed just above, so the stamp this writes is the
            // truth about the disk, and the site is owed the document this run took
            // away. A run that lost its lease is excluded because it settled nothing:
            // the thief owns the outcome.
            LLMS_DB::note_document_absent();
        }

        // Both destinations are settled now, by a completed promote or by the removal
        // above, so the shutdown handler has nothing left to decide about files.
        //
        // Disarming here rather than at the end of the method is load bearing in the
        // other direction. Everything below runs third party code: wp_update_post()
        // fires save_post for every plugin on the site. A fatal in one of those with the
        // handler still armed would delete the document this run has just promoted, and
        // a shutdown handler that destroys files on an ordinary successful request is a
        // worse bug than the one it exists to fix.
        $this->generation_armed = false;

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

        if ('' !== $this->lock_token && class_exists('LLMS_Lock')) {
            // release_identity(), not release(): refresh_lock() mints a new token on
            // every refresh, so identity is what this run still shares with the row.
            LLMS_Lock::release_identity($this->lock_name, $this->lock_token);
            $this->lock_token = '';
        }

        // This instance is free again. A Throwable escaping the lines above leaves it
        // set, and on_generation_shutdown() clears it along with the lease; refusing a
        // second run in a request whose first run ended in an exception is the safe
        // direction, and it is what the lock would say anyway.
        $this->running = false;

        do_action('wpseo_cache_clear_sitemap');
        do_action('llms_clear_seo_caches_rank_math');
    }

    /**
     * Put the scheduled route back when a rebuild is still owed.
     *
     * Only for the paths that do no work and settle nothing: firing the scheduled event
     * consumes it, so a run that returns without touching anything has to re-arm it or
     * that route is spent. It deliberately does not create an obligation that did not
     * exist: if nothing is owed, nothing is scheduled.
     *
     * It restores a lost route and never moves one that is still there, and it creates
     * no obligation that did not exist: if nothing is owed, nothing is scheduled. The
     * whole decision lives in LLMS_DB::ensure_rebuild_scheduled(), which every request
     * that finds a rebuild owed also calls.
     *
     * @return void
     */
    private function rearm_owed_rebuild()
    {
        if (class_exists('LLMS_DB')) {
            LLMS_DB::ensure_rebuild_scheduled();
        }
    }

    /**
     * Remove the generated destinations, without being able to fail the request.
     *
     * This is the failure path of the file integrity invariant, and a failure path that
     * can itself fatal is not a failure path. 8.5.4 as assembled ran this loop outside
     * the try, so on a host whose WP_Filesystem transport throws on ->exists() the whole
     * request died here, taking wp-admin with it. Every path is attempted independently
     * so one unremovable file does not leave the rest in place.
     *
     * wp_delete_file() is the fallback and not the first choice. The transport is the
     * right tool where it works, including on a host where PHP itself cannot unlink, but
     * a transport that has thrown or returned false has told us nothing about the file,
     * and leaving the previous document in place is the one outcome this method exists
     * to prevent.
     *
     * @param string[] $paths
     * @return void
     */
    private function remove_generated_files(array $paths)
    {
        foreach ($paths as $path) {
            $path = (string) $path;
            if ('' === $path) {
                continue;
            }

            try {
                if ($this->wp_filesystem && $this->wp_filesystem->exists($path)) {
                    $this->wp_filesystem->delete($path);
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a failed cleanup, WP_DEBUG only.
                    error_log('website-llms-txt: could not remove ' . $path . ' through WP_Filesystem: ' . $e->getMessage());
                }
            }

            if (file_exists($path)) {
                wp_delete_file($path);
            }
        }
    }

    /**
     * Settle a generation that ended without unwinding.
     *
     * Registered once per instance from update_llms_file(), and does nothing unless a
     * run is in flight, so an ordinary request that never generates and an ordinary
     * request that generated successfully both pass through it untouched. That is the
     * dangerous direction: a shutdown handler that unlinks on a successful request would
     * destroy the document on every generation.
     *
     * Public only because register_shutdown_function() needs a callable. Not part of the
     * plugin's API and nothing should call it.
     *
     * @return void
     */
    public function on_generation_shutdown()
    {
        try {
            // A run whose lease was stolen is no longer the authority over these files.
            // The run that took the lease over is producing the document and will settle
            // them itself.
            if ($this->generation_armed && !$this->lock_lost) {
                $this->generation_armed = false;
                $this->remove_generated_files($this->shutdown_remove);

                // The run died and this handler has just taken the site's document
                // away, so the site is owed one and both routes to it are re-armed
                // here. Nothing else can do this: a stop that reaches this handler
                // never returned to update_llms_file(), and the post-update one-shot
                // used to try, from a shutdown function of its own registered BEFORE
                // this one, reading a disk this handler had not cleaned up yet, so it
                // saw the scratch file, concluded a document had been left and asked
                // for nothing. That is adversarial F5, and this is where the decision
                // belongs: the code that removed the files is the code that knows they
                // are gone.
                if (class_exists('LLMS_DB')) {
                    LLMS_DB::note_document_absent();
                }
            }

            $this->running = false;

            // Release the lease as well, whether or not files had to be removed. Without
            // this a hard stop leaves the row behind and every generation for the next
            // LLMS_Lock::STALE_AFTER seconds returns immediately, which is the wrong
            // behaviour for a system whose failures repeat: an OOM at post N recurs at
            // post N on the next run, so the site would spend five minutes in each cycle
            // refusing to try, with the stale document still being served.
            if ('' !== $this->lock_token && class_exists('LLMS_Lock')) {
                LLMS_Lock::release_identity($this->lock_name, $this->lock_token);
                $this->lock_token = '';
            }
        } catch (\Throwable $e) {
            // A shutdown handler has nowhere to report to and nothing left to try.
            // Throwing from here would replace one failure with a less legible one.
        }
    }

    /**
     * Put the finished document in place.
     *
     * @param string $new_upload_path Uploads copy, the file get_llms_content() serves.
     * @param string $file_path       Root llms.txt, served by the web server directly.
     * @param string $old_upload_path Pre-domain-name uploads copy, deleted if present.
     * @return bool True when the uploads copy is the output of this run.
     */
    private function promote_generated_file($new_upload_path, $file_path, $old_upload_path)
    {
        // The uploads copy from before the file was named after the domain. Nothing
        // writes it any more; it is removed rather than promoted to, and get_llms_content()
        // does not read it.
        if ($this->wp_filesystem->exists($old_upload_path)) {
            $this->wp_filesystem->delete($old_upload_path);
        }

        // WP_Filesystem::move() with overwrite deletes the destination and then renames.
        // The destination is therefore briefly ABSENT and then complete, and never a
        // mixture, because a rename inside one directory is atomic on every filesystem
        // WordPress runs on. Absent is a state this invariant permits; partial is not.
        //
        // Full atomicity, where the path never stops resolving, is not reachable through
        // WP_Filesystem: move() unlinks the destination by construction and there is no
        // method on the API that renames over an existing path. That is the honest limit
        // of this fix, and it is the right trade, because the alternative to a
        // millisecond of 404 is serving half a document.
        if (!$this->wp_filesystem->move($this->temp_llms_path, $new_upload_path, true)) {
            return false;
        }

        // Multisite promotes as well now. The whole promote block used to sit inside
        // if (!is_multisite()), so a network never promoted at all and served the temp
        // file directly through get_llms_content()'s fallback, which meant the invariant
        // was simply false there: what a network served was whatever a run had appended
        // so far. The ROOT copy stays single site only, and for an unrelated reason:
        // every site on a network shares one ABSPATH, so each would overwrite the
        // others' /llms.txt.
        if (is_multisite()) {
            return true;
        }

        // The root file cannot be a rename from the uploads copy, because it is in a
        // different directory, and WP_Filesystem's copy() truncates the destination in
        // place: a reader arriving mid copy gets half a document, which is exactly the
        // state this method exists to make impossible. So copy to a sibling of the
        // destination and rename that over it.
        //
        // The invariant is per artifact, and the two artifacts are promoted
        // independently on purpose. Plenty of sites cannot write to ABSPATH at all: on
        // those, 8.5.3 served the uploads copy through the rewrite rule and the root
        // file simply never existed. Failing the whole promote because the root copy
        // failed would take the served document away from every one of them, which is
        // over-removal of exactly the kind this release cannot afford. So a root copy
        // that fails leaves the root absent, or failing that overwritten in place, and
        // the uploads copy still counts as promoted. What it must never leave is the
        // previous document, and the block below is three attempts at that in order of
        // preference.
        $staging = trailingslashit(dirname($file_path)) . '.llms.txt.part';

        if ($this->wp_filesystem->exists($staging)) {
            $this->wp_filesystem->delete($staging);
        }

        $root_ok = $this->wp_filesystem->copy($new_upload_path, $staging, true)
            && $this->wp_filesystem->move($staging, $file_path, true);

        if (!$root_ok) {
            // Never the previous file. Through the shared helper, so this gets the same
            // per path protection and the same wp_delete_file() fallback as the failure
            // path in update_llms_file(): the transport that could not write the root
            // copy is quite likely the transport that cannot delete it either, and a
            // root file left behind is the one the web server serves in preference to
            // everything else here.
            $this->remove_generated_files(array($staging, $file_path));

            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::log('Could not write the root llms.txt, the uploads copy is the served document');
            }

            // Last resort, and only when the previous root file is still there.
            //
            // A read only ABSPATH is a real hardening posture and it can be applied to a
            // site that already has a root llms.txt, so the staging file cannot be
            // created and the stale one cannot be unlinked: creating and deleting need
            // write permission on the DIRECTORY. Writing an existing file does not, so
            // the one operation still available is to overwrite the file where it lies.
            // Measured on WordPress 7.0.2 with ABSPATH at 0555 and llms.txt at 0644:
            // the copy to .llms.txt.part fails with "Permission denied" and this
            // succeeds.
            //
            // copy() truncates the destination and writes into it, which is the partial
            // document the atomic path above exists to avoid, so this is deliberately
            // the last thing tried rather than the first. The trade is a window of
            // milliseconds in which a reader can get a prefix of the CURRENT document,
            // against leaving the PREVIOUS document in place with no end date. The
            // invariant's own docblock says the last clause is the security carrying
            // one, and it is: a prefix of the current document contains nothing that is
            // not in the current document, while the previous one is the file that may
            // still carry what this release exists to remove.
            if (file_exists($file_path)) {
                $rescued = false;

                try {
                    $rescued = (bool) $this->wp_filesystem->copy($new_upload_path, $file_path, true);
                } catch (\Throwable $e) {
                    $rescued = false;
                }

                if (!$rescued) {
                    if (defined('WP_CLI') && WP_CLI) {
                        \WP_CLI::log('The previous root llms.txt could not be removed or overwritten and is still being served');
                    }
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for an unremovable stale artifact, WP_DEBUG only.
                        error_log('website-llms-txt: the previous ' . $file_path . ' could not be replaced, removed or overwritten and is still being served');
                    }
                }
            }
        }

        return true;
    }

    /**
     * Keep the generation lease alive during a long run.
     *
     * Called once per read chunk. The token carries a timestamp and refresh() mints a
     * new one, so the new token has to be kept: releasing with the original would fail
     * its own identity check and orphan the row until the steal threshold.
     *
     * @return void
     */
    private function refresh_lock()
    {
        if ('' === $this->lock_token || !class_exists('LLMS_Lock')) {
            return;
        }

        $refreshed = LLMS_Lock::refresh($this->lock_name, $this->lock_token);

        if (false === $refreshed) {
            // The lease was stolen, which means this run stalled for longer than the
            // steal threshold and another run is now appending to the same temp file.
            // Stop writing rather than add more bytes to a document somebody else is
            // producing, and give up the claim without deleting a row that is now
            // theirs. update_llms_file() will find its sentinel false and promote
            // nothing.
            $this->lock_token = '';
            $this->lock_lost  = true;
            throw new \RuntimeException('llms.txt generation lease was lost, another run has taken over');
        }

        $this->lock_token = $refreshed;
    }

    public function schedule_updates()
    {
        if (!wp_next_scheduled('llms_scheduled_update')) {
            $interval = ($this->settings['update_frequency'] === 'daily') ? 'daily' : 'weekly';
            wp_schedule_event(time(), $interval, 'llms_scheduled_update');
        }
    }
}