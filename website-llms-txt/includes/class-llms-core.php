<?php
if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Core {
    /** @var LLMS_Generator */
    private $generator;

    /**
     * Option holding the last generation's exclusion summary. Spec 3.9.
     *
     * Not autoloaded. It is read on one admin screen and nowhere else, and it is
     * written once per generation, so paying for it on every front end request
     * would be a cost on 40,000 installs for a record almost nobody looks at.
     */
    const EXCLUDED_OPTION = 'llms_last_excluded';

    /**
     * How many distinct reason lines the summary keeps.
     *
     * reasons() dedupes on code plus detail, so an ordinary site produces one line
     * per post type plus a handful. The unbounded shape is a reader or a query that
     * fails with a different message each time (an id or a value in the text), which
     * would otherwise grow one line per post. The cap is on the stored record, not on
     * the counting, so the counts stay true.
     */
    const EXCLUDED_REASON_LIMIT = 60;

    /**
     * Post meta the site owner sets to assert that one post is publicly readable.
     *
     * Same string as LLMS_Access::INCLUDE_META, and the two have to agree. It is
     * repeated rather than referenced so that this file does not depend on the
     * access class being loaded, which is what the metabox guard already assumes.
     */
    const INCLUDE_META = '_llmstxt_force_include';

    /**
     * True when this request is a whole site generation run.
     *
     * Set at priority 1 on every entry point that leads to a full pass, read on
     * shutdown. See record_excluded_summary() for why the boundary is the request
     * rather than the end of update_llms_file().
     *
     * @var bool
     */
    private $generation_run = false;

    /**
     * llms_last_generated as it was when this request was marked a generation run.
     *
     * @var int
     */
    private $generation_started_at = 0;

    public function __construct()
    {
        // NO register_activation_hook() here. It has to be at file scope in
        // website-llms-txt.php, next to register_deactivation_hook(), because this
        // constructor only runs from llms_init() on plugins_loaded and an activation
        // request includes the plugin file after plugins_loaded has already fired. A
        // registration made here is never made at all. See LLMS_Core::activate().

        // Make a new site on a network rebuild its rewrite rules, so ours is in them
        add_action('wp_initialize_site', array($this, 'on_new_site'), 10, 1);

        // Initialize core functionality
        add_action('init', array($this, 'init'), 0);

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_filter('plugin_action_links_' . plugin_basename(WEBSITE_LLMS_TXT_FILE), array($this, 'add_settings_link'));

        // Handle cache clearing
        add_action('admin_post_clear_caches', array($this, 'handle_cache_clearing'));

        // Initialize SEO integrations before post type registration
        add_action('init', array($this, 'init_seo_integrations'), -1);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Add required scripts for admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        add_action('wp_head', array($this, 'wp_head'));

        add_action('admin_notices', array($this, 'render_vk_banner'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notice_script'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_banner_styles'));
        add_action('wp_ajax_dismiss_llms_vk_banner', array($this, 'dismiss_llms_vk_banner'));
        add_filter('redirect_canonical', array($this, 'redirect_canonical'), 10, 2);

        // The per post include control. Spec 3.10, per post rather than per post
        // type, because on a membership site the gated and the public content are
        // usually the same post types.
        // Two arguments. add_meta_boxes passes the post type and the post, and the
        // default of one would hand the callback a null post, which is a silent
        // "return early" and no control at all.
        add_action('add_meta_boxes', array($this, 'add_include_meta_box'), 10, 2);
        add_action('save_post', array($this, 'save_include_meta'));

        // The include meta is written by our own metabox handler and by nothing else.
        // This closes the generic meta capability API against it. See deny_meta_write().
        add_filter('auth_post_meta_' . self::INCLUDE_META, array(__CLASS__, 'deny_meta_write'), PHP_INT_MAX, 6);

        // ...and the per subtype variant of the same hook, which is a DIFFERENT hook and
        // is the one register_post_meta() writes to. map_meta_cap() picks one branch or
        // the other, so the generic filter above does not cover it at any priority. See
        // deny_meta_write_for_post_type(). Core's own post types are registered from
        // wp-settings.php before any plugin loads, so the ones that already exist are
        // covered by the loop and everything registered later by the action.
        foreach (get_post_types() as $llms_registered_type) {
            self::deny_meta_write_for_post_type($llms_registered_type);
        }
        add_action('registered_post_type', array(__CLASS__, 'deny_meta_write_for_post_type'), PHP_INT_MAX);

        // Spec 3.9. Mark the request as a generation run, then snapshot what was
        // excluded on shutdown. Priority 1 so the marker is set before the
        // generator's own callback does the work, since several of these end in
        // wp_send_json or exit and never come back.
        foreach (self::generation_hooks() as $hook) {
            add_action($hook, array($this, 'mark_generation_run'), 1);
        }
        add_action('shutdown', array($this, 'record_excluded_summary'), 1);
    }

    /**
     * The actions that mean "a whole site generation pass is running in this request".
     *
     * save_post is deliberately absent. handle_post_update() evaluates a single post,
     * and letting it write the summary would replace a 5,000 post picture with a one
     * row one. wp_ajax_llms_gen_step is absent for the same reason: it is one chunk of
     * a chunked fill, and the run it belongs to ends at wp_ajax_llms_update_file,
     * which is here.
     *
     * @return string[]
     */
    private static function generation_hooks()
    {
        return array(
            'updates_all_posts',
            'llms_update_llms_file_cron',
            'llms_scheduled_update',
            'admin_post_run_manual_update_llms_file',
            'wp_ajax_run_llms_txt_reset_file',
            'wp_ajax_llms_update_file',
        );
    }

    /**
     * Remember that this request is a generation run.
     *
     * @return void
     */
    public function mark_generation_run()
    {
        $this->generation_run = true;
        // What the file was at when this run started. record_excluded_summary()
        // compares against it to tell a run that did work from one that returned
        // without doing any, which is what a run blocked by the lock does.
        $this->generation_started_at = (int) get_option('llms_last_generated');
    }

    /**
     * Write down what the run that just happened left out of the file. Spec 3.9.
     *
     * This is load bearing rather than decorative. The changelog for 8.5.4 is
     * deliberately minimal, so on a membership site the only thing standing between
     * an owner whose file shrank and a rollback to 8.5.3 is a screen that says which
     * post types went, how many entries, and which plugin caused it. A rollback
     * reintroduces the whole vulnerability on a site that demonstrably runs a gating
     * plugin, so an explanation here is a security control by another route.
     *
     * WHY THE BOUNDARY IS THE REQUEST AND NOT update_llms_file(). That function fires
     * no action of its own and lives in a file this round does not let me edit. So the
     * marker is set on the entry points at priority 1 and the snapshot is taken on
     * shutdown, which WordPress runs through register_shutdown_function() and which
     * therefore still runs after wp_send_json_*() or wp_safe_redirect() plus exit.
     * Every one of those endpoints ends that way.
     *
     * It is written on every marked run, including a run that excluded nothing. A
     * record that is only written when something goes wrong goes stale the moment the
     * owner fixes the cause, and a stale scary summary is worse than none.
     *
     * reasons() is read BEFORE the two state calls below it. Both of them can record a
     * reason of their own when they fail closed, and a reason recorded after the pass
     * finished is not a fact about the pass.
     *
     * @return void
     */
    public function record_excluded_summary()
    {
        if (!$this->generation_run) {
            return;
        }

        // Once per request, whatever else fires afterwards.
        $this->generation_run = false;

        if (!class_exists('LLMS_Access')) {
            return;
        }

        $reasons = LLMS_Access::reasons();
        if (!is_array($reasons)) {
            $reasons = array();
        }

        // A run that neither finished nor recorded anything replaces nothing. The
        // lock makes this ordinary rather than exotic: a hard stop orphans the lease
        // for STALE_AFTER, and every generation in that window returns immediately
        // having done no read pass at all. Measured: zero cache SELECTs, and the
        // summary on a gated site serving a header-only file was replaced with a
        // record saying nothing had been left out, which is the one thing standing
        // between a confused owner and a rollback to a version with the leak.
        //
        // The discriminator is that such a run neither advances llms_last_generated
        // nor records a reason, and no legitimate run does both of those at once: an
        // ungated site's successful run records no reasons but does advance the
        // timestamp, and a killed run that reached the read pass records reasons.
        $finished = ((int) get_option('llms_last_generated')) > $this->generation_started_at;
        if (!$finished && !$reasons && get_option(self::EXCLUDED_OPTION)) {
            return;
        }

        $summary = array(
            'time'       => time(),
            'precise'    => self::precise_access_enabled(),
            'site_gated' => (bool) LLMS_Access::site_is_gated(),
            'reasons'    => array_slice($reasons, 0, self::EXCLUDED_REASON_LIMIT),
            'truncated'  => (count($reasons) > self::EXCLUDED_REASON_LIMIT),
        );

        update_option(self::EXCLUDED_OPTION, $summary, false);
    }

    /**
     * Whether per post evaluation is turned on.
     *
     * Reads the same option key with the same default as LLMS_Access::precise_access(),
     * which is private. The two have to agree: this one decides what the screen says
     * happened, that one decides what actually happens.
     *
     * @return bool
     */
    public static function precise_access_enabled()
    {
        $settings = get_option('llms_generator_settings');

        return (is_array($settings) && !empty($settings['precise_access']));
    }

    /**
     * Turn a stored summary into rows a screen can print.
     *
     * The only structure in a reason record is the post type prefix that
     * LLMS_Access writes onto 'post_type_gated' and 'force_included'. Parsing it
     * happens HERE and nowhere else, and the parse validates itself: a prefix is
     * only believed when post_type_exists() agrees. Anything else, including a
     * detail whose format has moved, falls through to a plain line rather than
     * being forced into a table row with a wrong label.
     *
     * @param array $summary Stored llms_last_excluded record.
     * @return array {
     *     @type array $types    [ post_type => [ label, count, detail ] ] excluded
     *     @type array $included [ post_type => count ] kept by the per post include
     *     @type array $notes    [ [ detail, count ] ] everything else
     * }
     */
    public static function excluded_summary_rows($summary)
    {
        $out = array('types' => array(), 'included' => array(), 'notes' => array());

        if (!is_array($summary) || empty($summary['reasons']) || !is_array($summary['reasons'])) {
            return $out;
        }

        foreach ($summary['reasons'] as $reason) {
            if (!is_array($reason)) {
                continue;
            }
            $code   = isset($reason['code']) ? (string) $reason['code'] : '';
            $detail = isset($reason['detail']) ? (string) $reason['detail'] : '';
            $count  = isset($reason['count']) ? (int) $reason['count'] : 0;

            $type = '';
            $rest = $detail;
            $split = strpos($detail, ': ');
            if (false !== $split) {
                $candidate = substr($detail, 0, $split);
                if (post_type_exists($candidate)) {
                    $type = $candidate;
                    $rest = substr($detail, $split + 2);
                }
            }

            if ('force_included' === $code && $type) {
                if (!isset($out['included'][$type])) {
                    $out['included'][$type] = 0;
                }
                $out['included'][$type] += $count;
                continue;
            }

            if ('post_type_gated' === $code && $type) {
                // Keyed by post type AND cause, which is what spec 3.9 asks for. One
                // post type can be excluded by two different things in the same run:
                // WooCommerce coming soon names its store pages with a different
                // detail from the post type gate, and both are of type page. Keying
                // on the type alone would add those counts together under whichever
                // cause happened to be recorded first.
                $key = $type . '|' . $rest;
                if (!isset($out['types'][$key])) {
                    $obj = get_post_type_object($type);
                    $out['types'][$key] = array(
                        'label'  => ($obj && isset($obj->labels->name)) ? $obj->labels->name : $type,
                        'count'  => 0,
                        'detail' => $rest,
                    );
                }
                $out['types'][$key]['count'] += $count;
                continue;
            }

            $out['notes'][] = array('detail' => $detail, 'count' => $count);
        }

        return $out;
    }

    /**
     * The capability that may assert a post is publicly readable.
     *
     * NOT edit_post, and the difference is the whole point. edit_post is the capability
     * to edit the post. The assertion this control makes is not about the post, it is
     * about the site's public file: it says "publish this body to anyone who fetches
     * /llms.txt". On a membership site those two are held by different people. Measured
     * on MySQL with Members 3.2.26: an Author who owns a post an administrator has
     * restricted to subscribers has edit_post on it, does not have Members'
     * restrict_content, cannot see or lift the restriction, and under the old check
     * could tick this box and publish the withheld body to the world.
     *
     * manage_options is the default because it is the capability that already owns this
     * plugin's settings screen, and a statement about the whole site's public surface
     * belongs to whoever owns the site's settings. It is deliberately blunt: an editor
     * who cannot lift the underlying restriction must not be able to publish through it.
     *
     * Filterable so a site with a different editorial model can widen it DELIBERATELY,
     * which is the difference between a site owner's decision and a default. Return a
     * capability, not a boolean. The post id is passed so a filter can return a meta
     * capability such as 'edit_others_posts' or a gating plugin's own capability
     * ('restrict_content' on a Members site is the natural choice), and both primitive
     * and meta capabilities work because the id is forwarded to current_user_can().
     *
     * @param int $post_id
     * @return string
     */
    public static function include_capability($post_id = 0)
    {
        $cap = apply_filters('llms_include_capability', 'manage_options', (int) $post_id);

        return is_string($cap) && '' !== $cap ? $cap : 'manage_options';
    }

    /**
     * May the current user make the include assertion on this post?
     *
     * Two conditions, both required. edit_post is the floor: whatever the site widens
     * the assertion capability to, somebody who cannot edit the post never gets to
     * change what the file says about it. The assertion capability is the ceiling.
     *
     * @param int $post_id
     * @return bool
     */
    public static function can_include($post_id)
    {
        $post_id = (int) $post_id;
        if (!$post_id) {
            return false;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return false;
        }

        return current_user_can(self::include_capability($post_id), $post_id);
    }

    /**
     * Refuse every write to the include meta that goes through the meta capability API.
     *
     * Hooked at PHP_INT_MAX to BOTH hooks in map_meta_cap()'s auth chain for this key:
     * the generic auth_post_meta_<key>, and auth_post_meta_<key>_for_<post_type> for
     * every registered post type, added by deny_meta_write_for_post_type().
     *
     * BOTH, because those two are not one hook with two names and the chain is an
     * if/else. map_meta_cap() applies the _for_<subtype> hook when anything is
     * registered on it and the generic one only otherwise (wp-includes/capabilities.php,
     * 5.8 through current). register_post_meta('post', $key, ...) is exactly what
     * registers the _for_post variant, at priority 10. So until 8.5.4 a third party
     * registering this key for a post type replaced the protected-meta refusal with its
     * own auth_callback, moved the decision to a hook this class was not on, and an
     * Author who may not make the assertion could set it through the REST API. That was
     * measured, not reasoned about: an Author wrote the meta over REST and the next
     * generation published a body an anonymous visitor gets a 404 for. An earlier
     * version of this comment claimed priority alone survived it. It does not, and the
     * claim was wrong.
     *
     * WHAT THIS DOES NOT COVER, stated plainly rather than implied:
     *
     *  - the deprecated auth_post_<post_type>_meta_<key> hook, which map_meta_cap()
     *    applies after both of the above. Nothing is hooked to it here on purpose:
     *    apply_filters_deprecated() emits a _deprecated_hook() notice the moment that
     *    hook has any callback at all, so occupying it would put a notice in the log of
     *    every site on every capability check. register_meta() has not used it since
     *    4.9.8, so reaching it means somebody hand wrote a filter naming this plugin's
     *    private key on a hook WordPress deprecated.
     *  - another callback added to the same hook at PHP_INT_MAX after this one, which
     *    WordPress runs in registration order.
     *
     * The key is still not registered with register_meta() by this plugin and is not
     * exposed to REST, so on an ordinary site REST and XML-RPC store nothing before any
     * of this is reached: is_protected_meta() is true for an underscore prefixed key and
     * map_meta_cap() maps edit_post_meta to do_not_allow. That was measured and it is
     * the behaviour to keep. The filters are the belt for when a third party changes it.
     *
     * The only supported writer is save_include_meta(), which carries a nonce and the
     * capability check above and calls update_post_meta() directly. Direct calls do not
     * consult capabilities at all, so site code and WP-CLI are unaffected.
     *
     * @param bool   $allowed
     * @param string $meta_key
     * @param int    $object_id
     * @param int    $user_id
     * @param string $cap
     * @param array  $caps
     * @return bool
     */
    public static function deny_meta_write($allowed = false, $meta_key = '', $object_id = 0, $user_id = 0, $cap = '', $caps = array())
    {
        return false;
    }

    /**
     * Put the include-meta refusal on one post type's subtype auth hook.
     *
     * Called once per registered post type from the constructor and then on
     * registered_post_type for anything registered afterwards, so a post type a theme or
     * a plugin adds on init, or later, is covered on the request it appears in.
     *
     * add_filter() with the same callback and the same priority is idempotent, so
     * core re-registering its own post types on init:0 adds nothing a second time.
     *
     * Hooked at PHP_INT_MAX for the same reason as deny_meta_write(): register_meta()
     * puts a third party's auth_callback on this hook at priority 10.
     *
     * @param string $post_type Post type name, as passed by the registered_post_type action.
     * @return void
     */
    public static function deny_meta_write_for_post_type($post_type)
    {
        if (!is_string($post_type) || '' === $post_type) {
            return;
        }

        add_filter(
            'auth_post_meta_' . self::INCLUDE_META . '_for_' . $post_type,
            array(__CLASS__, 'deny_meta_write'),
            PHP_INT_MAX,
            6
        );
    }

    /**
     * The per post include control, in the editor. Spec 3.10.
     *
     * PER POST, not per post type. On a membership site the gated content and the
     * public content are usually the same post types, so a per post type override is
     * either useless or it puts the vulnerability straight back.
     *
     * Shown where it can do something, which is decided by asking LLMS_Access about
     * THIS post rather than about its post type. The first version of this asked
     * gated_post_types(), and measured on MySQL with per post evaluation turned on
     * that list is empty, so the control vanished from every post on exactly the
     * sites whose exclusions are decided one post at a time.
     *
     * It is also shown on any post that already carries the meta, whatever the state
     * of the site. Without that clause, deactivating the membership plugin would hide
     * the control on every post that has one set and the owner would have no way to
     * see or clear an assertion they had made.
     *
     * Shown only to a user who may make the assertion, and can_include() is checked
     * here as well as on save so the box is not merely hidden. Hiding a control is not
     * a capability check: save_include_meta() refuses the same request even when the
     * fields are posted by hand. Both checks call the same function.
     *
     * @param string  $post_type
     * @param WP_Post $post
     * @return void
     */
    public function add_include_meta_box($post_type, $post = null)
    {
        if (!class_exists('LLMS_Access') || !is_object($post) || empty($post->ID)) {
            return;
        }

        // First, and before any query. A user who cannot make the assertion gets no
        // box, no nonce and no checkbox, on their own posts as much as anyone else's.
        if (!self::can_include($post->ID)) {
            return;
        }

        $settings = apply_filters('get_llms_generator_settings', array());
        $types    = isset($settings['post_types']) && is_array($settings['post_types']) ? $settings['post_types'] : array();
        $already  = ('1' === (string) get_post_meta($post->ID, self::INCLUDE_META, true));

        $reason = '';

        if (!$already) {
            if (!in_array($post_type, $types, true)) {
                return;
            }

            // The deterministic gate. An assertion cannot reach a post that is not
            // published or that carries a password, so offering the control on one
            // would be offering a control that provably does nothing.
            if ('publish' !== $post->post_status || '' !== $post->post_password) {
                return;
            }

            // The cheap exit, and it is the one that has to stay cheap: on an install
            // with no access control plugin at all, gated_post_types() is symbol
            // detection with no query and this returns before anything is asked about
            // the post. That is the ~39k install case.
            $gated = LLMS_Access::gated_post_types();
            $reason = isset($gated[$post_type]) ? $gated[$post_type] : '';
            if (!$gated && !LLMS_Access::site_is_gated() && !self::precise_access_enabled()) {
                return;
            }

            // Ask the class about this one post rather than about its post type.
            // With per post evaluation on there is no post type gate to read, so a
            // post type test would hide the control on exactly the sites whose
            // exclusions are decided post by post. Two or three queries, on a post
            // edit screen, on a site that runs an access control plugin.
            $verdict = LLMS_Access::filter_publicly_readable(array((int) $post->ID));
            if (!empty($verdict[(int) $post->ID])) {
                return;
            }
        }

        add_meta_box(
            'llms_include',
            __('Llms.txt access', 'website-llms-txt'),
            array($this, 'render_include_meta_box'),
            $post_type,
            'side',
            'default',
            array('reason' => $reason)
        );
    }

    /**
     * @param WP_Post $post
     * @param array   $box add_meta_box callback args
     * @return void
     */
    public function render_include_meta_box($post, $box = array())
    {
        // Belt and braces. add_include_meta_box() already refused to register the box,
        // so this only fires if something else registered our callback, and printing a
        // nonce for a user who may not use it is exactly what not to do.
        if (!is_object($post) || empty($post->ID) || !self::can_include($post->ID)) {
            return;
        }

        $included = ('1' === (string) get_post_meta($post->ID, self::INCLUDE_META, true));
        $reason   = isset($box['args']['reason']) ? (string) $box['args']['reason'] : '';

        // The deterministic gate. The box does not appear on these unless the meta is
        // already set, and where it does appear the owner has to be told that clearing
        // it is not what is keeping the post out of the file.
        $deterministic = '';
        if ('publish' !== $post->post_status) {
            $deterministic = __('This post is not published, so it is left out of llms.txt whatever this setting says.', 'website-llms-txt');
        } elseif ('' !== $post->post_password) {
            $deterministic = __('This post is password protected, so it is left out of llms.txt whatever this setting says.', 'website-llms-txt');
        }

        wp_nonce_field('llms_include_' . $post->ID, 'llms_include_nonce');

        if ($deterministic) {
            // Say this first and do not claim the post is in the file. The assertion
            // cannot reach the deterministic gate, so on a draft or a password
            // protected post a ticked box is true about the owner's intent and false
            // about the file.
            echo '<p><strong>' . esc_html($deterministic) . '</strong></p>';
        } elseif ($included) {
            echo '<p><strong>' . esc_html__('This post is in llms.txt because you said it is public.', 'website-llms-txt') . '</strong></p>';
        } elseif ($reason) {
            echo '<p>' . esc_html($reason) . '</p>';
        } else {
            echo '<p>' . esc_html__('This post is being left out of llms.txt.', 'website-llms-txt') . '</p>';
        }
        ?>
        <p>
            <label>
                <input type="checkbox" name="llms_force_include" value="1" <?php checked($included); ?> />
                <?php esc_html_e('Anyone can read this post without logging in. Include it in llms.txt.', 'website-llms-txt'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('llms.txt is a public file. Ticking this is your statement that this post is public too, and the plugin takes your word for it instead of leaving the post out.', 'website-llms-txt'); ?>
        </p>
        <?php
    }

    /**
     * Save the per post include assertion.
     *
     * The nonce says the request came from our box. It does not say the sender may
     * edit THIS post: it is tied to the action and the session, so the capability
     * check is separate and comes before the write. Same reasoning as
     * LLMS_MD::save_post(), and this control is the one that defeats a security
     * exclusion, so it is the last place to get that wrong.
     *
     * The capability is can_include(), not edit_post. edit_post was wrong and it was
     * measured wrong: an Author with edit_post on their own post, which an administrator
     * had restricted to subscribers with Members, could publish that withheld body into
     * the public file. See include_capability(). The check is here as well as on
     * display, because a hidden control is not a refused one and the fields can be
     * posted by hand.
     *
     * The nonce is bound to the post id, which is also what stops a revision's own
     * save_post call from writing anything.
     *
     * Written as exactly '1' and deleted rather than written as '0'. LLMS_Access
     * reads it with meta_value = '1' in one site wide query, so a row holding
     * anything else is a row that costs a read and rescues nothing.
     *
     * @param int $post_id
     * @return void
     */
    public function save_include_meta($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['llms_include_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['llms_include_nonce'])), 'llms_include_' . $post_id)) {
            return;
        }

        if (!self::can_include($post_id)) {
            return;
        }

        if (!empty($_POST['llms_force_include'])) {
            update_post_meta($post_id, self::INCLUDE_META, '1');
        } else {
            delete_post_meta($post_id, self::INCLUDE_META);
        }
    }

    public function enqueue_banner_styles() {
        wp_enqueue_style('llms-banner-styles', WEBSITE_LLMS_TXT_URL . 'admin/admin-banner.css', array(), WEBSITE_LLMS_TXT_VERSION);
    }

    public function render_vk_banner() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (get_user_meta(get_current_user_id(), 'llms_vk_banner_dismissed', true)) {
            return;
        }
        if (get_option('vk_embed_token')) {
            return;
        }

        $banner_href = admin_url('tools.php?page=llms-file-manager');
        ?>
        <div class="notice is-dismissible llms-vk-banner">
            <p class="vk-banner-text">
                <?php esc_html_e('Are AI platforms visiting your site?', 'website-llms-txt'); ?>
                <a href="<?php echo esc_url($banner_href); ?>" class="vk-banner-link"><?php esc_html_e('See your tracking data', 'website-llms-txt'); ?> &rarr;</a>
            </p>
        </div>
        <?php
    }

    /**
     * Stop core's canonical redirect from rewriting a request for the llms.txt path.
     *
     * strpos(), NOT str_contains(). str_contains() is PHP 8.0, WordPress only polyfills
     * it from 5.9, and this plugin declares "Requires at least: 5.8" and
     * "Requires PHP: 7.2" in both the plugin header and README.txt. This filter is added
     * unconditionally in the constructor and core applies it on every request for which
     * it has computed a canonical redirect: a URL missing its trailing slash, /?p=ID,
     * /index.php/slug/, a wrong-case path, 404 permalink guessing. So on a WordPress 5.8
     * site running any PHP 7.x, str_contains() made ordinary front end traffic a fatal
     * and the visitor got HTTP 500 from the site itself, not from llms.txt. Measured on
     * WP 5.8.13 / PHP 7.4.33 and 7.2.34. Do not put str_contains() back while 5.8 and
     * 7.2 are the declared floor.
     *
     * The two guards are not decoration either. An earlier callback on this filter may
     * have already cancelled the redirect by returning false, and wp_parse_url() returns
     * no 'path' key at all for a path-less target, which was an undefined index notice
     * on PHP 7 and a warning on PHP 8.
     *
     * @param string|false $redirect_url  The URL core wants to redirect to, or false if
     *                                    a callback before us already cancelled it.
     * @param string       $requested_url The URL that was requested. Unused, kept because
     *                                    the filter is registered with two arguments.
     * @return string|false false cancels the redirect.
     */
    public function redirect_canonical($redirect_url, $requested_url)
    {
        if (!is_string($redirect_url) || '' === $redirect_url) {
            return $redirect_url;
        }

        $redirect = wp_parse_url($redirect_url);
        if (!is_array($redirect) || !isset($redirect['path'])) {
            return $redirect_url;
        }

        $ll_redirect_path = strtolower($redirect['path']);
        if (false !== strpos($ll_redirect_path, 'llms')) {
            return false;
        }

        return $redirect_url;
    }

    public function dismiss_llms_vk_banner() {
        check_ajax_referer('llms_dismiss_notice', 'nonce');
        update_user_meta(get_current_user_id(), 'llms_vk_banner_dismissed', 1);
        wp_send_json_success();
    }

    public function enqueue_notice_script() {
        wp_enqueue_script('llms-notice-script', WEBSITE_LLMS_TXT_URL . 'admin/notice-dismiss.js', array('jquery'), WEBSITE_LLMS_TXT_VERSION, true);
        wp_localize_script('llms-notice-script', 'llmsNoticeAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('llms_dismiss_notice')
        ));
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
        require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-generator.php';
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
            require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-provider.php';
            require_once WEBSITE_LLMS_TXT_DIR . 'includes/rank-math.php';
        }

        if (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps')) {
            require_once WEBSITE_LLMS_TXT_DIR . 'includes/yoast.php';
        }

        if (defined('SLIM_SEO_VER')) {
            require_once WEBSITE_LLMS_TXT_DIR . 'includes/slim-seo.php';
        }

        if (function_exists('aioseo')) {
            require_once WEBSITE_LLMS_TXT_DIR . 'includes/aioseo.php';
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
                    'post_name' => array(),
                    'max_posts' => 100,
                    'max_words' => 250,
                    'include_meta' => false,
                    'include_excerpts' => false,
                    'include_taxonomies' => false,
                    'gform_include' => false,
                    'update_frequency' => 'immediate',
                    'need_check_option' => true,
                    'llms_allow_indexing' => false,
                    'include_md_file' => false,
                    'detailed_content' => false,
                    // Off. Every site that has never opened this screen gets the
                    // safe value, and an absent key reads as off everywhere.
                    'precise_access' => false,
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
        
        $clean['post_name'] = array();
        if (isset($value['post_name']) && is_array($value['post_name'])) {
            foreach ($value['post_name'] as $name => $custom_name) {
                $clean['post_name'][$name] = sanitize_text_field($custom_name);
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
        $clean['include_meta'] = !empty($value['include_meta']);
        $clean['noindex_header'] = !empty($value['noindex_header']);
        $clean['include_excerpts'] = !empty($value['include_excerpts']);
        $clean['include_taxonomies'] = !empty($value['include_taxonomies']);
        $clean['gform_include'] = !empty($value['gform_include']);
        $clean['llms_txt_title'] = !isset($value['llms_txt_title']) ? '' : $value['llms_txt_title'];
        $clean['llms_txt_description'] = !isset($value['llms_txt_description']) ? '' : $value['llms_txt_description'];
        $clean['llms_after_txt_description'] = !isset($value['llms_after_txt_description']) ? '' : $value['llms_after_txt_description'];
        $clean['llms_end_file_description'] = !isset($value['llms_end_file_description']) ? '' : $value['llms_end_file_description'];
        $clean['include_md_file'] = !empty($value['include_md_file']);
        $clean['detailed_content'] = !empty($value['detailed_content']);

        // Per post evaluation. An absent checkbox is off, which is what every form
        // on the settings screen submits when the box is unticked and what every
        // install that has never seen this control has. There is deliberately no
        // truthy default anywhere on this path.
        $clean['precise_access'] = !empty($value['precise_access']);

        // Deliberately NOT in the truncate comparison below. This setting changes
        // which cached rows are read out into the file, never what is written into
        // them, so emptying the cache would cost a full re-crawl and change nothing.
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

        // Everything this method did not name. Without this, every key it does not
        // know about is silently dropped on the first save: the registered default
        // need_check_option disappears from the stored option on every install, and a
        // third party or a future version adding a key finds it deleted by the next
        // settings save. The forms already carry these keys as hidden inputs, so they
        // arrive here intact and are lost only at this line.
        //
        // The sharpest consequence is not divergence from the default. Anything the
        // plugin writes here with update_option() passes through this method, so
        // removed_ai_sitemap, the flag llms_maybe_create_ai_sitemap_page() sets to
        // record that its one-time cleanup has run, could never persist, and that
        // method re-ran get_page_by_path('ai-sitemap') on init on every request on
        // every install.
        //
        // Preserved, not trusted. Anything not named above has no schema here, so it
        // is sanitised generically rather than stored as it arrived, and structures
        // that cannot be sanitised generically are dropped rather than guessed at.
        // Merged from the submitted array rather than from the stored option on
        // purpose: merging from stored would resurrect a key a third party had just
        // deliberately removed.
        foreach ($value as $key => $unknown) {
            if (array_key_exists($key, $clean)) {
                continue;
            }
            $key  = is_string($key) ? $key : (string) $key;
            $kept = self::sanitize_unknown_setting($unknown);
            if (null !== $kept) {
                $clean[$key] = $kept;
            }
        }

        return $clean;
    }

    /**
     * Sanitise a settings value this class has no schema for.
     *
     * Scalars only, and arrays of them. sanitize_textarea_field() rather than
     * sanitize_text_field() because a third party's value may legitimately carry
     * newlines, and neither leaves markup behind. Objects and nulls are dropped:
     * update_option() would serialise an object and nothing here can know whether
     * waking it up later is safe.
     *
     * The depth cap is not decoration. maybe_unserialize() on an option can produce
     * a deeply nested array and an uncapped recursion over one is a stack exhaustion
     * on the settings save path.
     *
     * array_key_exists() rather than isset() in the caller matters for the same class
     * of reason: a named key whose value is null would be re-added by the loop under
     * isset(), and every named key above is assigned unconditionally, so
     * array_key_exists() is exactly "this method already decided about this key".
     *
     * @param mixed $value
     * @param int   $depth
     * @return mixed|null Null means do not store this.
     */
    private static function sanitize_unknown_setting($value, $depth = 0)
    {
        if ($depth > 5) {
            return null;
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $kept = self::sanitize_unknown_setting($v, $depth + 1);
                if (null !== $kept) {
                    $out[is_string($k) ? $k : (string) $k] = $kept;
                }
            }
            return $out;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return sanitize_textarea_field($value);
        }

        return null;
    }

    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, ['tools_page_llms-file-manager', 'toplevel_page_llms-file-manager'])) {
            return;
        }

        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue admin styles with dashicons dependency
        wp_enqueue_style('llms-admin-styles', WEBSITE_LLMS_TXT_URL . 'admin/admin-styles.css', array('dashicons'), WEBSITE_LLMS_TXT_VERSION);
        wp_enqueue_script('llms-admin-script', WEBSITE_LLMS_TXT_URL . 'admin/admin-script.js', array('jquery', 'jquery-ui-sortable'), WEBSITE_LLMS_TXT_VERSION, true);
        wp_localize_script('llms-admin-script', 'LLMS_GEN', [
            'nonce' => wp_create_nonce('llms_gen_nonce'),
        ]);
        wp_localize_script('llms-admin-script', 'LLMS_VK', [
            'nonce' => wp_create_nonce('vk_connect_nonce'),
        ]);
    }

    /**
     * Activation. Register the llms.txt rewrite rule, then flush.
     *
     * STATIC, and registered from file scope in website-llms-txt.php, not from this
     * constructor. WordPress includes the plugin file during an activation request in
     * which plugins_loaded has ALREADY fired, so llms_init() never runs, LLMS_Core is
     * never constructed, and a register_activation_hook() call sitting in the
     * constructor registers nothing. Measured on a real activation: the only callback on
     * activate_website-llms-txt/website-llms-txt.php was the probe. That is why this
     * method was dead code on every activation the plugin has ever had, and why an
     * established site kept its stale rewrite_rules option and served /llms.txt as a 404
     * on every blog of a network and on any single site whose ABSPATH is not writable.
     * register_deactivation_hook() in website-llms-txt.php is at file scope for the same
     * reason and has always worked.
     *
     * Static so that file scope can register it without constructing LLMS_Core. Building
     * an instance there would re-register every hook this class adds, inside the request
     * that is running the activation hook.
     *
     * THE TWO BRANCHES DO DIFFERENT THINGS AND HAVE TO.
     *
     * Single site: add the rule, then flush. Adding it first is the fix. Flushing without
     * it rewrites the option from a rule set that does not contain ours, which is worse
     * than doing nothing: it is what wrote the stale value in the first place.
     *
     * Network: invalidate each blog's rules and let each blog rebuild its own, because a
     * flush inside a switch_to_blog() writes the bootstrap blog's rules into the switched
     * blog and 404s every post on it. See invalidate_rewrite_rules(), which has the
     * measurement.
     *
     * @param bool $network_wide True when the plugin was activated for the whole network.
     * @return void
     */
    public static function activate($network_wide = false) {
        if (is_multisite() && $network_wide) {
            // 'number' => 0 is required, not tidy-up. WP_Site_Query defaults to
            // 'number' => 100 (wp-includes/class-wp-site-query.php:176 on 5.8) and
            // builds its LIMIT only when the value is non-empty, so 0 means no LIMIT
            // and every blog. Without it a network of more than 100 blogs has the
            // first 100 invalidated and the rest silently left with a stale option,
            // and there is no second route for them: on_new_site() only fires for
            // blogs created later, and a network-active plugin cannot be activated
            // per site. Do not drop this argument.
            foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $blog_id) {
                switch_to_blog($blog_id);
                self::invalidate_rewrite_rules();
                restore_current_blog();
            }

            return;
        }

        self::add_rewrite_rule();
        flush_rewrite_rules();
    }

    /**
     * Make this blog rebuild its rewrite rules on its next request.
     *
     * DELETE THE OPTION. DO NOT FLUSH FROM INSIDE A switch_to_blog(), and this is the
     * one thing in this file that has to be read before anybody "simplifies" it back to
     * a flush.
     *
     * switch_to_blog() does not touch the global WP_Rewrite. The object keeps the
     * permalink structure, the front, the taxonomy permastructs and the page-rule mode
     * of the blog this request bootstrapped on, so flush_rewrite_rules() inside a switch
     * generates THAT blog's rules and writes them into this one's option.
     *
     * On a subdirectory network that is every post on every subsite. WordPress gives the
     * main site of a subdirectory network the structure "/blog/%postname%/" so its
     * permalinks cannot collide with a subsite path, while each subsite gets
     * "/%postname%/". Measured on a three site network, flushing blog 2 from a request
     * bootstrapped on blog 1:
     *
     *   blog 2's option lost   ([^/]+)(?:/([0-9]+))?/?$
     *              and gained  blog/([^/]+)(?:/([0-9]+))?/?$
     *   GET /s2/llms-ctl-public-post/  ->  404
     *   GET /s2/llms-ctl-public-page/  ->  200      (pages resolve through another rule)
     *
     * Re-running WP_Rewrite::init() after the switch fixes the front but not the cached
     * taxonomy permastructs, which are built once per request from the bootstrap blog's
     * front and are not rebuilt by init(). That was measured too: it recovered the post
     * rules and still left 15 "blog/category/...", "blog/tag/..." rules on the subsite.
     * There is no supported way to rebuild another blog's rule set in a switched request,
     * so this does not try.
     *
     * Deleting the option is the whole job. WP_Rewrite::wp_rewrite_rules() regenerates
     * and saves whenever the option is empty, on that blog's own next request, with the
     * plugin loaded and init:0 having already added our rule. That is not a hopeful
     * reading of core: it is exactly how a brand new site picks the rule up today, which
     * is the reason this defect looked intermittent for five rounds.
     *
     * This was latent until 8.5.4 only because activate() never ran. on_new_site() has
     * always run, so until now a site created on a network was handed the bootstrap
     * blog's rules on creation.
     *
     * @return void
     */
    private static function invalidate_rewrite_rules()
    {
        delete_option('rewrite_rules');
    }

    public function on_new_site($new_site) {
        // wp_initialize_site fires on ordinary front-end and WP-CLI requests, not
        // only in the admin, and is_plugin_active_for_network() lives in an admin
        // include. WordPress 7.0 loads that file from wp-settings.php on every
        // request so the call cannot fail there, but 5.8, which is this plugin's
        // declared floor, does not, so programmatic site creation on a 5.8 network
        // fatals without this.
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!is_plugin_active_for_network(plugin_basename(WEBSITE_LLMS_TXT_FILE))) {
            return;
        }
        // Delete rather than flush, for the reason written out in full on
        // invalidate_rewrite_rules(): a flush inside a switch_to_blog() writes THIS
        // request's blog's rules into the new site and 404s every post on it. The new
        // site rebuilds its own rules on its own first request, with the plugin loaded
        // and our rule already registered on init.
        switch_to_blog($new_site->blog_id);
        self::invalidate_rewrite_rules();
        restore_current_blog();
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
        // The page is registered under tools.php (see add_admin_menu). Pointing at
        // admin.php resolves the hook as toplevel_page_llms-file-manager, which was
        // never registered, so WordPress rejects it with "Sorry, you are not allowed
        // to access this page."
        $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=llms-file-manager')) . '">' . __('Settings', 'website-llms-txt') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function render_admin_page() {
        include WEBSITE_LLMS_TXT_DIR . 'admin/admin-page.php';
    }

    public function handle_cache_clearing() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('clear_caches', 'clear_caches_nonce');
        set_time_limit(0);
        do_action('llms_clear_seo_caches');
        $this->add_rewrite_rule();
        flush_rewrite_rules();

        // Rebuild the file in this request instead of scheduling a WP-Cron
        // event: on hosts where cron never fires, the scheduled rebuild
        // silently never ran and the file stayed stale after "Clear caches".
        // The cached post data is kept (only missing rows are refilled), so
        // this stays cheap; the full re-crawl remains "Delete and recreate".
        if ($this->generator) {
            // Marked inline rather than through generation_hooks(): this path calls
            // update_llms_file() directly instead of firing an action.
            $this->mark_generation_run();
            $this->generator->update_llms_file();
        }


        // tools.php, not admin.php. The page is a tools.php submenu, so redirecting
        // through admin.php lands on "Sorry, you are not allowed to access this page"
        // and the cache_cleared notice below can never render.
        wp_safe_redirect(add_query_arg(array(
            'page' => 'llms-file-manager',
            'cache_cleared' => 'true',
            '_wpnonce' => wp_create_nonce('llms_cache_cleared')
        ), admin_url('tools.php')));
        exit;
    }

    /**
     * Register the llms.txt rewrite rule on the current blog.
     *
     * Static because activate() is static and calls it during an activation request in
     * which no LLMS_Core instance exists. It uses no instance state, and the existing
     * $this->add_rewrite_rule() call sites keep working unchanged.
     *
     * @return void
     */
    public static function add_rewrite_rule() {
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
        $raw_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $request_uri = $raw_uri ? trim(wp_parse_url($raw_uri, PHP_URL_PATH), '/') : '';

        if ($request_uri === 'llms.txt') {
            $disable_noindex = $settings['noindex_header'] ?? '';
            if ( !$disable_noindex ) {
                header('X-Robots-Tag: noindex');
            }
        }

        if (get_query_var('llms_txt')) {
            $latest_post = apply_filters('get_llms_content', '');
            if ($latest_post) {
                header('Content-Type: text/plain; charset=utf-8');
                echo esc_html($latest_post);
                exit;
            }
        }
    }
}