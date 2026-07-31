<?php
/**
 * Access control for llms.txt generation.
 *
 * Decides whether an anonymous, cookie-less visitor can read a given post. This is
 * the only place that decision is made.
 *
 * Three rules govern everything in this file.
 *
 * 1. It never consults the current user. Generation runs as an administrator on the
 *    admin path and as nobody on cron, and a file whose contents depend on which of
 *    those ran is a leak by definition.
 * 2. It reads data, it does not call the gating plugins. Two calls are specifically
 *    forbidden and are named at their reader below: pmpro_has_membership_access()
 *    fatals on PHP 8 when a taxonomy is unregistered, and members_can_user_view_post()
 *    rewrites postmeta site wide as a side effect.
 * 3. Almost every polarity here looks like it could be simplified and almost every one
 *    of them was established by a test that failed first. The comments say which. Do
 *    not "tidy" any of them without re-running the validation that produced them. That
 *    validation is the reader-trap validation and the tier 3 detection spike written up
 *    with the 8.5.4 release material, and the harnesses kept beside them under
 *    restriction-validation. Ask whoever holds that material, re-run it, and only then
 *    tidy. Do not take a polarity out on a reading of the code alone.
 * 4. It fails CLOSED when it cannot determine readability. A state where this class does
 *    not know what is restricted produces "not publicly readable", never "publicly
 *    readable": a failed query, a throw while building the context, a call made before
 *    the post type registry is complete, or a switched blog whose gating plugins this
 *    request never loaded. Every one of those paths records a machine readable reason,
 *    readable through reasons(), for the admin summary.
 *
 *    That is NOT a licence to over-remove where the answer CAN be determined. A state
 *    this class can evaluate has to be evaluated correctly, and destroying a customer's
 *    legitimately public content is a defect of the same weight as a leak. Two places in
 *    here exist only because of that symmetry: site_gate_exempt(), which keeps the pages
 *    a site wide gate demonstrably still serves, and cc_classify_rule(), which asks
 *    Content Control's registry instead of widening a rule name that restricts nothing.
 *
 * THE DEFAULT IS BLUNT EXCLUSION, and everything below about precision is opt in.
 *
 * Three rounds of verification put roughly five defects per round into the precise
 * readers, one of them critical each time, about half of them introduced by the
 * previous round's own fixes. The defects cluster in precision, not in detection. So
 * the policy is:
 *
 *  - if a gating plugin is LOADED, every public post type is excluded and nothing is
 *    evaluated per post. Detection is by symbol, which is the one thing three rounds
 *    never got wrong;
 *  - the precise readers stay in this file, comments and polarities intact, and run
 *    only when the site owner turns 'precise_access' on in llms_generator_settings.
 *    Their residual defects are then an informed choice rather than a default on
 *    vulnerability;
 *  - the escape hatch is PER POST, the '_llmstxt_force_include' meta, because on a
 *    membership site the gated and the public content are usually the same post types,
 *    so a per post type override is either useless or reintroduces the vulnerability;
 *  - a site with no gating plugin must produce output byte identical to 8.5.3, so the
 *    blunt path issues no query at all beyond the primary key lookup on the batch.
 *
 * Three tiers, all of which are the PRECISE path:
 *
 * Tier 1, per post restriction evidence. One reader per plugin, each independently
 * testable, driven off one site wide candidate query.
 * Tier 2, site wide and post type gates read as options. These withhold content while
 * leaving zero per post trace, so a purely per post reader sees a fully public site.
 * Tier 3, existence detection for Restrict User Access and Content Control. Answers
 * "does this plugin gate any post content", never "which posts", and its answer is
 * always a superset of the truly restricted set.
 *
 * Tier 2 is the exception: a site wide gate is not a precision question, it is an
 * option read, and it is evaluated on both paths. WooCommerce coming soon mode is one
 * of those and is the most populous gate this class handles.
 *
 * @package Website_LLMs_txt
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LLMS_Access {

    /**
     * Bytes of meta_value the candidate query returns per row.
     *
     * The candidate query is site wide, so a reader that registers a fat meta key
     * would otherwise pull the whole column into memory. Truncation is safe here
     * because every value a reader compares is either a short scalar or a serialized
     * array whose sentinel sits in the first few bytes; a truncated serialized value
     * fails to unserialize and lands on "restricted", never on "public".
     *
     * Ultimate Member is the one key that does not fit and it is deliberately not
     * read through this path. See um_restricted_ids().
     */
    const META_VALUE_LIMIT = 100;

    /** Depth guard for ancestor walks, mirroring Members' own guard of 100. */
    const ANCESTOR_DEPTH = 100;

    /**
     * Where the two settings this class reads live, and what they are called.
     *
     * Both default to the SAFE value when absent, which is what every site that has
     * never opened the settings screen has.
     *
     *  SETTINGS_OPTION[PRECISE_SETTING]  bool, default false. True runs the precise per
     *                                    plugin readers. False is blunt exclusion.
     *  INCLUDE_META on a post, value '1' the owner asserts this post is publicly
     *                                    readable. Overrides exclusion, never the
     *                                    deterministic gate.
     *
     * The option is the same one the generator already reads, so it is in the options
     * cache by the time this class runs and costs no query.
     */
    const SETTINGS_OPTION = 'llms_generator_settings';
    const PRECISE_SETTING = 'precise_access';
    const INCLUDE_META    = '_llmstxt_force_include';

    /**
     * Depth guard for Content Control's condition tree.
     *
     * cc_flatten() walks $item['query']['items'], which arrives from unserialize()
     * on a database row, and unserialize() can rebuild a self referential array
     * through an R: reference. Content Control's own UI nests a handful of levels.
     */
    const CC_FLATTEN_DEPTH = 20;

    /**
     * Every memo in this class is keyed by blog id.
     *
     * Under switch_to_blog() the table names in $wpdb, the options cache and the
     * term tables all retarget, so the SQL here follows the switch while a plain
     * static memo does not, which is the worst of the two arrangements. Blog ID
     * ranges overlap almost completely, because each blog's AUTO_INCREMENT starts
     * at 1, so a memo keyed by bare post_id would judge blog B's post 42 on blog
     * A's meta: a leak in one direction and an over-removal in the other.
     *
     * flush() is also hooked to switch_blog, so the keying is belt and braces.
     * Keying alone is still not sufficient for multisite; see
     * unloaded_gating_plugins() for the part it cannot fix.
     */

    /** @var array blog_id => array Request scoped memo of readers, options, meta and sets. */
    private static $ctx = array();

    /** @var array blog_id => bool Tier 2 site wide verdict, computed once per run. */
    private static $site_gated = array();

    /** @var array blog_id => string What made site_is_gated() true, for the admin summary. */
    private static $gate_source = array();

    /** @var array blog_id => bool Is the precise per plugin path switched on for this site. */
    private static $precise = array();

    /** @var array blog_id => [ key => label ] Gating plugins loaded, by symbol. Blunt path. */
    private static $blunt = array();

    /** @var array blog_id => array WooCommerce coming soon verdict. */
    private static $wc_gate = array();

    /** @var array blog_id => [ post_id => true ] Posts the owner asserted are public. */
    private static $forced = array();

    /** @var array blog_id => array Tier 2 + tier 3 post type verdicts, computed once per run. */
    private static $gated_types = array();

    /** @var array blog_id => [ post_id => post_parent ], filled in batches by prime_parents(). */
    private static $parents = array();

    /** @var array blog_id => array Parsed wc_memberships_rules, shared by the gate and the set builder. */
    private static $wc_rules = array();

    /** @var array blog_id => array Tier 3 verdicts. */
    private static $rua_detect = array();

    /** @var array blog_id => array Tier 3 verdicts. */
    private static $cc_detect = array();

    /** @var array blog_id => bool A read this class depends on failed, so nothing is known. */
    private static $failed = array();

    /**
     * Machine readable record of why content was withheld, for the admin summary.
     *
     * @var array blog_id => [ [ code, detail, count ] ]
     *
     * Codes, all of which mean "withheld because the answer could not be
     * established" unless marked otherwise.
     *
     * WHAT 'count' MEANS depends on the code, and the two are marked below. A code
     * marked PER POST carries a count of distinct post ids, deduped across the two
     * passes a generation run makes over the site. Every other code carries a count of
     * how many times the thing happened in the request, which for a run that makes two
     * passes can legitimately be 2. See record_reason().
     *
     *   called_before_registries  asked before the post type and taxonomy
     *                             registries were complete, so tier 3 and the
     *                             taxonomy scoping could not be evaluated
     *   blog_plugins_unreadable   multisite: a gating plugin is active on this
     *                             blog, is NOT active on the blog this request
     *                             bootstrapped on, and was never loaded, so nothing
     *                             here can read what it restricts
     *   blog_mismatch             the per run context belongs to another blog
     *   query_failed              a read this class depends on returned an error
     *   context_threw             building the per run context threw
     *   site_gate_threw           the tier 2 site wide test threw
     *   gated_types_threw         the tier 2 plus tier 3 post type pass threw
     *   cc_tree_too_deep          Content Control's condition tree exceeded
     *                             CC_FLATTEN_DEPTH, so it could not be classified
     *   reader_callback_invalid   informational: a registered reader had no
     *                             callable callback and was skipped
     *   um_site_gate_exceptions   informational: UM Global Site Access is on with
     *                             documented exceptions, which are served
     *   site_wide_gate            informational: a tier 2 site wide gate is
     *                             configured, which is why the file is empty or
     *                             nearly so. Names the plugin and the setting
     *   reader_threw              informational: a precise reader threw on one post and
     *                             the documented fail open applied, so the post was NOT
     *                             withheld for it. PER POST
     *   post_type_gated           informational: a post type was removed wholesale
     *                             by a tier 2 or tier 3 gate, or by the blunt default.
     *                             One line per post type, and the detail names the
     *                             plugin that triggered it. PER POST
     *   blunt_exclusion           informational: a gating plugin is loaded and the
     *                             precise path is off, so the post was excluded
     *                             without being evaluated. Names the plugin. This is
     *                             the default policy, not a failure. PER POST
     *   site_gate_withheld        informational: the site wide gate named by the
     *                             site_wide_gate reason took this many entries out of
     *                             the file. PER POST
     *   reader_restricted         informational: the precise path found restriction
     *                             evidence for this post. Names the plugin. PER POST
     *   force_included            informational: the post carries the owner's per post
     *                             include assertion and was kept despite an exclusion.
     *                             PER POST
     *   gating_plugin_never_loaded informational: multisite, a gating plugin is
     *                             active on the bootstrap blog too and still did
     *                             not load, so it restricts nothing anywhere and
     *                             nothing was withheld for it
     *
     * Every path that empties or shrinks the file records one of these. The
     * informational codes above exist because a file that shrank for a REASON the class
     * understood is not the same event as one that shrank because it could not tell,
     * and spec 3.9's summary has to be able to say which.
     */
    private static $reasons = array();

    /**
     * Which post ids have already been counted under which reason.
     *
     * @var array blog_id => [ 'code|detail' => [ post_id => true ] ]
     *
     * Only the per post codes above populate this. It exists so that a count on a per
     * post code is a count of POSTS rather than of gate decisions, because a generation
     * run puts every post through the gate twice, once for the overview and once for the
     * detailed section. See record_reason(). Kept out of $reasons so that what is
     * serialized into the llms_last_excluded option stays the same three-key shape it
     * has always been, and dropped by flush_reasons() with the ledger.
     */
    private static $reason_ids = array();

    /** @var int|null Blog the request was bootstrapped on, which is what decides plugin loading. */
    private static $boot_blog = null;

    /**
     * @var array|null Gating plugins active on the bootstrap blog, read once per request.
     *
     * Deliberately NOT keyed by blog and deliberately NOT cleared by flush(): it
     * describes the request, not the blog being evaluated, and flush() runs on every
     * switch_blog.
     */
    private static $boot_gating = null;

    /** @var bool Whether flush() has been hooked to switch_blog yet. */
    private static $switch_hooked = false;

    /**
     * Batch predicate. The only entry point the generator calls.
     *
     * Order of decision, and every step of it is load bearing:
     *
     *  1. cannot_determine(). Nothing is known, so nothing is readable, and the per post
     *     include assertion does not apply either. That assertion says "a visitor can
     *     read this post"; in this state we cannot even establish that the post is
     *     published, and honouring it would publish a draft.
     *  2. The DETERMINISTIC gate. posts_for_ids() drops anything that is not published
     *     or that carries a password. Runs before the assertion is read, so the
     *     assertion can never defeat it.
     *  3. The site wide gate, the post type gates and then either blunt exclusion or the
     *     precise readers. Everything excluded here is COLLECTED rather than applied.
     *  4. The include assertion, read in ONE query over exactly the posts it could
     *     change. A site that excludes nothing issues no query here at all.
     *  5. The AND only filter, which may still remove a post the owner asserted. A
     *     developer's exclusion outranks the assertion; the assertion exists to defeat
     *     THIS class, not to defeat a site's own code.
     *
     * @param int[] $post_ids
     * @return array [ post_id => bool ] true when an anonymous visitor can read it.
     */
    public static function filter_publicly_readable(array $post_ids)
    {
        self::hook_switch_blog();

        $ids = array();
        foreach ($post_ids as $raw) {
            $id = absint($raw);
            if ($id) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (!$ids) {
            return array();
        }

        $out = array();
        foreach ($ids as $id) {
            $out[$id] = true;
        }

        // Fail CLOSED when this class cannot determine what is restricted. A state
        // where we do not know what is gated has to produce "not publicly readable",
        // because the alternative is publishing a body a visitor cannot see. Every
        // such path records a machine readable reason for the admin summary.
        $blocked = self::cannot_determine();
        if ($blocked) {
            self::record_reason($blocked['code'], $blocked['detail']);
            return self::withhold_all($out);
        }

        $precise = self::precise_access();

        // The DETERMINISTIC gate, and it runs before anything the include assertion can
        // reach. An ID we cannot resolve is not demonstrably public, so it fails closed.
        // posts_for_ids() also enforces publish + empty post_password, which the read
        // query is expected to enforce too. Doing it here as well is deliberate
        // redundancy: this class has to be correct on its own.
        $posts = self::posts_for_ids($ids);
        foreach ($ids as $id) {
            if (!isset($posts[$id])) {
                $out[$id] = false;
            }
        }

        // Tier 2 site wide. Nothing on the site is publicly readable, whatever any post's
        // own meta says.
        //
        // The gate's own documented exceptions are resolved only on the precise path.
        // They exist so that a site whose front page is demonstrably still served does
        // not lose its whole file, which is an over-removal question, and every one of
        // them is Ultimate Member's. Under blunt exclusion a loaded Ultimate Member
        // already excludes every post type, so resolving them would change no verdict
        // and would issue queries for nothing.
        $site_gated = self::site_is_gated();
        $exempt     = ($site_gated && $precise) ? self::site_gate_exempt() : array();

        $gated_types = self::gated_post_types();
        $wc          = self::wc_coming_soon();

        // The blunt fallback. Symbol detection only: no candidate query, no set builder,
        // no rule parsing, no scope narrowing.
        //
        // On the default path it is every loaded gating plugin. On the precise path it
        // is only the loaded plugins no reader covers, because precise_access means
        // "use a precise reader where one exists and blunt exclusion where none does".
        // Turning precision on must never be the state in which a plugin this class
        // cannot evaluate stops excluding anything. See unreadable_gating_plugins().
        //
        // Empty either way on the ~39k installs that have no gating plugin at all.
        $blunt = $precise ? self::unreadable_gating_plugins() : self::blunt_gating_plugins();

        $ctx = array();
        if ($precise) {
            $ctx = self::context();

            // readers() returns nothing when no covered plugin is loaded, so the
            // candidate query was never issued and there is no ancestor walk to prime.
            if ($ctx['readers']) {
                self::prime_parents($ids, $posts);
            }
        }

        // Fail the WHOLE batch closed when any read this class depends on failed.
        // get_results() returns false on failure and foreach ((array) false) yields an
        // empty map that is indistinguishable from "nothing on this site is
        // restricted", so proceeding here publishes every restricted body. The
        // site wide postmeta scan is the query that times out on a large site while
        // the small primary key lookups succeed, so this is the realistic case, not a
        // theoretical one.
        if (!empty(self::$failed[self::blog_key()])) {
            return self::withhold_all($out);
        }

        // Defence in depth on the memo keying above. If the context was built for
        // another blog we do not know what this blog restricts. Only the precise path
        // builds a context; the blunt path reads no per post data at all, and the
        // switched blog case it would otherwise have to defend against is already
        // failed closed by cannot_determine().
        if ($precise) {
            $blog = self::blog_key();
            if (!isset($ctx['blog_id']) || $ctx['blog_id'] !== $blog) {
                self::record_reason('blog_mismatch', 'context built for blog ' . (isset($ctx['blog_id']) ? $ctx['blog_id'] : 'unknown') . ', called for blog ' . $blog);
                return self::withhold_all($out);
            }
        }

        // Exclusions are COLLECTED rather than applied, so the include assertion can be
        // read in one query over exactly the posts it could change, and so that query is
        // not issued at all when nothing was excluded. [ post_id => [ code, detail ] ].
        $excluded = array();

        foreach ($ids as $id) {
            // Already gone, and gone deterministically. Not reachable by the assertion.
            if (!$out[$id]) {
                continue;
            }

            $post = $posts[$id];
            $type = $post['post_type'];

            if ($site_gated && !isset($exempt[$id])) {
                $excluded[$id] = array(
                    'site_gate_withheld',
                    isset(self::$gate_source[self::blog_key()]) ? self::$gate_source[self::blog_key()] : 'a site wide gate is configured',
                );
                continue;
            }

            // Post type gates. Tier 2, tier 3 and the blunt default all arrive here, and
            // the reason names the plugin that produced them. record_reason() dedupes on
            // code plus detail, so this is one entry per post type carrying a count of
            // how many entries it took out of the file.
            if (isset($gated_types[$type])) {
                $excluded[$id] = array('post_type_gated', $type . ': ' . $gated_types[$type]);
                continue;
            }

            // WooCommerce store pages in store pages only mode. These are ordinary pages
            // of type page, identified by id, so no post type gate can express them.
            if (isset($wc['page_ids'][$id])) {
                $excluded[$id] = array('post_type_gated', $type . ': ' . $wc['detail'] . ' (store page)');
                continue;
            }

            // Blunt exclusion. Reached only for a post type that gated_post_types() did
            // not name, which means a type that is not public, so it is not a duplicate
            // of the branch above: a gating plugin is loaded and nothing here has
            // evaluated this post, so it is not demonstrably public.
            if ($blunt) {
                $excluded[$id] = array(
                    'blunt_exclusion',
                    implode(', ', $blunt) . ($precise
                        ? ' is active and has no per post reader, so content is excluded by default'
                        : ' is active, so content is excluded by default'),
                );
                continue;
            }

            if (!$precise) {
                // No gating plugin, no site wide gate, precise path off. This is the
                // ~39k install case and it ends here, with one query issued in total.
                continue;
            }

            foreach ($ctx['readers'] as $reader) {
                if (empty($reader['callback']) || !is_callable($reader['callback'])) {
                    continue;
                }
                $restricted = false;
                try {
                    $restricted = (bool) call_user_func($reader['callback'], $id, $post, $ctx);
                } catch (\Throwable $e) {
                    // A reader that throws must not be able to empty the file. This is
                    // the documented fail open: the residual is the same one every
                    // reader already carries, that a runtime filter inside the gating
                    // plugin can move the real answer either way. It is recorded so it
                    // is visible in the admin summary rather than silent.
                    $restricted = false;
                    self::record_reason(
                        'reader_threw',
                        (isset($reader['label']) ? $reader['label'] : 'reader') . ': ' . $e->getMessage(),
                        $id
                    );
                }
                if ($restricted) {
                    $excluded[$id] = array(
                        'reader_restricted',
                        (isset($reader['label']) ? $reader['label'] : 'reader') . ': restriction evidence on this post',
                    );
                    break;
                }
            }
        }

        // The owner's per post assertion that a post is publicly readable. One query per
        // run, issued only because something was excluded. It defeats every exclusion
        // above and none of the deterministic gate, which has already run.
        if ($excluded) {
            $forced = self::forced_include_set();

            // The post id is passed on both of these because both counts are counts of
            // posts on the admin card. A run puts every post through here twice, once
            // for the overview and once for the detailed section, and without the id the
            // card reported exactly double. See record_reason().
            foreach ($excluded as $id => $why) {
                if (isset($forced[$id])) {
                    self::record_reason(
                        'force_included',
                        $posts[$id]['post_type'] . ': kept by the per post include setting',
                        $id
                    );
                    continue;
                }
                $out[$id] = false;
                self::record_reason($why[0], $why[1], $id);
            }
        }

        // AND only. This filter may turn true into false and never the reverse, so a
        // third party cannot use it to re-open drafts, password protected posts or
        // membership restricted bodies. Deliberate inclusion is a separate, differently
        // named opt in and is not this filter's job.
        foreach ($ids as $id) {
            if ($out[$id]) {
                $out[$id] = (bool) apply_filters(
                    'llms_post_is_publicly_readable',
                    true,
                    $id,
                    isset($posts[$id]) ? $posts[$id]['post_type'] : ''
                );
            }
        }

        return $out;
    }

    /**
     * Tier 2 site wide short circuit, computed once per generation run.
     *
     * True means no post on the site is readable by an anonymous visitor, whatever its
     * own meta says. Each of the three sources below was verified by fetching a post
     * carrying zero restriction meta as a stranger: Members and Ultimate Member redirect
     * it to a login screen, WooCommerce replaces it with the coming soon template.
     *
     * This runs on BOTH paths. A site wide gate is an option read, not a precision
     * question, and getting it wrong in the leak direction publishes an entire site.
     *
     * WHAT IT DOES NOT MEAN, and this has already misled one reader of the admin
     * surface. It is not "is anything being left out". A site where a loaded gating
     * plugin has caused blunt exclusion of every public post type answers FALSE here,
     * because that exclusion is expressed entirely through gated_post_types() and this
     * method stays reserved for a genuine site wide gate: Members' private_blog,
     * Ultimate Member's global site access, WooCommerce coming soon in whole site mode.
     * The distinction is real, it decides what the summary tells an owner to change,
     * and it also decides whether site_gate_exempt() is consulted at all.
     *
     * A caller that wants "is this run removing content" has to ask both, the way
     * LLMS_Generator::defer_empty_headings() and the settings screen already do:
     *
     *     LLMS_Access::site_is_gated() || LLMS_Access::gated_post_types()
     *
     * Verdicts are unaffected either way. filter_publicly_readable() returns false for
     * every id on both shapes, and it is the only thing the generator consumes.
     *
     * @return bool
     */
    public static function site_is_gated()
    {
        self::hook_switch_blog();

        $blog = self::blog_key();
        if (isset(self::$site_gated[$blog])) {
            return self::$site_gated[$blog];
        }

        // Asked before the registries are complete. Do not memoize a verdict computed
        // against a half built registry, and fail closed.
        $blocked = self::cannot_determine();
        if ($blocked) {
            self::record_reason($blocked['code'], $blocked['detail']);
            return true;
        }

        try {
            $gated  = false;
            $source = '';

            // Members private site mode. members_settings['private_blog'] redirects every
            // front end URL to wp-login.php. Default 0.
            if (self::plugin_active('members')) {
                $settings = self::option_array('members_settings');
                if (!empty($settings['private_blog'])) {
                    $gated  = true;
                    $source = 'Members: private site mode';
                }
            }

            // Ultimate Member Global Site Access. um_options['accessible'] is stored as an
            // integer; 2 is "Logged In Users" and 302s the whole front end to the UM login
            // page. Compared loosely because UM compares loosely.
            if (!$gated && self::plugin_active('um')) {
                $opts = self::option_array('um_options');
                if (isset($opts['accessible']) && 2 == $opts['accessible']) {
                    $gated  = true;
                    $source = 'Ultimate Member: Global Site Access is set to logged in users';
                }
            }

            // WooCommerce coming soon mode with store pages only OFF. Verified against
            // anonymous curl on MySQL with WooCommerce 10.9.4: every front end URL,
            // including posts and pages that have nothing to do with the store, serves
            // the coming soon template and the post body is absent. See
            // wc_coming_soon() for the option semantics, which are not symmetric.
            //
            // This is not a precision question and it is not behind 'precise_access'.
            // It is two option reads, it leaves zero per post trace, and it is the state
            // every WooCommerce store is in between installing the plugin and launching.
            if (!$gated) {
                $wc = self::wc_coming_soon();
                if ('site' === $wc['mode']) {
                    $gated  = true;
                    $source = $wc['detail'];
                }
            }

            // Informational, and recorded HERE rather than at the caller so that it is
            // recorded exactly once per run whichever entry point asked first. Without it
            // a customer whose file just emptied because someone ticked "private site"
            // got an empty reasons() and spec 3.9's admin summary had nothing to show.
            // Anything the exceptions save is reported separately by site_gate_exempt().
            if ($gated) {
                self::record_reason('site_wide_gate', $source);
                self::$gate_source[$blog] = $source;
            }
        } catch (\Throwable $e) {
            // Nothing here is under this plugin's control: get_option() runs third party
            // filters. A throw means the gate could not be established, which is a
            // fail closed state, and it is not memoized so a later caller can retry.
            self::record_reason('site_gate_threw', $e->getMessage());
            return true;
        }

        self::$site_gated[$blog] = $gated;
        return self::$site_gated[$blog];
    }

    /**
     * Post IDs that stay readable even though site_is_gated() is true.
     *
     * site_is_gated() keeps its contract: it answers "is a site wide gate configured",
     * and that stays true when the gate has exceptions. The exceptions are resolved
     * here instead, because emptying the whole file for a site whose front page and
     * three marketing pages are demonstrably served to an anonymous visitor is an
     * over-removal, and the fail closed policy does not license over-removal where the
     * answer CAN be determined.
     *
     * Ultimate Member's Global Site Access has three documented escapes, all in
     * um_access_check_global_settings():
     *
     *  - home_page_accessible (includes/core/class-access.php:1799-1810), which
     *    DEFAULTS TO true and is written into um_options at install
     *    (includes/class-config.php:696), so most gated UM sites have it on;
     *  - category_page_accessible (:1822-1828), which exempts category archives. An
     *    archive is not a post body, so it yields no post id and is recorded for the
     *    admin summary only;
     *  - access_exclude_uris (:1843-1875), an explicit list of URLs compared against
     *    the current URL with and without a trailing slash. um_options['access_redirect']
     *    is the FIRST entry of that same list (:1845-1848), before the configured URLs
     *    are merged in, so the page an admin picked as the redirect target is served too.
     *    Empty on a default install, where UM falls back to its login page without
     *    adding it to the list, so an empty value exempts nothing.
     *
     * And a fourth escape that is not in that function at all, which is why it was missed
     * the first time: the global gate NEVER RUNS for a post that carries its own UM
     * restriction settings. On the_posts, for a singular query, $this->singular_page is
     * set for every post whose get_post_privacy_settings() returns a restriction array
     * (:1436-1445), and template_redirect() returns at :1553-1556 when
     * $is_singular && $this->singular_page, which is BEFORE
     * um_access_check_global_settings fires at :1624-1640. Those posts are decided by
     * is_restricted() alone, which reader_um() already models. Reachable from UM's own UI
     * in two clicks: Global Site Access "Logged In Users" plus one page marked
     * "Everyone". See um_site_gate_exempt_ids().
     *
     * Members' private site mode has two of its own,
     * members/inc/functions-private-site.php:111-127: the WooCommerce My Account page
     * and the BuddyPress register and activate pages. They are deliberately NOT
     * resolved here. Both are functional pages whose bodies are shortcode driven, so
     * losing them from llms.txt costs nothing a customer would notice, and guessing at
     * BuddyPress' page ids from this class would cost more than it returns.
     *
     * @return array [ post_id => true ]
     */
    private static function site_gate_exempt()
    {
        $exempt = array();

        if (!self::plugin_active('um')) {
            return $exempt;
        }

        $opts = self::option_array('um_options');
        if (!isset($opts['accessible']) || 2 != $opts['accessible']) {
            return $exempt;
        }

        $count = 0;

        // UM()->options()->get() reads um_options and falls through to '' for a missing
        // key, so an absent key is falsy here too. Mirroring that rather than the
        // documented default is deliberate.
        if (!empty($opts['home_page_accessible'])) {
            $count++;
            if ('page' === get_option('show_on_front')) {
                $front = absint(get_option('page_on_front'));
                if ($front) {
                    $exempt[$front] = true;
                }
            }
            // With the blog index as the front page there is no single post to exempt.
            // The listed posts keep their own permalinks, which UM still gates.
        }

        if (!empty($opts['category_page_accessible'])) {
            $count++;
        }

        // access_redirect is the first entry of UM's own exclusion list, so it gets the
        // same treatment as the list it heads. One page per site, always a page a
        // stranger can read, and lost on every site where an admin set a custom redirect
        // target.
        $uris = array();
        if (isset($opts['access_redirect'])) {
            $uris[] = $opts['access_redirect'];
        }
        if (!empty($opts['access_exclude_uris']) && is_array($opts['access_exclude_uris'])) {
            foreach ($opts['access_exclude_uris'] as $uri) {
                $uris[] = $uri;
            }
        }

        foreach ($uris as $uri) {
            // untrailingslashit() is UM's, at :1846 and :1850. It compares the current URL
            // both with and without the trailing slash, so the stored form does not
            // matter; url_to_postid() resolves either.
            $uri = trim((string) $uri);
            if ('' === $uri) {
                continue;
            }
            $count++;
            // url_to_postid() needs rewrite rules, which is one of the reasons this
            // class refuses to answer before the registries are up.
            $id = absint(self::safe_call('url_to_postid', array($uri), 0));
            if ($id) {
                $exempt[$id] = true;
            }
        }

        // Posts carrying their own UM restriction settings, which the global gate never
        // reaches. These are added to the exempt set rather than judged here, so
        // reader_um() decides them in the normal way: a post marked "Members only" inside
        // a globally gated site is still withheld, and one marked "Everyone" is served,
        // which is exactly what an anonymous visitor measures.
        $own = self::um_site_gate_exempt_ids();
        foreach ($own as $id => $unused) {
            $exempt[$id] = true;
        }

        if ($count || $own) {
            self::record_reason(
                'um_site_gate_exceptions',
                'Ultimate Member Global Site Access is on with ' . $count . ' exception(s) configured; '
                    . count($exempt) . ' resolved to a post id and stay readable, of which '
                    . count($own) . ' carry their own restriction settings and are decided per post'
            );
        }

        return $exempt;
    }

    /**
     * Post IDs whose own Ultimate Member restriction settings decide their access, so
     * Global Site Access never applies to them.
     *
     * This is get_post_privacy_settings() (includes/core/class-access.php:1961-2111)
     * expressed as a set: every post for which it returns an ARRAY rather than false,
     * because that is exactly the condition that sets $this->singular_page and makes
     * template_redirect() return before the global gate runs.
     *
     * Note the polarity carefully. The question is NOT "does the post's own setting
     * restrict it", it is "does the post have its own setting at all". A term marked
     * "Everyone" still takes its posts out of the global gate; a term whose custom flag
     * is OFF does not, and that is the control that keeps this honest.
     *
     * Three guards, each of which is UM's own and each of which is a leak if dropped:
     *
     *  - the POST level branch is behind the post type option (:2007), so a post of an
     *    unticked type carrying its own settings falls through to the term scan and, with
     *    no restricted term, back to false. The global gate DOES run for it. This is why
     *    the set is not um_custom_access_ids(), which has no post type scope.
     *  - the term scan uses get_object_taxonomies( $post ) (:2015, :2081), so a term of a
     *    taxonomy no longer registered for the post's type yields nothing. Same guard as
     *    wcm_guarded_ids().
     *  - UM's own core pages (login, register, account, logout, password reset) are
     *    excluded from privacy entirely at :1990-1997 and return false, so the global gate
     *    runs for them whatever they carry.
     *
     * @return array [ post_id => true ]
     */
    private static function um_site_gate_exempt_ids()
    {
        global $wpdb;

        $opts   = self::option_array('um_options');
        $exempt = array();

        // Post level. Truthy post types only, same polarity as the reader.
        $types = isset($opts['restricted_access_post_metabox']) && is_array($opts['restricted_access_post_metabox'])
            ? $opts['restricted_access_post_metabox']
            : array();
        $enabled = array();
        foreach ($types as $type => $on) {
            $type = (string) $type;
            if (!empty($on) && '' !== $type) {
                $enabled[$type] = $type;
            }
        }

        if ($enabled) {
            // Same fragments, and the same reason for them, as um_custom_access_ids():
            // the value is a fat serialized array and only ids need to come back.
            $custom = array(
                's:26:"_um_custom_access_settings";b:1;',
                's:26:"_um_custom_access_settings";i:1;',
                's:26:"_um_custom_access_settings";s:1:"1";',
            );

            $in  = "'" . implode("','", array_map('esc_sql', array_values($enabled))) . "'";
            $sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                      INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = 'um_content_restriction'
                       AND p.post_type IN ({$in})";
            $sql .= ' AND (' . self::like_clause('pm.meta_value', $custom) . ')';
            $sql .= ' AND (' . self::like_clause('pm.meta_value', array('s:14:"_um_accessible";')) . ')';

            $ids = self::db_col($sql, 'um_site_gate_exempt_ids.posts');
            if (false !== $ids) {
                $exempt = self::id_set($ids);
            }
        }

        // Term level. Not behind the post type option: the term scan is reached whenever
        // the post level branch did not return.
        $taxonomies = array();
        $enabled_tax = isset($opts['restricted_access_taxonomy_metabox']) && is_array($opts['restricted_access_taxonomy_metabox'])
            ? $opts['restricted_access_taxonomy_metabox']
            : array();
        foreach ($enabled_tax as $taxonomy => $on) {
            $taxonomy = (string) $taxonomy;
            if (empty($on) || '' === $taxonomy || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $taxonomies[$taxonomy] = $taxonomy;
        }

        if ($taxonomies) {
            $in   = "'" . implode("','", array_map('esc_sql', array_values($taxonomies))) . "'";
            $rows = self::db_results(
                "SELECT tt.term_taxonomy_id AS term_taxonomy_id, tt.taxonomy AS taxonomy, tm.meta_value AS meta_value
                   FROM {$wpdb->termmeta} tm
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                  WHERE tm.meta_key = 'um_content_restriction' AND tt.taxonomy IN ({$in})",
                'um_site_gate_exempt_ids.terms'
            );

            $by_taxonomy = array();
            foreach ((array) $rows as $row) {
                $restriction = self::safe_unserialize($row['meta_value']);
                // UM's own two tests at :2096-2101, and NOTHING about the value: a term
                // set to "Everyone" still returns its array and still takes the post out
                // of the global gate.
                if (!is_array($restriction) || empty($restriction['_um_custom_access_settings'])) {
                    continue;
                }
                if (!isset($restriction['_um_accessible'])) {
                    continue;
                }
                $by_taxonomy[(string) $row['taxonomy']][] = absint($row['term_taxonomy_id']);
            }

            $where = array();
            foreach ($by_taxonomy as $taxonomy => $tt_ids) {
                $tt_ids = array_values(array_unique(array_filter($tt_ids)));
                if (!$tt_ids) {
                    continue;
                }
                $object = self::safe_call('get_taxonomy', array($taxonomy), null);
                $otypes = $object ? array_values(array_filter((array) $object->object_type)) : array();
                if (!$otypes) {
                    continue;
                }
                $where[] = '(tr.term_taxonomy_id IN (' . implode(',', $tt_ids) . ")"
                    . " AND p.post_type IN ('" . implode("','", array_map('esc_sql', $otypes)) . "'))";
            }

            if ($where) {
                $ids = self::db_col(
                    "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr
                       INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                      WHERE " . implode(' OR ', $where),
                    'um_site_gate_exempt_ids.objects'
                );
                if (false !== $ids) {
                    foreach (self::id_set($ids) as $id => $unused) {
                        $exempt[$id] = true;
                    }
                }
            }
        }

        // UM's core pages are excluded from privacy at :1990-1997 whatever they carry, so
        // the global gate applies to them and they are not exempt. The WPML duplicate
        // route into um_is_core_post() (_um_wpml_<page> post meta, um-short-functions.php
        // :1348) is not resolved: it costs a second meta scan to save a login page.
        foreach (array('login', 'register', 'account', 'logout', 'password-reset') as $page) {
            $id = isset($opts['core_' . $page]) ? absint($opts['core_' . $page]) : 0;
            if ($id) {
                unset($exempt[$id]);
            }
        }

        return $exempt;
    }

    /**
     * Post types excluded wholesale for this run. Tier 2 plus tier 3.
     *
     * Only gates with no per post escape hatch belong here. WP-Members is deliberately
     * absent: its post type default is overridable in both directions by _wpmem_block,
     * so it is a tier 1 reader that starts from the option rather than a wholesale gate.
     *
     * @return array [ post_type => reason ]
     */
    public static function gated_post_types()
    {
        self::hook_switch_blog();

        $blog = self::blog_key();
        if (isset(self::$gated_types[$blog])) {
            return self::$gated_types[$blog];
        }

        // Asked before the registries are complete. get_post_types(),
        // get_taxonomies() and post_type_exists() all answer against a half built
        // registry there, so no verdict is memoized and the caller fails closed.
        $blocked = self::cannot_determine();
        if ($blocked) {
            self::record_reason($blocked['code'], $blocked['detail']);
            $all = array();
            foreach (self::public_post_types() as $type) {
                $all[$type] = 'Access could not be determined: ' . $blocked['code'];
            }
            return $all;
        }

        try {
            $gated = self::build_gated_post_types();
        } catch (\Throwable $e) {
            // maybe_unserialize() on database controlled bytes is reachable from
            // detect_cc(), and every registry lookup below runs third party filters. A
            // throw means the post type scope is unknown, so everything public is
            // withheld and the reason is recorded. Not memoized.
            self::record_reason('gated_types_threw', $e->getMessage());
            $all = array();
            foreach (self::public_post_types() as $type) {
                $all[$type] = 'Access could not be determined: gated_types_threw';
            }
            return $all;
        }

        self::$gated_types[$blog] = $gated;
        return self::$gated_types[$blog];
    }

    /**
     * The body of gated_post_types(), so the memo, the registry guard and the throw
     * guard all live in one place.
     *
     * @return array [ post_type => reason ]
     */
    private static function build_gated_post_types()
    {
        $gated = array();

        // THE DEFAULT. A gating plugin is loaded and the precise path is off, so every
        // public post type comes out of the file and nothing below this branch runs: no
        // candidate query, no set builder, no rule parsing, no tier 3 detection.
        //
        // The reason names the plugin because the admin summary is what explains a file
        // that just got smaller, and "your file shrank" is only actionable if the owner
        // can see which plugin did it and reach the per post include control from there.
        //
        // A PRODUCT CONSEQUENCE THAT HAS TO REACH THE RELEASE NOTES. Spec 3.5 accepted
        // that "a Restrict User Access site with no content rules produces a file byte
        // identical to fail open", and tier 3 was built to keep that true. Blunt
        // exclusion deliberately reverses it: RUA is loaded, so every public post type
        // comes out and that site loses its file until the owner turns per post
        // evaluation on or includes posts one at a time. Content Control is the same
        // shape. A site that runs either purely for dashboard or capability control,
        // gating no content at all, will be surprised, and that is the agreed trade for
        // not deciding a security question from a rule parser three rounds of
        // verification kept finding defects in. Do not "fix" it here.
        if (!self::precise_access()) {
            $blunt = self::blunt_gating_plugins();
            if ($blunt) {
                $detail = implode(', ', $blunt)
                    . ': content is left out by default while an access control plugin is active';
                foreach (self::public_post_types() as $type) {
                    $gated[$type] = $detail;
                }
            }

            // WooCommerce coming soon in store pages only mode still applies. It is a
            // tier 2 option read rather than a precision question, so it is evaluated on
            // both paths, and on a store with no membership plugin it is the only thing
            // standing between an unlaunched catalogue and llms.txt.
            return self::add_wc_coming_soon_types($gated);
        }

        // THE PRECISE PATH STILL EXCLUDES BLUNTLY WHERE IT HAS NO READER, and this
        // branch is the whole of that rule at post type scope.
        //
        // Turning per post evaluation on used to publish every post on a MemberPress,
        // LifterLMS, LearnDash or Tutor LMS site: those four are carried by detection
        // alone, so with precision on there was no reader to consult, no post type gate
        // to apply and nothing left to exclude them. Measured on the same fixture, 16
        // bytes and zero canaries with the setting off against 2,871 bytes and every
        // canary with it on. A documented opt in must not be the thing that removes all
        // protection, and "publish it because nothing here can evaluate it" is the exact
        // opposite of this class's fail closed rule.
        //
        // So precision is per plugin, not per site: a plugin with a reader is evaluated
        // post by post, a plugin without one keeps the default policy.
        $unreadable = self::unreadable_gating_plugins();
        if ($unreadable) {
            $detail = implode(', ', $unreadable)
                . ': content is left out by default because per post evaluation has no reader for this plugin';
            foreach (self::public_post_types() as $type) {
                $gated[$type] = $detail;
            }
        }

        // Restrict Content 3.0 post type restriction. rcp_get_post_restrictions() takes
        // the post type branch first, so this overrides per post meta entirely, in both
        // directions: a post whose own meta says public is still withheld.
        if (self::plugin_active('rcp') && '3.0' === self::rcp_mode()) {
            $types = self::option_array('rcp_restricted_post_types');
            foreach ($types as $type => $rule) {
                if (!empty($rule)) {
                    $gated[$type] = 'Restrict Content: post type restriction';
                }
            }
        }

        // WooCommerce Memberships post type wide rules, but only when nothing on the
        // site can override them. _wc_memberships_force_public is the escape hatch and
        // it is the final AND in the plugin's own test, so if any post carries it the
        // rules have to be expanded to IDs by the set builder instead.
        if (self::plugin_active('wcm')) {
            $rules = self::wc_memberships_rules();
            if ($rules['post_type_wide'] && !$rules['has_force_public']) {
                foreach (array_keys($rules['post_type_wide']) as $type) {
                    $gated[$type] = 'WooCommerce Memberships: post type rule';
                }
            }
        }

        // Tier 3. Existence detection only, so the scope is always a superset.
        foreach (array('rua' => 'Restrict User Access', 'cc' => 'Content Control') as $key => $label) {
            $detect = ('rua' === $key) ? self::detect_rua() : self::detect_cc();
            if (empty($detect['gates_content'])) {
                continue;
            }
            $types = ('site' === $detect['scope']) ? self::public_post_types() : $detect['post_types'];
            foreach ($types as $type) {
                $gated[$type] = $label . ': ' . ('site' === $detect['scope'] ? 'rule scope could not be narrowed' : 'gates this post type');
            }
        }

        return self::add_wc_coming_soon_types($gated);
    }

    /**
     * Add the post types WooCommerce coming soon mode gates, on either path.
     *
     * Only the store pages only mode contributes a post type. The whole site mode is a
     * site wide gate and site_is_gated() carries it, which is a stronger statement than
     * any post type list.
     *
     * @param array $gated
     * @return array
     */
    private static function add_wc_coming_soon_types(array $gated)
    {
        $wc = self::wc_coming_soon();

        if ('store' === $wc['mode']) {
            foreach ($wc['post_types'] as $type) {
                $gated[$type] = $wc['detail'];
            }
        }

        return $gated;
    }

    /**
     * Drop the request scoped memos.
     *
     * Generation runs once per request, so this exists for tests, for the rare caller
     * that changes options mid request, and for switch_blog: hook_switch_blog() wires
     * this to that action, because every datum behind every memo is per site.
     *
     * THE REASON LEDGER SURVIVES THIS, and that is a contract other files depend on.
     * reasons() is a record of what a run DID, not a memo of what the site IS, and the
     * two have opposite lifetimes: the memos have to die whenever the data behind them
     * could have changed, and the record has to live until the request that produced it
     * ends. LLMS_Core::record_excluded_summary() reads reasons() on shutdown, which is
     * after every generator call site has finished, so a flush() anywhere in the
     * generator, in a chunked fill or on a switch_blog would otherwise silently empty
     * the "Excluded content" summary on every gated site. Use flush_reasons() when the
     * record itself has to go, which outside a test is never.
     *
     * @return void
     */
    public static function flush()
    {
        self::$ctx         = array();
        self::$site_gated  = array();
        self::$gate_source = array();
        self::$precise     = array();
        self::$blunt       = array();
        self::$wc_gate     = array();
        self::$forced      = array();
        self::$gated_types = array();
        self::$parents     = array();
        self::$wc_rules    = array();
        self::$rua_detect  = array();
        self::$cc_detect   = array();
        self::$failed      = array();
    }

    /**
     * Drop the reason ledger. Separate from flush() on purpose, see its docblock.
     *
     * @return void
     */
    public static function flush_reasons()
    {
        self::$reasons    = array();
        self::$reason_ids = array();
    }

    /* ---------------------------------------------------------------------------
     * Policy: blunt exclusion by default, precision by opt in
     * ------------------------------------------------------------------------ */

    /**
     * Is the precise per plugin path switched on for this site?
     *
     * Default FALSE, and absent means false, which is what every site that has never
     * opened the settings screen has. The option is the one the generator already
     * reads, so it is in the options cache and this costs no query.
     *
     * empty() rather than a strict comparison because a checkbox stored by the settings
     * API arrives as '1' while a filter or a wp-cli update can leave 1, true or 'yes'.
     * Every falsy form lands on the safe value.
     *
     * @return bool
     */
    private static function precise_access()
    {
        $blog = self::blog_key();
        if (isset(self::$precise[$blog])) {
            return self::$precise[$blog];
        }

        $settings = get_option(self::SETTINGS_OPTION);
        $on       = is_array($settings) && !empty($settings[self::PRECISE_SETTING]);

        self::$precise[$blog] = $on;

        return self::$precise[$blog];
    }

    /**
     * Gating plugins loaded in this request, by symbol only. The blunt path.
     *
     * This is the whole of the default policy's evidence. It asks one question, "is a
     * plugin that can gate content loaded", and it answers with no query, no rule
     * parsing and no per post semantics, which is the property that makes it hard to get
     * wrong. Three rounds of verification produced defects in the per plugin readers at
     * a flat rate and none at all in symbol detection.
     *
     * @return array [ internal key => label ]
     */
    private static function blunt_gating_plugins()
    {
        $blog = self::blog_key();
        if (isset(self::$blunt[$blog])) {
            return self::$blunt[$blog];
        }

        $found = array();
        foreach (self::gating_plugins() as $key => $label) {
            if (self::plugin_active($key)) {
                $found[$key] = $label;
            }
        }

        self::$blunt[$blog] = $found;

        return $found;
    }

    /**
     * The gating plugins the PRECISE path can actually evaluate.
     *
     * Tier 1 has a reader per plugin. Tier 3 has existence detection, which is coarse
     * but is still an evaluation this class performs. Everything else in
     * gating_plugins() is carried by detection alone.
     *
     * This is deliberately a list of what IS covered rather than a list of what is not,
     * so that adding a plugin to gating_plugins() without writing a reader for it lands
     * on blunt exclusion on both paths by default. The failure mode of the opposite
     * arrangement is exactly adv F6: a new entry silently publishes everything the
     * moment an owner ticks the precision box.
     *
     * A third party reader registered through llms_access_readers does NOT move a
     * plugin into this list. That filter adds a reader; it cannot decide that a plugin
     * this class excludes wholesale is now evaluated post by post, because a reader
     * that returned false for everything would then be a way to turn the default policy
     * off from a theme's functions.php, which is the residual the same filter's
     * additions-only contract exists to remove. The cost is over-removal on a site that
     * wrote a genuine reader for one of these four, and the fix for that site is one
     * line here rather than a filter that can empty the policy.
     *
     * @return array [ internal key => true ]
     */
    private static function precise_covered_plugins()
    {
        return array(
            // Tier 1, one reader each.
            'members' => true,
            'rcp'     => true,
            'um'      => true,
            'wpmem'   => true,
            'wcm'     => true,
            'pmpro'   => true,
            // Tier 3, existence detection with a post type scope.
            'rua'     => true,
            'cc'      => true,
        );
    }

    /**
     * Loaded gating plugins the precise path has no way to read.
     *
     * The complement of precise_covered_plugins() within the loaded set, which today is
     * exactly detection_only_plugins() and is computed rather than repeated so it stays
     * true when either list changes.
     *
     * Reached only when precise_access() is on. With it off, blunt_gating_plugins() is
     * already the whole policy and every one of these is in it.
     *
     * @return array [ internal key => label ]
     */
    private static function unreadable_gating_plugins()
    {
        $covered = self::precise_covered_plugins();
        $found   = array();

        foreach (self::blunt_gating_plugins() as $key => $label) {
            if (!isset($covered[$key])) {
                $found[$key] = $label;
            }
        }

        return $found;
    }

    /**
     * WooCommerce coming soon mode.
     *
     * Read out of WooCommerce 10.9.4's own source, ComingSoonHelper.php:
     *
     *   is_site_live()         'yes' !== woocommerce_coming_soon
     *   is_site_coming_soon()  coming_soon === 'yes' AND store_pages_only !== 'yes'
     *   is_store_coming_soon() coming_soon === 'yes' AND store_pages_only === 'yes'
     *
     * THE POLARITY IS NOT SYMMETRIC and this is the part that is easy to get wrong. An
     * ABSENT woocommerce_store_pages_only is the whole site mode, not the store mode,
     * because the test is `!==`. Confirmed by anonymous curl on MySQL: with the option
     * deleted, every post and page on the site served the coming soon template.
     *
     * Store pages, from WCAdminHelper::is_current_page_store_page():
     * the shop, cart, checkout, terms and coming soon PAGES by id, the product post type
     * (single and archive) and product taxonomy archives. my-account is deliberately not
     * in that list and is not gated. Archives carry no post id, so what is left for this
     * class is the product post type plus at most five page ids.
     *
     * WooCommerce is not in gating_plugins(). It is not an access control plugin and
     * having it installed excludes nothing; only this option state does.
     *
     * Residual, accepted rather than coded around: WooCommerce also requires the
     * launch-your-store feature to be enabled, which comes from the
     * woocommerce_admin_features filter and not from an option, so it cannot be read
     * from the database. Disabling that feature while leaving coming soon armed
     * over-removes the product post type and the store pages here. Enabled by default.
     *
     * @return array mode ''|'site'|'store', detail, post_types, page_ids [ id => true ]
     */
    private static function wc_coming_soon()
    {
        $blog = self::blog_key();
        if (isset(self::$wc_gate[$blog])) {
            return self::$wc_gate[$blog];
        }

        $gate = array(
            'mode'       => '',
            'detail'     => '',
            'post_types' => array(),
            'page_ids'   => array(),
        );

        if (self::plugin_active('wc') && 'yes' === get_option('woocommerce_coming_soon')) {
            if ('yes' === get_option('woocommerce_store_pages_only')) {
                $gate['mode']       = 'store';
                $gate['detail']     = 'WooCommerce: coming soon mode is on for the store';
                $gate['post_types'] = array('product');

                // wc_get_page_id( $page ) is get_option( 'woocommerce_' . $page . '_page_id' ),
                // and wc_terms_and_conditions_page_id() is the same shape for terms.
                foreach (array('shop', 'cart', 'checkout', 'terms', 'coming_soon') as $page) {
                    $id = absint(get_option('woocommerce_' . $page . '_page_id'));
                    if ($id) {
                        $gate['page_ids'][$id] = true;
                    }
                }
            } else {
                $gate['mode']   = 'site';
                $gate['detail'] = 'WooCommerce: coming soon mode is on for the whole site';
            }
        }

        self::$wc_gate[$blog] = $gate;

        return $gate;
    }

    /**
     * The owner's per post assertion that a post is publicly readable.
     *
     * ONE query per run, not one per chunk, and it is not issued at all until something
     * has been excluded. Same argument as candidate_meta(): the meta_key index answers a
     * site wide question once, while a per chunk "post_id IN (...)" repeats a query per
     * chunk for the whole pass. Measured on MySQL 8 at 5,005 posts with a gating plugin
     * loaded, the per chunk form cost 11 extra queries and 14 ms per pass and this form
     * costs 1 and under a millisecond, because a site with no overrides has no rows to
     * return.
     *
     * The value test is exact. '1' is the contract, and the settings API writes exactly
     * that for a checkbox. Being lenient here would mean a stale or malformed row
     * publishing a restricted body, and this is the one setting in the plugin whose
     * whole purpose is to defeat a security exclusion.
     *
     * Only post ids come back, never meta_value, so the memory shape is one int per
     * override rather than the row shape candidate_meta() has to bound with
     * META_VALUE_LIMIT.
     *
     * A failed query returns nothing, which leaves every excluded post excluded, and
     * db_col() has already failed the blog closed for every later batch. Both directions
     * are the fail closed one.
     *
     * @return array [ post_id => true ]
     */
    private static function forced_include_set()
    {
        global $wpdb;

        $blog = self::blog_key();
        if (isset(self::$forced[$blog])) {
            return self::$forced[$blog];
        }

        $rows = self::db_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
                self::INCLUDE_META
            ),
            'forced_include_set'
        );

        self::$forced[$blog] = (false === $rows) ? array() : self::id_set($rows);

        return self::$forced[$blog];
    }

    /**
     * Machine readable record of what this class withheld and why, for the admin
     * summary in spec 3.9.
     *
     * Accumulates for the whole request and is NOT cleared by flush(). A caller may
     * flush the memos as often as it likes, including between chunks and on every
     * switch_blog, and still read a complete record of the run afterwards. Entries are
     * deduped on code plus detail with a count, so repeated passes over the same site
     * raise counts rather than growing the list.
     *
     * Per blog, like every other memo here, so a network run reads back only the blog
     * it is standing on.
     *
     * @return array [ [ code, detail, count ] ]
     */
    public static function reasons()
    {
        $blog = self::blog_key();

        return isset(self::$reasons[$blog]) ? array_values(self::$reasons[$blog]) : array();
    }

    /* ---------------------------------------------------------------------------
     * Fail closed plumbing
     * ------------------------------------------------------------------------ */

    /**
     * Memo key. Every datum this class reads is per site.
     *
     * @return int
     */
    private static function blog_key()
    {
        if (function_exists('get_current_blog_id')) {
            return (int) get_current_blog_id();
        }

        return 0;
    }

    /**
     * Record a machine readable reason once per code plus detail, with a count.
     *
     * WHEN A POST ID IS PASSED THE COUNT IS A COUNT OF DISTINCT POSTS, and that is the
     * whole point of the third argument. A generation run evaluates the same posts more
     * than once: generate_overview() and generate_detailed_content() each call the gate
     * over the whole site, and the second pass runs whatever the detailed-section
     * settings are. Without the id set, a site with 76 published pages reported "152
     * pages left out" on the Excluded content card, and a site owner who ticked one
     * include box was told the setting had kept 2 posts. Both measured. The card is what
     * the 8.5.4 changelog and FAQ point an owner at when their file shrinks, so a number
     * that is twice the truth is a support thread, not a cosmetic defect.
     *
     * With no post id the count stays a count of events, which is what the codes that
     * describe the run rather than a post actually mean: query_failed, context_threw,
     * site_wide_gate and the rest are facts about the request. Those can still be
     * recorded twice by two passes, and a count of 2 there means "it happened on both
     * passes", not "two posts". The catalogue on $reasons says which codes are per post.
     *
     * The id set is memory bounded by the number of posts the run excluded, which the
     * run is already holding rows for, and it is dropped by flush_reasons() along with
     * the ledger it belongs to.
     *
     * @param string $code
     * @param string $detail
     * @param int    $post_id Post this reason is about, when it is about one post. 0
     *                        means the reason describes the run, not a post.
     * @return void
     */
    private static function record_reason($code, $detail = '', $post_id = 0)
    {
        $blog = self::blog_key();
        $key  = $code . '|' . $detail;

        $post_id = (int) $post_id;
        if ($post_id > 0) {
            if (isset(self::$reason_ids[$blog][$key][$post_id])) {
                // This post was already counted under this reason on an earlier pass.
                return;
            }
            self::$reason_ids[$blog][$key][$post_id] = true;
        }

        if (!isset(self::$reasons[$blog])) {
            self::$reasons[$blog] = array();
        }
        if (isset(self::$reasons[$blog][$key])) {
            self::$reasons[$blog][$key]['count']++;
            return;
        }

        self::$reasons[$blog][$key] = array(
            'code'   => (string) $code,
            'detail' => (string) $detail,
            'count'  => 1,
        );
    }

    /**
     * Mark this blog's verdicts as unknown, so the batch fails closed.
     *
     * @param string $where
     * @param string $error
     * @return void
     */
    private static function note_failure($where, $error)
    {
        self::$failed[self::blog_key()] = true;
        self::record_reason('query_failed', $where . ': ' . $error);
    }

    /**
     * Turn every verdict in a batch into "not publicly readable".
     *
     * @param array $out
     * @return array
     */
    private static function withhold_all(array $out)
    {
        foreach ($out as $id => $unused) {
            $out[$id] = false;
        }

        return $out;
    }

    /**
     * Can this class answer at all right now? Returns a reason, or false when it can.
     *
     * Two states make the answer unknowable rather than merely imprecise.
     *
     * 1. The registries are not up yet. plugin_active() detects Restrict User Access
     *    and Content Control with post_type_exists(), and RUA registers its post type
     *    on init at priority 99 (restrict-user-access/src/Level/PostType.php:18-24),
     *    Content Control on plain init. taxonomy_exists() in um_term_restricted_ids()
     *    and the get_post_types()/get_taxonomies() calls have the same dependency. So
     *    there is a window in which tier 1 works and tier 3 reports no gating at all.
     *    did_action('init') is not the test: it is already non zero inside the first
     *    init callback, long before priority 99. wp_loaded is, because it fires after
     *    init has finished. Nothing is memoized while this holds, so one early caller
     *    cannot poison the request.
     *
     *    A site with no gating plugin at all, which is roughly 39k of the install base,
     *    is NOT unknowable here: no plugin can appear later in the request, so there is
     *    nothing to wait for and an early call is answered normally.
     *
     * 2. Multisite, and a gating plugin is active on this blog but was not loaded by
     *    this request. See unloaded_gating_plugins().
     *
     * @return array|false [ code, detail ]
     */
    private static function cannot_determine()
    {
        if (!did_action('wp_loaded')) {
            $active = self::gating_plugins_by_option();
            if ($active) {
                return array(
                    'code'   => 'called_before_registries',
                    'detail' => 'asked before wp_loaded with ' . implode(', ', array_keys($active)) . ' active',
                );
            }
        }

        $unloaded = self::unloaded_gating_plugins();
        if ($unloaded) {
            return array(
                'code'   => 'blog_plugins_unreadable',
                'detail' => 'active on this blog but not loaded by this request: ' . implode(', ', $unloaded),
            );
        }

        return false;
    }

    /**
     * The gating plugins this class knows how to read, as plugin directory slug =>
     * internal key.
     *
     * Used for two questions that a loaded symbol cannot answer: whether a plugin that
     * has not registered its post type yet is going to, and whether a plugin is active
     * on a switched blog.
     *
     * @return array
     */
    private static function gating_plugin_map()
    {
        $map = array(
            'members'                 => 'members',
            'restrict-content'        => 'rcp',
            'restrict-content-pro'    => 'rcp',
            'ultimate-member'         => 'um',
            'wp-members'              => 'wpmem',
            'woocommerce-memberships' => 'wcm',
            'paid-memberships-pro'    => 'pmpro',
            'restrict-user-access'    => 'rua',
            'content-control'         => 'cc',
        );

        foreach (self::detection_only_plugins() as $key => $plugin) {
            $map[$plugin['slug']] = $key;
        }

        return $map;
    }

    /**
     * Every gating plugin this class detects, as internal key => label.
     *
     * This is the list the blunt default works from. Everything in it excludes content
     * when it is loaded, and nothing in it needs a reader, a rule format or a polarity
     * to be understood first.
     *
     * @return array [ internal key => label ]
     */
    private static function gating_plugins()
    {
        $plugins = array(
            'members' => 'Members',
            'rcp'     => 'Restrict Content',
            'um'      => 'Ultimate Member',
            'wpmem'   => 'WP-Members',
            'wcm'     => 'WooCommerce Memberships',
            'pmpro'   => 'Paid Memberships Pro',
            'rua'     => 'Restrict User Access',
            'cc'      => 'Content Control',
        );

        foreach (self::detection_only_plugins() as $key => $plugin) {
            $plugins[$key] = $plugin['label'];
        }

        return $plugins;
    }

    /**
     * Gating plugins carried by detection alone, with no reader behind them.
     *
     * The precise path deliberately never covered these four. MemberPress inverts three
     * of its shapes and is only obtainable from an unofficial mirror; LifterLMS' own
     * verdict says restricted for every course an anonymous visitor can actually read,
     * so trusting it deletes every course landing page; LearnDash and Tutor LMS were
     * never evaluated at all.
     *
     * Under blunt exclusion none of that matters, because the question is no longer
     * "which posts does it restrict" but "is it loaded". Detection carries them at no
     * risk, which is why MemberPress no longer needs the release note disclaimer that
     * partial coverage would have owed it.
     *
     * They are excluded on the PRECISE path too. precise_covered_plugins() does not
     * name them, so unreadable_gating_plugins() picks every loaded one of them up and
     * gates its post types whatever the setting says. A site can turn per post
     * evaluation on for the plugins that have readers without turning protection off
     * for the ones that do not.
     *
     * ADDING A PLUGIN HERE IS ONE LINE AND CARRIES NO SEMANTICS. It joins
     * gating_plugins(), gating_plugin_map() and plugin_active() automatically.
     *
     * Symbols are tested with defined(), class_exists() WITHOUT autoloading and
     * function_exists(), in that order. Autoloading is off deliberately: LifterLMS
     * registers an autoloader for every class name beginning llms_, so an autoloading
     * probe from this class would call into it.
     *
     * Two rules for choosing a symbol, both learned here:
     *
     *  - never a symbol this plugin also defines. LifterLMS defines LLMS_VERSION,
     *    LLMS_PLUGIN_FILE and LLMS_PLUGIN_DIR, and up to 8.5.3 so did this plugin.
     *    Testing any of those then would have made all 40,000 installs detect a gating
     *    plugin and withhold every post on every site. 8.5.4 renamed ours to
     *    WEBSITE_LLMS_TXT_VERSION, WEBSITE_LLMS_TXT_FILE, WEBSITE_LLMS_TXT_DIR and
     *    WEBSITE_LLMS_TXT_URL, so the collision no longer exists, and the rule is kept
     *    because website-llms-txt.php says the old names must never be defined again.
     *  - a symbol that exists from plugin load, not one that is autoloaded on demand,
     *    because absence has to mean "not loaded" rather than "not needed yet".
     *
     * Provenance: LifterLMS 9.x and Tutor LMS 4.0.3 symbols were read from the current
     * wordpress.org builds. MemberPress and LearnDash are commercial with no public
     * source, so their constants are taken from their documented public API. A wrong
     * symbol there is a false negative, which leaves those sites where 8.5.3 left them,
     * and both constants are also spelled in a way nothing else would define.
     *
     * @return array [ internal key => [ label, slug, symbols ] ]
     */
    private static function detection_only_plugins()
    {
        return array(
            'memberpress' => array('label' => 'MemberPress', 'slug' => 'memberpress', 'symbols' => array('MEPR_VERSION', 'MEPR_PLUGIN_NAME', 'MeprAppCtrl')),
            'lifterlms'   => array('label' => 'LifterLMS', 'slug' => 'lifterlms', 'symbols' => array('LifterLMS', 'llms')),
            'learndash'   => array('label' => 'LearnDash', 'slug' => 'sfwd-lms', 'symbols' => array('LEARNDASH_VERSION', 'SFWD_LMS')),
            'tutor'       => array('label' => 'Tutor LMS', 'slug' => 'tutor', 'symbols' => array('TUTOR_VERSION', 'TUTOR_FILE')),
        );
    }

    /**
     * Does a constant, class or function of this name exist right now?
     *
     * class_exists() is called with autoloading OFF. See detection_only_plugins().
     *
     * @param string $symbol
     * @return bool
     */
    private static function symbol_exists($symbol)
    {
        return defined($symbol) || class_exists($symbol, false) || function_exists($symbol);
    }

    /**
     * Gating plugins active on the CURRENT blog according to the stored plugin lists,
     * whether or not their code is loaded in this request.
     *
     * Reads the blog's own active_plugins option plus the network's
     * active_sitewide_plugins site option, which is the only per blog source there is:
     * switch_to_blog() does not re-run plugin loading.
     *
     * A plugin whose main file is missing from disk is ignored. WordPress skips such a
     * plugin silently, so it gates nothing, and treating it as unreadable would empty
     * a file over a stale option row.
     *
     * @return array [ internal_key => plugin path ]
     */
    private static function gating_plugins_by_option()
    {
        $map   = self::gating_plugin_map();
        $paths = array();

        $active = get_option('active_plugins');
        if (is_array($active)) {
            foreach ($active as $path) {
                $paths[] = (string) $path;
            }
        }

        if (is_multisite() && function_exists('get_site_option')) {
            $network = get_site_option('active_sitewide_plugins');
            if (is_array($network)) {
                foreach (array_keys($network) as $path) {
                    $paths[] = (string) $path;
                }
            }
        }

        return self::gating_keys_from_paths($paths);
    }

    /**
     * Resolve a list of plugin paths onto the gating plugins this class knows.
     *
     * A plugin whose main file is missing from disk is dropped. WordPress skips such a
     * plugin silently, so it gates nothing, and treating it as unreadable would empty a
     * file over a stale option row.
     *
     * @param string[] $paths
     * @return array [ internal_key => plugin path ]
     */
    private static function gating_keys_from_paths(array $paths)
    {
        $map   = self::gating_plugin_map();
        $found = array();

        foreach ($paths as $path) {
            $path = (string) $path;
            $slug = strtok($path, '/');
            if (!$slug || !isset($map[$slug])) {
                continue;
            }
            if (defined('WP_PLUGIN_DIR') && !file_exists(WP_PLUGIN_DIR . '/' . $path)) {
                continue;
            }
            $found[$map[$slug]] = $path;
        }

        return $found;
    }

    /**
     * The blog this request was BOOTSTRAPPED on, which is the blog whose active_plugins
     * decided what got loaded.
     *
     * NOT simply the current blog, and the difference is a leak. If the first call into
     * this class happens INSIDE switch_to_blog(), the current blog is the switched one,
     * and recording it as the bootstrap blog makes unloaded_gating_plugins() compare a
     * blog's plugin list against ITSELF: every gating plugin active there but never
     * loaded is then excused as "it bailed", and its restricted bodies are published.
     * Measured on a real network, WP-Members active on blog 2 only and the first call
     * inside switch_to_blog( 2 ): a page an anonymous visitor cannot read came back
     * READABLE, where the pre-round-3 code failed the batch closed.
     *
     * WordPress records what is needed. switch_to_blog() pushes the PREVIOUS blog id onto
     * $GLOBALS['_wp_switched_stack'] (ms_is_switched() is defined as that stack being
     * non-empty, ms-blogs.php), so element 0 is the blog the request started on, whatever
     * depth of switching happened afterwards. Present since WP 3.5, well under the 5.8
     * floor.
     *
     * If the stack cannot be read the bootstrap blog stays unknown. That is not a hole:
     * boot_blog_gating_plugins() then contributes only the network wide list and a per
     * blog symbol mismatch falls back to failing closed, which is the round 2 behaviour.
     *
     * @return int|null
     */
    private static function boot_blog_id()
    {
        $switched = function_exists('ms_is_switched') ? ms_is_switched() : false;
        if (!$switched) {
            return self::blog_key();
        }

        if (isset($GLOBALS['_wp_switched_stack'][0])) {
            $id = (int) $GLOBALS['_wp_switched_stack'][0];
            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Gating plugins active on the blog this request was BOOTSTRAPPED on, which is the
     * blog whose plugin list decided what got loaded.
     *
     * Read straight out of that blog's options table rather than through
     * get_blog_option(), which switches blogs internally and would fire the switch_blog
     * action this class hooks flush() to, dropping the memos and the recorded reasons in
     * the middle of a batch.
     *
     * Network activated plugins are added whether or not the bootstrap blog is known,
     * because active_sitewide_plugins is loaded on every request in the network, so a
     * network activated plugin whose symbols are absent really did bail.
     *
     * @return array [ internal_key => plugin path ]
     */
    private static function boot_blog_gating_plugins()
    {
        global $wpdb;

        if (null !== self::$boot_gating) {
            return self::$boot_gating;
        }

        self::$boot_gating = array();
        $paths             = array();

        if (null !== self::$boot_blog && method_exists($wpdb, 'get_blog_prefix')) {
            $table = $wpdb->get_blog_prefix(self::$boot_blog) . 'options';
            $value = self::db_var(
                $wpdb->prepare("SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", 'active_plugins'),
                'boot_blog_gating_plugins'
            );
            $value = (false === $value) ? null : self::safe_unserialize($value);
            if (is_array($value)) {
                foreach ($value as $path) {
                    $paths[] = (string) $path;
                }
            }
        }

        // Network activated plugins are the same list on every blog, so they need no
        // per blog read and they are loaded on every request in the network.
        if (function_exists('get_site_option')) {
            $network = get_site_option('active_sitewide_plugins');
            if (is_array($network)) {
                foreach (array_keys($network) as $path) {
                    $paths[] = (string) $path;
                }
            }
        }

        self::$boot_gating = self::gating_keys_from_paths($paths);

        return self::$boot_gating;
    }

    /**
     * Gating plugins that are active on this blog but whose code this request never
     * loaded, which makes their restrictions unreadable from here.
     *
     * This is the part of the multisite problem that keying the memos by blog id
     * cannot fix. plugin_active() is symbol based and switch_to_blog() does not re-run
     * plugin loading, so a gating plugin activated on blog B only is invisible from a
     * request bootstrapped on blog A, and tier 3 detects by registered post type, which
     * is per request rather than per blog. Under the fail closed policy that is an
     * unknown state, not a public one.
     *
     * Deliberately scoped to the switched case. On a normal single site request the
     * plugin list and the loaded symbols are produced by the same bootstrap, so a
     * mismatch means a plugin that bailed out early and therefore gates nothing;
     * failing closed on that would be an over-removal.
     *
     * TWO DIFFERENT STATES produce a mismatch on a switched blog and only one of them is
     * unknowable. Failing closed on both empties that blog's file FOREVER, with no way
     * for the site owner to fix it, over a plugin that restricts nothing:
     *
     *  - the plugin is active on the switched blog and NOT on the blog this request
     *    bootstrapped on. Nothing ever tried to load it, its restrictions are unreadable
     *    from here, and that is the real leak this check exists to close. Fails closed.
     *  - the plugin is active on the bootstrap blog TOO and its symbols are still absent.
     *    This request did try to load it and it bailed: a version check, a missing
     *    dependency (WooCommerce Memberships without WooCommerce is the common one), or
     *    WordPress' fatal error recovery. A plugin that bails does so on every request on
     *    every blog, including the anonymous visitor's, so it gates nothing anywhere and
     *    the switched blog's content is readable. Recorded, not failed closed.
     *
     * A plugin whose main file is missing from disk is already dropped by
     * gating_keys_from_paths(), so the deleted-plugin row never reaches here at all.
     *
     * The reason codes distinguish the two, because "your file is empty" is only
     * actionable if the owner can tell which one they are in.
     *
     * Network generation should be one blog per request. That keeps this check quiet
     * and is the only arrangement in which tier 3 is correct on multisite.
     *
     * @return string[] internal keys
     */
    private static function unloaded_gating_plugins()
    {
        if (!is_multisite()) {
            return array();
        }

        $switched = function_exists('ms_is_switched') ? ms_is_switched() : false;
        if (!$switched && null !== self::$boot_blog && self::$boot_blog === self::blog_key()) {
            return array();
        }
        if (!$switched && null === self::$boot_blog) {
            self::$boot_blog = self::blog_key();
            return array();
        }

        $boot     = self::boot_blog_gating_plugins();
        $unloaded = array();
        foreach (self::gating_plugins_by_option() as $key => $path) {
            if (self::plugin_active($key)) {
                continue;
            }
            if (isset($boot[$key])) {
                // Informational. Active where the loading happened and still not loaded,
                // so it gates nothing in any request on any blog.
                self::record_reason(
                    'gating_plugin_never_loaded',
                    $key . ' is active but did not load in this request, so it restricts nothing'
                );
                continue;
            }
            $unloaded[] = $key;
        }

        return $unloaded;
    }

    /**
     * Hook flush() to switch_blog once.
     *
     * The class has no bootstrap of its own by design, so it wires this itself the
     * first time it is used rather than relying on a caller to do it.
     *
     * @return void
     */
    private static function hook_switch_blog()
    {
        if (self::$switch_hooked || !function_exists('add_action')) {
            return;
        }
        self::$switch_hooked = true;
        if (null === self::$boot_blog) {
            // boot_blog_id(), NOT blog_key(): a first call made inside switch_to_blog()
            // would otherwise record the switched blog as the bootstrap blog, which
            // excuses that blog's own unloaded gating plugins and publishes their bodies.
            self::$boot_blog = self::boot_blog_id();
        }
        add_action('switch_blog', array(__CLASS__, 'flush'));
    }

    /**
     * $wpdb->get_results() that distinguishes "no rows" from "the query failed".
     *
     * Errors stay suppressed for the whole call. On a WP_DEBUG site an unsuppressed
     * failure calls wpdb::print_error(), which echoes an HTML error block, and the
     * generator buffers output into the file, so that block would land in llms.txt.
     *
     * @param string $sql
     * @param string $where Label for the recorded reason.
     * @return array|false
     */
    private static function db_results($sql, $where)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $rows     = $wpdb->get_results($sql, ARRAY_A);
        $error    = $wpdb->last_error;
        $wpdb->suppress_errors($suppress);

        if ($error) {
            self::note_failure($where, $error);
            return false;
        }

        return (array) $rows;
    }

    /**
     * $wpdb->get_col() that distinguishes "no rows" from "the query failed".
     *
     * @param string $sql
     * @param string $where
     * @return array|false
     */
    private static function db_col($sql, $where)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $rows     = $wpdb->get_col($sql);
        $error    = $wpdb->last_error;
        $wpdb->suppress_errors($suppress);

        if ($error) {
            self::note_failure($where, $error);
            return false;
        }

        return (array) $rows;
    }

    /**
     * $wpdb->get_var() that distinguishes "no row" from "the query failed".
     *
     * @param string $sql
     * @param string $where
     * @return string|null|false false means the query failed.
     */
    private static function db_var($sql, $where)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $value    = $wpdb->get_var($sql);
        $error    = $wpdb->last_error;
        $wpdb->suppress_errors($suppress);

        if ($error) {
            self::note_failure($where, $error);
            return false;
        }

        return $value;
    }

    /**
     * unserialize() on database controlled bytes, with objects refused.
     *
     * maybe_unserialize() instantiates objects, so a row whose bytes describe an
     * object with a throwing __wakeup() or __destruct() would take the generation run
     * down. allowed_classes false yields __PHP_Incomplete_Class instead, which fails
     * every is_array() test in this file and therefore lands on "restricted" rather
     * than on "public".
     *
     * @param mixed $value
     * @return mixed
     */
    private static function safe_unserialize($value)
    {
        if (!is_string($value) || !is_serialized($value)) {
            return $value;
        }

        $out = @unserialize(trim($value), array('allowed_classes' => false));

        return (false === $out && 'b:0;' !== trim($value)) ? $value : $out;
    }

    /* ---------------------------------------------------------------------------
     * Reader registry
     * ------------------------------------------------------------------------ */

    /**
     * Active tier 1 readers.
     *
     * A reader is registered only when its plugin is actually loaded. A deactivated
     * plugin leaves its options and its post meta behind and gates nothing, so
     * detection is by symbol, never by stored data.
     *
     * Descriptor shape:
     *   label     string  human readable, used by the admin summary
     *   meta_keys string[] keys to include in the site wide candidate query
     *   callback  callable ( int $post_id, array $post, array $ctx ) : bool
     *                      true means the post is restricted
     *
     * @return array
     */
    private static function readers()
    {
        $readers = array();

        if (self::plugin_active('members')) {
            $readers['members'] = array(
                'label'     => 'Members',
                'meta_keys' => array('_members_access_role', '_role'),
                'callback'  => array(__CLASS__, 'reader_members'),
            );
        }

        if (self::plugin_active('rcp')) {
            $readers['rcp'] = array(
                'label'     => 'Restrict Content',
                'meta_keys' => array('rcp_user_level', 'rcUserLevel', 'rcp_access_level', 'rcp_subscription_level', '_is_paid'),
                'callback'  => array(__CLASS__, 'reader_rcp'),
            );
        }

        if (self::plugin_active('um')) {
            $readers['um'] = array(
                'label'     => 'Ultimate Member',
                // um_content_restriction is deliberately absent. See um_restricted_ids().
                'meta_keys' => array(),
                'callback'  => array(__CLASS__, 'reader_um'),
            );
        }

        if (self::plugin_active('wpmem')) {
            $readers['wpmem'] = array(
                'label'     => 'WP-Members',
                'meta_keys' => array('_wpmem_block'),
                'callback'  => array(__CLASS__, 'reader_wpmem'),
            );
        }

        if (self::plugin_active('wcm')) {
            $readers['wcm'] = array(
                'label'     => 'WooCommerce Memberships',
                'meta_keys' => array(),
                'callback'  => array(__CLASS__, 'reader_wc_memberships'),
            );
        }

        if (self::plugin_active('pmpro')) {
            $readers['pmpro'] = array(
                'label'     => 'Paid Memberships Pro',
                'meta_keys' => array(),
                'callback'  => array(__CLASS__, 'reader_pmpro'),
            );
        }

        // MemberPress is deliberately not registered. The only obtainable build is an
        // unofficial mirror, so no fixture can establish that a reader matches what a
        // licensed customer actually runs, and three of its shapes invert the naive
        // reading. Coverage claimed but not delivered is worse than coverage disclaimed.

        // LifterLMS, LearnDash and Tutor LMS are deliberately not registered either.
        // LifterLMS' own verdict returns restricted for every course an anonymous
        // visitor loads while its template loader skips those post types, so trusting
        // it deletes every course landing page.
        //
        // All four are DETECTED, and detection is the whole policy for them on BOTH
        // paths. See detection_only_plugins() and unreadable_gating_plugins(): a plugin
        // with no reader keeps blunt exclusion even when per post evaluation is on, so
        // turning precision on cannot be the thing that removes their protection.

        /**
         * Filter the tier 1 reader registry. ADDITIONS ONLY.
         *
         * This filter receives an EMPTY array and the built in readers are merged over
         * whatever it returns, so a third party can add a reader for a plugin this
         * class does not cover, and cannot remove, replace or shadow a built in one.
         *
         * That is deliberate and it is the same contract as the sibling filter
         * llms_post_is_publicly_readable, which may only turn true into false. This
         * registry is one level up from that decision: with the built ins removable,
         * `return array();` from any plugin, theme functions.php or mu-plugin would
         * turn the whole security fix off for every gating plugin at once, undocumented
         * and with no admin surface, and the same happens by accident from a callback
         * that builds a fresh array instead of modifying the one it was given. On a
         * plugin whose fix exists because of a broken access control report, that is
         * not an acceptable residual.
         *
         * A collision on a built in key is resolved in favour of the built in. Register
         * under your own key.
         *
         * Descriptor shape:
         *   label     string   human readable, used by the admin summary
         *   meta_keys string[] keys to include in the site wide candidate query
         *   callback  callable ( int $post_id, array $post, array $ctx ) : bool
         *                      true means the post is restricted
         *
         * A third party reader otherwise gets the same treatment as a built in one: its
         * meta keys join the single site wide candidate query, its values arrive
         * truncated to META_VALUE_LIMIT bytes, and a throw inside it fails open.
         *
         * @param array $readers Always empty. Add your descriptors and return them.
         */
        $added = apply_filters('llms_access_readers', array());
        $added = is_array($added) ? $added : array();

        // Built ins last, so they win every key collision.
        $readers = array_merge($added, $readers);

        // A descriptor with no callable callback is recorded rather than silently
        // skipped, so a typo in a third party registration is visible in the admin
        // summary instead of failing open in silence. It does not fail the batch
        // closed: the built in readers still ran, and a broken third party descriptor
        // is not evidence about this site's own content.
        foreach ($readers as $key => $reader) {
            if (!empty($reader['callback']) && is_callable($reader['callback'])) {
                continue;
            }
            unset($readers[$key]);
            self::record_reason(
                'reader_callback_invalid',
                (string) $key . ' (' . (isset($reader['label']) ? $reader['label'] : 'no label') . ')'
            );
        }

        return $readers;
    }

    /**
     * Is a gating plugin loaded in this request?
     *
     * This is the evidence the default policy runs on, so it is the one thing in this
     * file that has to be right.
     *
     * The six tier 1 answers, the four detection only ones and WooCommerce are symbol
     * based and true from plugin load onwards. The two tier 3 answers are not: they read
     * the post type registry, which is not populated until init, and RUA registers at
     * init priority 99. cannot_determine() is what keeps a caller from acting on a tier 3
     * "no" before then, and gating_plugins_by_option() is what makes that check cost
     * nothing on a site with no gating plugin.
     *
     * @param string $which
     * @return bool
     */
    private static function plugin_active($which)
    {
        switch ($which) {
            case 'members':
                return function_exists('members_get_setting') || class_exists('Members_Plugin');

            case 'rcp':
                return function_exists('rcp_is_restricted_content')
                    || function_exists('rcp_user_can_access')
                    || class_exists('Restrict_Content_Pro')
                    || class_exists('Restrict_Content_Plugin')
                    || defined('RC_PLUGIN_VERSION')
                    || defined('RCP_PLUGIN_VERSION');

            case 'um':
                return function_exists('UM') || class_exists('UM') || defined('um_url');

            case 'wpmem':
                return function_exists('wpmem_is_blocked') || class_exists('WP_Members') || defined('WPMEM_VERSION');

            case 'wcm':
                return function_exists('wc_memberships') || class_exists('WC_Memberships');

            case 'pmpro':
                return defined('PMPRO_VERSION') || function_exists('pmpro_getOption') || function_exists('pmpro_has_membership_access');

            case 'rua':
                return post_type_exists('restriction');

            case 'cc':
                // Two shipping plugins, not one plugin with two data formats.
                //
                // Content Control 2.x registers the cc_restriction post type
                // (classes/Controllers/PostTypes.php:79), which is the tier 3 dependency
                // on init. Content Control 1.x registers NO post type at all: grep for
                // register_post_type over 1.1.10 returns zero hits. It stores its
                // restrictions in jp_cc_settings and withholds bodies with a the_content
                // filter (classes/Site/Posts.php:40-67). Detecting by the post type alone
                // therefore published every restricted body on a 1.x site, and 2.0 shipped
                // in 2023 with a long tail that never updated.
                //
                // Its loader symbols: jp_content_control() (content-control.php:225,
                // hooked to plugins_loaded at :230) and the JP_Content_Control class
                // (:62). Both exist from plugin load, so unlike the CPT they are true
                // before init and this case is only registry dependent for 2.x.
                return post_type_exists('cc_restriction')
                    || function_exists('jp_content_control')
                    || class_exists('JP_Content_Control');

            case 'wc':
                // WooCommerce itself, which is NOT a gating plugin and is not in
                // gating_plugins(). Only its coming soon option state gates anything.
                // Symbol based and true from plugin load, so wc_coming_soon() never
                // depends on a registry.
                return defined('WC_VERSION') || class_exists('WooCommerce', false) || function_exists('WC');
        }

        // Detection only plugins, which have no reader and need no case of their own.
        $detect = self::detection_only_plugins();
        if (isset($detect[$which])) {
            foreach ($detect[$which]['symbols'] as $symbol) {
                if (self::symbol_exists($symbol)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /* ---------------------------------------------------------------------------
     * Per run context
     * ------------------------------------------------------------------------ */

    /**
     * Build the per run context once: readers, option snapshots, the candidate meta
     * map and the set builders.
     *
     * @return array
     */
    private static function context()
    {
        $blog = self::blog_key();
        if (isset(self::$ctx[$blog])) {
            return self::$ctx[$blog];
        }

        // Everything below is wrapped, because the readers it feeds are wrapped and
        // this is where the majority of the work happens. safe_unserialize() removes
        // the object instantiation route, but get_option() and every registry lookup in
        // here run third party filters, and a throw would otherwise propagate out of
        // filter_publicly_readable() and kill the generation run mid file. A throw here
        // means this class has no idea what is restricted, so the contract is fail
        // CLOSED with a recorded reason rather than the readers' fail open.
        try {
            $ctx = self::build_context($blog);
        } catch (\Throwable $e) {
            self::record_reason('context_threw', $e->getMessage());
            $ctx = array(
                'blog_id' => $blog,
                'readers' => array(),
                'opt'     => array(),
                'meta'    => array(),
                'sets'    => array(),
            );
            self::$failed[$blog] = true;
        }

        self::$ctx[$blog] = $ctx;
        return self::$ctx[$blog];
    }

    /**
     * The body of context().
     *
     * @param int $blog
     * @return array
     */
    private static function build_context($blog)
    {
        $ctx = array(
            // Recorded so filter_publicly_readable() can refuse to judge one blog's
            // posts with another blog's data even if the memo keying above were wrong.
            'blog_id' => $blog,
            'readers' => self::readers(),
            'opt'     => array(),
            'meta'    => array(),
            'sets'    => array(),
        );

        // Nothing is registered on the common case, so the candidate query is never
        // issued and this whole class costs one filter call.
        if (!$ctx['readers']) {
            return $ctx;
        }

        $ctx['meta'] = self::candidate_meta($ctx['readers']);

        if (isset($ctx['readers']['members'])) {
            $settings = self::option_array('members_settings');
            // Master switch. members_get_setting() defaults it to 1, so an absent key
            // means enabled. When it is off, Members publishes posts that still carry
            // restriction meta, and a reader that ignores it over-restricts.
            $ctx['opt']['members_content_permissions'] = !isset($settings['content_permissions'])
                ? true
                : (bool) $settings['content_permissions'];
        }

        if (isset($ctx['readers']['rcp'])) {
            $ctx['opt']['rcp_mode'] = self::rcp_mode();
            $ctx['sets']['rcp_term'] = self::rcp_term_restricted_ids();
        }

        if (isset($ctx['readers']['um'])) {
            $opts = self::option_array('um_options');
            // Post type enablement REVERSES polarity: with the type unticked, a post
            // carrying _um_accessible = 2 is publicly visible. UM reads the metabox
            // value only after this gate passes.
            $ctx['opt']['um_post_types'] = isset($opts['restricted_access_post_metabox']) && is_array($opts['restricted_access_post_metabox'])
                ? $opts['restricted_access_post_metabox']
                : array();
            $ctx['sets']['um'] = self::um_restricted_ids();

            // Term level restriction, which withholds a post carrying no UM post meta at
            // all and so is invisible to any postmeta scan. Skipped entirely when no
            // taxonomy is enabled, which is what almost every UM site looks like.
            $ctx['sets']['um_term'] = self::um_term_restricted_ids(
                isset($opts['restricted_access_taxonomy_metabox']) && is_array($opts['restricted_access_taxonomy_metabox'])
                    ? $opts['restricted_access_taxonomy_metabox']
                    : array()
            );

            // Consulted only to let a post level setting beat a restricted term, so
            // there is nothing worth building when no term restricts anything.
            $ctx['sets']['um_custom'] = $ctx['sets']['um_term']
                ? self::um_custom_access_ids()
                : array();
        }

        if (isset($ctx['readers']['wpmem'])) {
            $settings = self::option_array('wpmembers_settings');
            $ctx['opt']['wpmem_block'] = isset($settings['block']) && is_array($settings['block'])
                ? $settings['block']
                : array();
            // Which post types WP-Members handles at all, keyed by post type name.
            // WP_Members::$post_types is this option verbatim, assembled from
            // wpmembers_settings at includes/class-wp-members.php:526-559, and the
            // hidden post scan tests array_key_exists() against it merged with post and
            // page. See reader_wpmem().
            $ctx['opt']['wpmem_post_types'] = isset($settings['post_types']) && is_array($settings['post_types'])
                ? $settings['post_types']
                : array();

            // The CACHED hidden post list, which is what actually decides an anonymous
            // visitor's 404 for a '2' ("Hide"). See reader_wpmem().
            //
            // hidden_posts() (includes/class-wp-members.php:1346-1352) reads this option
            // and recomputes ONLY when `false == $hidden`, which a missing option, an
            // empty array and '0' all satisfy. So a non-empty stored list is authoritative
            // and a falsy one means "the plugin will recompute", which is what
            // wpmem_handles_type() mirrors. A stored value that is not an array is
            // treated as falsy here: do_hide_posts() array_merge()s it (:1454-1470) and
            // the recompute is the only reading of it that is defined.
            $hidden = get_option('wpmem_hidden_posts');
            $ctx['opt']['wpmem_hidden'] = (is_array($hidden) && $hidden) ? self::id_set($hidden) : null;
        }

        if (isset($ctx['readers']['wcm'])) {
            $ctx['sets']['wcm'] = self::wc_memberships_restricted_ids();
        }

        if (isset($ctx['readers']['pmpro'])) {
            $ctx['sets']['pmpro'] = self::pmpro_restricted_ids();
        }

        // Returned, never stored. self::$ctx is keyed by blog id and context() is the
        // only writer; assigning the bare context here replaced the whole memo array
        // with one context, leaving its own string keys ('readers', 'meta', ...)
        // alongside the int blog key that context() then wrote back on top. Harmless
        // only by accident, because blog ids are ints and the memo is read by key, and
        // wrong for anything that ever iterates the memo.
        return $ctx;
    }

    /**
     * The single site wide candidate query.
     *
     * Driven by the meta_key index, once per run, not once per chunk. A per chunk
     * "meta_key IN (...) AND post_id IN (...)" reads the post_id index instead, which
     * does not carry meta_key, so it degrades into roughly 15,000 primary key lookups
     * per 500 post chunk and about 1.5M row reads per pass at 50k posts.
     *
     * _prime_post_caches() is not an option here for the same reason it is not an
     * option anywhere in this plugin: it loads every meta row for the batch into the
     * object cache with no eviction and never consults wp_suspend_cache_addition(), so
     * an Elementor site exhausts 256 MB in two or three batches.
     *
     * @param array $readers
     * @return array [ post_id => [ meta_key => string[] ] ]
     */
    private static function candidate_meta(array $readers)
    {
        global $wpdb;

        $keys = array();
        foreach ($readers as $reader) {
            if (empty($reader['meta_keys'])) {
                continue;
            }
            foreach ((array) $reader['meta_keys'] as $key) {
                $key = (string) $key;
                if ('' !== $key) {
                    $keys[$key] = $key;
                }
            }
        }
        if (!$keys) {
            return array();
        }

        $in  = "'" . implode("','", array_map('esc_sql', array_values($keys))) . "'";
        $sql = "SELECT post_id, meta_key, LEFT(meta_value, " . (int) self::META_VALUE_LIMIT . ") AS meta_value"
            . " FROM {$wpdb->postmeta} WHERE meta_key IN ({$in})";

        // Errors stay suppressed across BOTH queries. Restoring suppression before the
        // fallback ran let a failing fallback call wpdb::print_error() on a WP_DEBUG
        // site, which echoes an HTML error block into the output buffer the generator
        // writes to the file.
        $suppress = $wpdb->suppress_errors(true);
        $rows     = $wpdb->get_results($sql, ARRAY_A);
        $error    = $wpdb->last_error;

        if ($error) {
            // LEFT() is MySQL and MariaDB. Drop-in engines that do not implement it
            // still have to answer the query, so fall back and truncate in PHP. The
            // memory guarantee is weaker on that path and it is not the shipping one.
            $rows  = $wpdb->get_results(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$in})",
                ARRAY_A
            );
            $error = $wpdb->last_error;

            if ($error) {
                // The fallback failed too, and its result was previously never checked.
                // get_results() returns false on failure and foreach ((array) false)
                // iterates nothing, so an empty map is indistinguishable from "no post
                // on this site carries any restriction meta" and every Members and RCP
                // restricted body would be published. This is the site wide postmeta
                // scan, so it is precisely the query that hits a statement timeout, a
                // lock wait or a lost connection on a large site while the small
                // primary key lookups elsewhere succeed.
                $wpdb->suppress_errors($suppress);
                self::note_failure('candidate_meta', $error);
                return array();
            }

            foreach ((array) $rows as $i => $row) {
                $rows[$i]['meta_value'] = substr((string) $row['meta_value'], 0, self::META_VALUE_LIMIT);
            }
        }

        $wpdb->suppress_errors($suppress);

        $map = array();
        foreach ((array) $rows as $row) {
            $map[(int) $row['post_id']][$row['meta_key']][] = (string) $row['meta_value'];
        }

        return $map;
    }

    /**
     * Post type, parent, status and password for a batch, in one primary key lookup.
     *
     * Rows that are not published or that carry a password are dropped, so the caller
     * fails them closed. The read query is expected to enforce the same two clauses;
     * doing it here as well keeps this class correct in isolation.
     *
     * @param int[] $ids
     * @return array [ post_id => [ post_type, post_parent ] ]
     */
    private static function posts_for_ids(array $ids)
    {
        global $wpdb;

        $in   = implode(',', array_map('absint', $ids));
        $rows = self::db_results(
            "SELECT ID, post_type, post_parent, post_status, post_password FROM {$wpdb->posts} WHERE ID IN ({$in})",
            'posts_for_ids'
        );

        // A failure here already fails every id closed, because an unresolved id is not
        // demonstrably public. The reason is recorded by db_results().
        if (false === $rows) {
            return array();
        }

        $out = array();
        foreach ((array) $rows as $row) {
            if ('publish' !== $row['post_status'] || '' !== (string) $row['post_password']) {
                continue;
            }
            $out[(int) $row['ID']] = array(
                'post_type'   => (string) $row['post_type'],
                'post_parent' => (int) $row['post_parent'],
            );
        }

        return $out;
    }

    /**
     * Resolve the ancestor closure of a batch in at most ANCESTOR_DEPTH queries, each
     * a primary key IN. Members inherits restriction down the post_parent chain, and a
     * child page with no meta of its own under a restricted parent is withheld.
     *
     * @param int[] $ids
     * @param array $posts
     * @return void
     */
    private static function prime_parents(array $ids, array $posts)
    {
        global $wpdb;

        $blog = self::blog_key();
        if (!isset(self::$parents[$blog])) {
            self::$parents[$blog] = array();
        }

        $pending = array();
        foreach ($ids as $id) {
            if (!isset($posts[$id])) {
                continue;
            }
            self::$parents[$blog][$id] = $posts[$id]['post_parent'];
            if ($posts[$id]['post_parent'] > 0 && !isset(self::$parents[$blog][$posts[$id]['post_parent']])) {
                $pending[$posts[$id]['post_parent']] = $posts[$id]['post_parent'];
            }
        }

        $depth = 0;
        while ($pending && $depth < self::ANCESTOR_DEPTH) {
            $depth++;
            $in   = implode(',', array_map('absint', $pending));
            $rows = self::db_results("SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ({$in})", 'prime_parents');

            // A failed ancestor query would silently truncate the Members inheritance
            // walk, which is a leak: a child page under a restricted parent carries no
            // meta of its own. The reason is recorded and the batch fails closed.
            if (false === $rows) {
                return;
            }

            $next = array();
            foreach ((array) $rows as $row) {
                $id                        = (int) $row['ID'];
                $parent                    = (int) $row['post_parent'];
                self::$parents[$blog][$id] = $parent;
                if ($parent > 0 && !isset(self::$parents[$blog][$parent])) {
                    $next[$parent] = $parent;
                }
            }
            // Anything unresolved has no row, so record it as having no parent rather
            // than looping on it forever.
            foreach ($pending as $id) {
                if (!isset(self::$parents[$blog][$id])) {
                    self::$parents[$blog][$id] = 0;
                }
            }
            $pending = $next;
        }
    }

    /* ---------------------------------------------------------------------------
     * Tier 1 readers, one per plugin
     * ------------------------------------------------------------------------ */

    /**
     * Members.
     *
     * members_can_user_view_post() is never called: it runs the plugin's own legacy
     * meta migration, which rewrites postmeta site wide as a side effect of asking a
     * read only question.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_members($post_id, $post, $ctx)
    {
        if (empty($ctx['opt']['members_content_permissions'])) {
            return false;
        }
        return self::members_restricted($post_id, $ctx, 0);
    }

    /**
     * @param int   $post_id
     * @param array $ctx
     * @param int   $depth
     * @return bool
     */
    private static function members_restricted($post_id, $ctx, $depth)
    {
        if ($depth > self::ANCESTOR_DEPTH) {
            return false;
        }

        // Bare ! empty(), no normalising. Members itself tests ! empty( $roles ) and
        // array('') is not empty, so a post whose only _members_access_role row is ''
        // is WITHHELD. Confirmed by anonymous curl, repeatably. Running
        // array_filter( ..., 'strlen' ) here would publish a body Members is hiding.
        $roles = isset($ctx['meta'][$post_id]['_members_access_role'])
            ? $ctx['meta'][$post_id]['_members_access_role']
            : array();
        if (!empty($roles)) {
            return true;
        }

        // Legacy key. Members' own migration consumes it on the first anonymous view,
        // so reading it races a write and its absence is inconclusive rather than
        // public. Read, never write.
        $legacy = isset($ctx['meta'][$post_id]['_role']) ? $ctx['meta'][$post_id]['_role'] : array();
        if (!empty($legacy)) {
            return true;
        }

        $blog   = self::blog_key();
        $parent = isset(self::$parents[$blog][$post_id]) ? (int) self::$parents[$blog][$post_id] : 0;
        if ($parent > 0) {
            return self::members_restricted($parent, $ctx, $depth + 1);
        }

        return false;
    }

    /**
     * Restrict Content and Restrict Content Pro.
     *
     * The polarity of rcp_user_level = 'None' FLIPS on the engine option. Demonstrated
     * with byte identical post meta: switching the mode flipped three 'None' posts from
     * publicly visible to withheld. A single boolean polarity for this key is wrong in
     * one direction or the other on every RCP site.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_rcp($post_id, $post, $ctx)
    {
        $mode = isset($ctx['opt']['rcp_mode']) ? $ctx['opt']['rcp_mode'] : '';

        // Engine could not be established at all. See rcp_mode().
        if ('' === $mode) {
            return false;
        }

        // Term restriction leaves zero per post trace and is invisible to any postmeta
        // scan. Applies in both engines' 3.0 code path.
        if (isset($ctx['sets']['rcp_term'][$post_id])) {
            return true;
        }

        if ('3.0' === $mode) {
            return self::rcp_restricted_30($post_id, $ctx);
        }

        return self::rcp_restricted_legacy($post_id, $ctx);
    }

    /**
     * Legacy engine. rcp_user_level is a plain string from a fixed six item list and
     * only the five role names withhold. 'None' means unrestricted, and because the
     * legacy metabox writes any non empty submitted value, EVERY post ever saved on a
     * legacy site carries rcp_user_level = 'None'. A "key present means restricted"
     * reader empties the file for that site.
     *
     * @param int   $post_id
     * @param array $ctx
     * @return bool
     */
    private static function rcp_restricted_legacy($post_id, $ctx)
    {
        $value = self::meta_single($ctx, $post_id, 'rcp_user_level');
        if ('' === $value) {
            // Pre 2.2 key, renamed wholesale by the plugin's own upgrade routine.
            $value = self::meta_single($ctx, $post_id, 'rcUserLevel');
        }

        $roles = array('administrator', 'editor', 'author', 'contributor', 'subscriber');

        return in_array(strtolower(trim($value)), $roles, true);
    }

    /**
     * 3.0 engine. Mirrors rcp_has_post_restrictions(), four keys.
     *
     * @param int   $post_id
     * @param array $ctx
     * @return bool
     */
    private static function rcp_restricted_30($post_id, $ctx)
    {
        if (!empty(self::meta_single($ctx, $post_id, '_is_paid'))) {
            return true;
        }

        // rcp_get_content_subscription_levels() returns false for the 'all' sentinel,
        // which is the backwards compatibility escape from before RCP allowed multiple
        // levels. Everything else non empty restricts.
        $levels = self::safe_unserialize(self::meta_single($ctx, $post_id, 'rcp_subscription_level'));
        if (is_string($levels) && 'all' === strtolower($levels)) {
            $levels = false;
        }
        if (!empty($levels)) {
            return true;
        }

        // 3.0 stores this as a serialized array whose common value is array('all'), not
        // the string 'All'. The plugin's own 'None' comparison there is dead code,
        // because strtolower() can never return 'None', so a stored 'None' reads as
        // RESTRICTED in this engine.
        $user_level = self::safe_unserialize(self::meta_single($ctx, $post_id, 'rcp_user_level'));
        if (!empty($user_level) && !is_array($user_level)) {
            $user_level = array($user_level);
        }
        if (!empty($user_level)) {
            $first = reset($user_level);
            if ('all' !== strtolower((string) $first)) {
                return true;
            }
        }

        // Exact case 'None' is the only sentinel the plugin honours at post level.
        // '' and '0' are public because ! empty() already rejects them.
        $access_level = self::meta_single($ctx, $post_id, 'rcp_access_level');
        if (!empty($access_level) && 'None' !== $access_level) {
            return true;
        }

        return false;
    }

    /**
     * Ultimate Member.
     *
     * Three traps. The stored values are PHP integers, so '2' === $v fails open on every
     * UM post. Key presence carries zero information, because UM writes its full
     * restriction array on every save of an enabled post type, including when the
     * restriction checkbox is off. And the two levels are not symmetrical: the post type
     * option gates the post level settings only, never the term level ones.
     *
     * The order below is get_post_privacy_settings()' own order,
     * includes/core/class-access.php:2005-2111.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_um($post_id, $post, $ctx)
    {
        // Reversed polarity, verified: with the post type unticked in
        // um_options['restricted_access_post_metabox'], a post carrying
        // _um_accessible = 2 was publicly visible.
        $type_enabled = !empty($ctx['opt']['um_post_types'][$post['post_type']]);

        if ($type_enabled && isset($ctx['sets']['um'][$post_id])) {
            return true;
        }

        // Post level settings beat the term, in this direction only. Once
        // get_post_privacy_settings() finds _um_custom_access_settings on the post at
        // :2010 it returns that array and never reads a single term, so a post
        // deliberately opened up inside a restricted category stays public. Inverting
        // this is an over-removal, and it is the reason this test sits ahead of the term
        // set rather than behind it.
        if ($type_enabled && isset($ctx['sets']['um_custom'][$post_id])) {
            return false;
        }

        // Term level, and deliberately NOT behind the post type gate above. The term scan
        // at :2077 is reached whenever the post level branch did not return, which
        // includes every post whose post type is unticked. Verified: a post type absent
        // from restricted_access_post_metabox is still withheld inside a restricted term.
        return isset($ctx['sets']['um_term'][$post_id]);
    }

    /**
     * WP-Members.
     *
     * _wpmem_block is stored ONLY when the chosen status differs from the post type
     * default and deleted when it matches, so its absence is not "unrestricted" and its
     * value is meaningless without wpmembers_settings['block'][$post_type]. Verified on
     * a virgin install: zero _wpmem rows anywhere, every post withheld, every page
     * visible.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_wpmem($post_id, $post, $ctx)
    {
        $defaults = isset($ctx['opt']['wpmem_block']) ? $ctx['opt']['wpmem_block'] : array();
        $type     = $post['post_type'];
        $default  = isset($defaults[$type]) ? (int) $defaults[$type] : 0;
        $meta     = self::meta_single($ctx, $post_id, '_wpmem_block');

        if ($default >= 1) {
            // Blocked by default. Only an explicit '0' unblocks, so '1', '2' and an
            // absent row all stay blocked.
            return '0' !== $meta;
        }

        // Unblocked by default, and '2' ("Hide") still makes the URL 404.
        //
        // is_blocked() is indeed false for '2' on this branch: the switch at
        // includes/class-wp-members.php:1030-1038 tests block_meta == '1' and nothing
        // else. But is_blocked() is not the only thing WP-Members does with the value.
        // load_hooks() adds do_hide_posts() to pre_get_posts unconditionally at
        // includes/class-wp-members.php:636, with no is_main_query(), no is_singular()
        // and no admin guard (:1454-1470), and for an anonymous visitor
        // get_hidden_posts() returns EVERY hidden post (:1396-1406). post__not_in is
        // applied by WP_Query unconditionally, so the singular query returns no rows and
        // WP_Query::handle_404() sets a 404. Measured on MySQL: HTTP 404, canary absent.
        //
        // The earlier code comment here asserted the opposite and cited a curl that was
        // a SQLite artifact. hidden_posts() caches wpmem_hidden_posts and only recomputes
        // when the option is falsy (:1347-1353), and under Playground's SQLite layer
        // update_hidden_posts() selects p1.id and reads $result->id, which comes back as
        // the property ID, so the cached list was a single NULL, post__not_in excluded
        // nothing and the page rendered.
        //
        // Scope precisely, and scope it against the CACHE rather than against the
        // recompute. update_hidden_posts() (:1364-1384) only adds a row whose post_type
        // is a key of wpmembers_settings['post_types'] merged with the defaults post and
        // page, so a '2' on any other post type never enters the hidden list. But that
        // function is the RECOMPUTE, and an anonymous visitor's 404 is decided by
        // get_hidden_posts() -> hidden_posts() -> the CACHED wpmem_hidden_posts option,
        // which the settings tab save never rebuilds
        // (includes/admin/tabs/class-wp-members-admin-tab-options.php, the save path
        // around :415-445). The cache is rebuilt only by a post save through
        // set_block_status() (class-wp-members-admin-posts.php:487), a plugin update
        // (install.php:510), the CLI refresh-hidden (class-wp-members-cli.php:136), or
        // the option being falsy (:1346-1352); `wp mem set --status=hide` writes the meta
        // WITHOUT refreshing (:124).
        //
        // So mirroring the recompute diverges from the front end in BOTH directions, and
        // both were measured on MySQL: a post type removed from the handled list leaves a
        // stale entry in the cache and the URL still 404s while this reader said readable
        // (leak), and a post type added to the handled list before any rebuild leaves the
        // cache short and the URL serves 200 while this reader withheld it
        // (over-removal). The cache is therefore consulted first, and the recompute is
        // used only where the plugin itself would recompute.
        if ('2' === $meta) {
            // isset(), not a bare index: an unset key here would emit a warning, and the
            // generator buffers output into the file. Absent means "no cache was read",
            // which is the recompute path below.
            if (isset($ctx['opt']['wpmem_hidden']) && is_array($ctx['opt']['wpmem_hidden'])) {
                return isset($ctx['opt']['wpmem_hidden'][$post_id]);
            }

            return self::wpmem_handles_type($type, $ctx);
        }

        return '1' === $meta;
    }

    /**
     * Is this a post type WP-Members' hidden post scan covers?
     *
     * Mirrors update_hidden_posts()' own test, array_key_exists() against
     * WP_Members::$post_types merged with array( 'post' => ..., 'page' => ... ).
     * WP_Members::$post_types is wpmembers_settings['post_types'] verbatim
     * (includes/class-wp-members.php:526-559), keyed by post type name.
     *
     * @param string $type
     * @param array  $ctx
     * @return bool
     */
    private static function wpmem_handles_type($type, $ctx)
    {
        if ('post' === $type || 'page' === $type) {
            return true;
        }

        $handled = isset($ctx['opt']['wpmem_post_types']) ? $ctx['opt']['wpmem_post_types'] : array();

        return is_array($handled) && array_key_exists($type, $handled);
    }

    /**
     * WooCommerce Memberships. Set builder, computed once per run.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_wc_memberships($post_id, $post, $ctx)
    {
        return isset($ctx['sets']['wcm'][$post_id]);
    }

    /**
     * Paid Memberships Pro. Set builder, computed once per run.
     *
     * pmpro_has_membership_access() is never called. It does
     * array_merge( $post_terms, wp_get_post_tags( ... ) ) at content.php:54-57, which
     * fatals on PHP 8 when a taxonomy is unregistered. The two tables are read instead.
     *
     * @param int   $post_id
     * @param array $post
     * @param array $ctx
     * @return bool
     */
    private static function reader_pmpro($post_id, $post, $ctx)
    {
        return isset($ctx['sets']['pmpro'][$post_id]);
    }

    /* ---------------------------------------------------------------------------
     * Set builders. All return [ post_id => true ], all computed once per run.
     * ------------------------------------------------------------------------ */

    /**
     * Ultimate Member restricted post IDs.
     *
     * This one does not go through the candidate meta map. um_content_restriction is a
     * fully populated eight key array written on every save, easily past
     * META_VALUE_LIMIT and unbounded at the top end because it can carry a per post
     * custom message. Truncating it would fail to unserialize on every row and withhold
     * the whole site; not truncating it would pull one fat serialized array per post
     * into memory. So the two comparisons are pushed into SQL against PHP's own
     * serialization format and only post IDs come back.
     *
     * Both the integer and the string encodings are matched. UM stores integers, and
     * the reader must not care which, because UM's own code compares loosely.
     *
     * The match is NEGATIVE, and that is the whole point of it.
     *
     * is_restricted() (includes/core/class-access.php:2205-2258) starts from
     * $restricted = true and its if/elseif chain over _um_accessible has no terminal
     * else, so every value that is not a loose 0, 1 or 2 leaves the post RESTRICTED.
     * Matching restriction as a positive allowlist of i:2; / s:1:"2"; therefore
     * published anything else, and "anything else" is reachable: UM's own 2.0-beta1
     * migration (includes/admin/core/packages/2.0-beta1/content_restriction.php:44-82)
     * writes '_um_accessible' => get_post_meta( $post_id, '_um_accessible', true )
     * unconditionally for every post whose legacy _um_custom_access_settings was '1',
     * and its selecting query never checks that _um_accessible exists. A site that came
     * through UM 1.x therefore carries _um_accessible => '', and '0' == '', '1' == ''
     * and '2' == '' are all false, so UM withholds the body.
     *
     * This is also the polarity this file's own term level reader already uses, at
     * um_term_restricted_ids(): "any other value reaches no clearing branch at all and
     * stays restricted". The two now agree.
     *
     * Residual, accepted and worth knowing: the fragments are matched anywhere in
     * meta_value, and the array can carry a per post custom message, so an admin who
     * pastes a clearing encoding verbatim into that message clears the restriction.
     * Anchoring is not available without unserializing every row, which is what this
     * builder exists to avoid. Before the inversion the same imprecision pointed at
     * over-removal instead.
     *
     * @return array
     */
    private static function um_restricted_ids()
    {
        global $wpdb;

        // Truthy _um_custom_access_settings. Without it UM never reads _um_accessible.
        $custom = array(
            's:26:"_um_custom_access_settings";b:1;',
            's:26:"_um_custom_access_settings";i:1;',
            's:26:"_um_custom_access_settings";s:1:"1";',
        );

        // The key has to be PRESENT. get_post_privacy_settings() falls straight through
        // to its term scan without it, exactly as if the post carried nothing.
        $present = array('s:14:"_um_accessible";');

        // The only values that CLEAR restriction for an anonymous visitor. 0 is
        // "private", which clears unconditionally, and 1 is "logged out users", which
        // clears for a visitor who is not logged in.
        //
        // UM's own test is '0' == $value / '1' == $value, a LOOSE compare, while this is
        // a match on serialized bytes, so the two can only be made to agree over an
        // enumerated set. Enumerated here: the integer and string encodings UM's own
        // writers produce, plus the boolean and float encodings a mechanical cast
        // produces (b:0; from (bool), d:0; from (float)), all four of which UM's loose
        // compare clears and all of which were measured VISIBLE to anonymous curl.
        //
        // DELIBERATELY LEFT ON THE WITHHELD SIDE: non-canonical numeric STRINGS such as
        // s:3:"0.0", s:2:" 1", "+1" or "0e0". PHP 8 makes every one of them loosely equal
        // to 0 or 1, so UM serves them and this builder withholds them, which is a known
        // over-removal. It is not closed because the set is unbounded: there is no finite
        // list of numeric strings equal to 0 or 1, no UM writer produces any of them (the
        // metabox stores a <select> value and the 2.0-beta1 migration copies a value that
        // came from the same select), and the alternative is unserializing every row,
        // which is the memory cost this builder exists to avoid. Direction is
        // over-removal, never leak.
        $clearing = array(
            's:14:"_um_accessible";i:0;',
            's:14:"_um_accessible";s:1:"0";',
            's:14:"_um_accessible";i:1;',
            's:14:"_um_accessible";s:1:"1";',
            's:14:"_um_accessible";b:0;',
            's:14:"_um_accessible";b:1;',
            's:14:"_um_accessible";d:0;',
            's:14:"_um_accessible";d:1;',
        );

        $sql  = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'um_content_restriction'";
        $sql .= ' AND (' . self::like_clause('meta_value', $custom) . ')';
        $sql .= ' AND (' . self::like_clause('meta_value', $present) . ')';
        $sql .= ' AND NOT (' . self::like_clause('meta_value', $clearing) . ')';

        $ids = self::db_col($sql, 'um_restricted_ids');

        return (false === $ids) ? array() : self::id_set($ids);
    }

    /**
     * Posts whose own Ultimate Member settings decide their access, whatever their terms
     * say.
     *
     * get_post_privacy_settings() returns the post level array at :2072 and stops there,
     * so these posts must not be pulled in by the term pass, whichever way their own
     * tri-state points. _um_accessible has to be present as well as
     * _um_custom_access_settings truthy: without it UM falls straight through to its term
     * scan at :2011-2044, exactly as if the post carried nothing.
     *
     * Same SQL shape, and the same reason for it, as um_restricted_ids().
     *
     * @return array
     */
    private static function um_custom_access_ids()
    {
        global $wpdb;

        $custom = array(
            's:26:"_um_custom_access_settings";b:1;',
            's:26:"_um_custom_access_settings";i:1;',
            's:26:"_um_custom_access_settings";s:1:"1";',
        );

        $sql  = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'um_content_restriction'";
        $sql .= ' AND (' . self::like_clause('meta_value', $custom) . ')';
        $sql .= ' AND (' . self::like_clause('meta_value', array('s:14:"_um_accessible";')) . ')';

        $ids = self::db_col($sql, 'um_custom_access_ids');

        return (false === $ids) ? array() : self::id_set($ids);
    }

    /**
     * Ultimate Member term level restriction.
     *
     * A post carrying no UM post meta whatsoever is withheld from an anonymous visitor
     * when it sits in a restricted term, so nothing about this is visible to a postmeta
     * scan. This is a set builder computed once per run, not a per post lookup.
     *
     * @param array $restricted_taxonomies um_options['restricted_access_taxonomy_metabox'].
     * @return array
     */
    private static function um_term_restricted_ids(array $restricted_taxonomies)
    {
        global $wpdb;

        $taxonomies = array();
        foreach ($restricted_taxonomies as $taxonomy => $enabled) {
            // Truthy only. UM's own two readers disagree here: exclude_posts_array()
            // takes array_keys() at :227 and ignores the value, while is_restricted_term()
            // at :2300 and get_post_privacy_settings() at :2087 both test
            // empty( $restricted_taxonomies[ $taxonomy ] ). The latter pair decide what an
            // anonymous visitor is served, so they win. Same polarity as the post type
            // option.
            if (empty($enabled)) {
                continue;
            }
            $taxonomy = (string) $taxonomy;
            // An unregistered taxonomy restricts nothing, because get_object_taxonomies()
            // never returns it and UM's term scan therefore cannot reach its terms. UM
            // drops those taxonomies itself at :229-232. Leaving them in would let a
            // deactivated plugin's leftover term meta withhold posts.
            if ('' === $taxonomy || !taxonomy_exists($taxonomy)) {
                continue;
            }
            $taxonomies[$taxonomy] = $taxonomy;
        }

        // The cheap path, and the one almost every UM site takes. No enabled taxonomy
        // means no term can restrict anything, so neither query below is issued and the
        // post level reader is untouched.
        if (!$taxonomies) {
            return array();
        }

        $in = "'" . implode("','", array_map('esc_sql', array_values($taxonomies))) . "'";

        // UM's own query at :247-255, selecting term_taxonomy_id rather than term_id so
        // the taxonomy scope survives into the relationships join below. Bounded by the
        // number of terms carrying UM meta, not by post count.
        //
        // meta_value is read in full rather than truncated the way candidate_meta() does
        // it: the tri-state has to be unserialized, a term restriction can carry a custom
        // message past META_VALUE_LIMIT, and there is no memory risk because the row count
        // is the number of configured terms.
        $rows = self::db_results(
            "SELECT tt.term_taxonomy_id AS term_taxonomy_id, tm.meta_value AS meta_value
               FROM {$wpdb->termmeta} tm
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
              WHERE tm.meta_key = 'um_content_restriction' AND tt.taxonomy IN ({$in})",
            'um_term_restricted_ids.terms'
        );
        if (false === $rows) {
            return array();
        }

        $tt_ids = array();
        foreach ((array) $rows as $row) {
            $restriction = self::safe_unserialize($row['meta_value']);

            // Both empty tests are UM's, at :2099 and :2312. A term with no restriction
            // array, or one whose custom settings flag is off, restricts nothing.
            if (!is_array($restriction) || empty($restriction['_um_custom_access_settings'])) {
                continue;
            }

            // A term with no _um_accessible at all is skipped rather than restricting:
            // :2100 continues to the next term.
            if (!isset($restriction['_um_accessible'])) {
                continue;
            }

            // The tri-state, and it must be loose. UM writes '0' == / '1' == / '2' ==,
            // post meta holds integers, and term meta is not guaranteed to agree, so a
            // strict === against either type fails open on the other.
            //
            // is_restricted_term() starts from $restricted = true at :2307 and only these
            // branches can clear it. 0 is "private" and clears unconditionally at :2318.
            // 1 is "logged out users" and clears at :2324 for a visitor who is not logged
            // in. 2 only clears inside is_user_logged_in() at :2334, so it stands. Any
            // other value reaches no clearing branch at all and stays restricted, which is
            // both what UM does and the safe direction.
            $accessible = $restriction['_um_accessible'];
            if ('0' == $accessible || '1' == $accessible) {
                continue;
            }

            $tt_ids[] = absint($row['term_taxonomy_id']);
        }

        if (!$tt_ids) {
            return array();
        }

        // Directly assigned terms only, no child term walk. UM's query exclusion at
        // :273-279 does inherit through a hierarchy, because tax_query defaults
        // include_children to true, but the function that decides whether a body is
        // withheld reads wp_get_post_terms() at :2091, which returns assigned terms and
        // nothing else. Withholding is what this class answers.
        $ids = self::db_col(
            "SELECT DISTINCT object_id FROM {$wpdb->term_relationships}
              WHERE term_taxonomy_id IN (" . implode(',', array_unique($tt_ids)) . ')',
            'um_term_restricted_ids.objects'
        );

        return (false === $ids) ? array() : self::id_set($ids);
    }

    /**
     * Restrict Content term level restriction, plus the post type option's own reach.
     *
     * rcp_has_term_restrictions() treats any truthy return as restricted, so a saved
     * but empty array still counts. Only access_level = 'none' is stripped first.
     *
     * @return array
     */
    private static function rcp_term_restricted_ids()
    {
        global $wpdb;

        if ('3.0' !== self::rcp_mode()) {
            return array();
        }

        $rows = self::db_results(
            "SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE meta_key = 'rcp_restricted_meta'",
            'rcp_term_restricted_ids.termmeta'
        );
        if (false === $rows) {
            return array();
        }

        $terms = array();
        foreach ((array) $rows as $row) {
            $restrictions = self::safe_unserialize($row['meta_value']);
            if (is_array($restrictions)
                && !empty($restrictions['access_level'])
                && 'none' === strtolower((string) $restrictions['access_level'])
            ) {
                unset($restrictions['access_level']);
            }
            if (!empty($restrictions)) {
                $terms[] = absint($row['term_id']);
            }
        }

        // Sites predating term meta keep the same array in an option per term, and the
        // plugin falls back to it whenever get_term_meta() comes back empty for that
        // term. option_name is uniquely indexed, so a prefix LIKE is a range scan.
        $legacy = self::db_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'rcp\\_category\\_meta\\_%'",
            'rcp_term_restricted_ids.legacy_options'
        );
        if (false === $legacy) {
            return array();
        }
        foreach ((array) $legacy as $row) {
            $term_id = absint(str_replace('rcp_category_meta_', '', $row['option_name']));
            if (!$term_id || in_array($term_id, $terms, true)) {
                continue;
            }
            $restrictions = self::safe_unserialize($row['option_value']);
            if (is_array($restrictions)
                && !empty($restrictions['access_level'])
                && 'none' === strtolower((string) $restrictions['access_level'])
            ) {
                unset($restrictions['access_level']);
            }
            if (!empty($restrictions)) {
                $terms[] = $term_id;
            }
        }

        if (!$terms) {
            return array();
        }

        $in = implode(',', array_unique($terms));

        $ids = self::db_col(
            "SELECT DISTINCT tr.object_id
               FROM {$wpdb->term_relationships} tr
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
              WHERE tt.term_id IN ({$in})",
            'rcp_term_restricted_ids.objects'
        );

        return (false === $ids) ? array() : self::id_set($ids);
    }

    /**
     * Paid Memberships Pro restricted post IDs.
     *
     * Error handling here is deliberately not the same as everywhere else. A MISSING
     * table means PMPro has never created its schema, so it restricts nothing, and that
     * must read as "no restrictions" rather than as a failure. Any OTHER error means the
     * tables exist and could not be read, which is unknown and fails closed.
     *
     * The table presence test is a SHOW TABLES rather than a match on the error text,
     * so the distinction does not rest on a MySQL message string.
     *
     * @return array
     */
    private static function pmpro_restricted_ids()
    {
        global $wpdb;

        $pages  = $wpdb->prefix . 'pmpro_memberships_pages';
        $cats   = $wpdb->prefix . 'pmpro_memberships_categories';
        $levels = $wpdb->prefix . 'pmpro_membership_levels';

        $present = self::db_col(
            $wpdb->prepare("SHOW TABLES LIKE %s", $wpdb->esc_like($wpdb->prefix . 'pmpro_') . '%'),
            'pmpro_restricted_ids.show_tables'
        );
        if (false === $present) {
            return array();
        }
        $present = array_map('strval', (array) $present);
        if (!in_array($pages, $present, true) || !in_array($cats, $present, true) || !in_array($levels, $present, true)) {
            // PMPro is loaded but its schema is not there. Nothing is restricted, and
            // this is not a failure.
            return array();
        }

        // Any row restricts, even an orphan pointing at a deleted level: the plugin's
        // own query LEFT JOINs the levels table with no IS NOT NULL guard, so the
        // orphan still produces a result row and still denies.
        $by_page = self::db_col("SELECT DISTINCT mp.page_id FROM {$pages} mp", 'pmpro_restricted_ids.pages');
        if (false === $by_page) {
            return array();
        }

        // Category and tag rules apply to post_type = 'post' only, across category and
        // post_tag only, and only while the level still exists. Joined through
        // term_taxonomy so term_id is compared to term_id; PMPro's own search filter
        // compares category_id to term_taxonomy_id, which is wrong wherever those
        // diverge, and the front end gate is the behaviour we mirror.
        $by_term = self::db_col(
            "SELECT DISTINCT tr.object_id
               FROM {$wpdb->term_relationships} tr
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
              WHERE tt.taxonomy IN ('category','post_tag')
                AND p.post_type = 'post'
                AND tt.term_id IN (
                    SELECT mc.category_id FROM {$cats} mc
                    INNER JOIN {$levels} m ON m.id = mc.membership_id
                )",
            'pmpro_restricted_ids.terms'
        );
        if (false === $by_term) {
            return array();
        }

        $ids = array_merge((array) $by_page, (array) $by_term);
        $set = self::id_set($ids);
        if (!$set) {
            return array();
        }

        // Attachments and revisions resolve to their parent.
        $in       = implode(',', array_keys($set));
        $children = self::db_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('attachment','revision') AND post_parent IN ({$in})",
            'pmpro_restricted_ids.children'
        );
        if (false === $children) {
            return $set;
        }

        foreach (self::id_set($children) as $id => $unused) {
            $set[$id] = true;
        }

        return $set;
    }

    /**
     * Parse wc_memberships_rules once. Shared by the post type gate and the set builder.
     *
     * @return array
     */
    private static function wc_memberships_rules()
    {
        $blog = self::blog_key();
        if (isset(self::$wc_rules[$blog])) {
            return self::$wc_rules[$blog];
        }

        global $wpdb;

        $parsed = array(
            'post_type_wide'   => array(),
            'explicit_ids'     => array(),
            'taxonomy_wide'    => array(),
            'term_ids'         => array(),
            // Taxonomies named by any term rule, for the post type guard WCM applies.
            'term_taxonomies'  => array(),
            // [ post_type => int[] ]. Seeds for the opt in ancestor walk, and nothing
            // else may seed it. See the walk in wc_memberships_restricted_ids().
            'inherit_seeds'    => array(),
            'has_force_public' => false,
        );

        $rules = get_option('wc_memberships_rules', array());
        if (!is_array($rules) || !$rules) {
            self::$wc_rules[$blog] = $parsed;
            return self::$wc_rules[$blog];
        }

        $plan_status = array();

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $rule_type = isset($rule['rule_type']) ? $rule['rule_type'] : '';

            // content_restriction gates ordinary content. product_restriction blocks
            // viewing only when access_type is 'view'; with 'purchase' the product page
            // stays readable. purchasing_discount never restricts.
            if ('content_restriction' !== $rule_type) {
                if ('product_restriction' !== $rule_type) {
                    continue;
                }
                if ('view' !== (isset($rule['access_type']) ? $rule['access_type'] : '')) {
                    continue;
                }
            }

            $plan_id = isset($rule['membership_plan_id']) ? absint($rule['membership_plan_id']) : 0;
            if (!$plan_id) {
                continue;
            }
            if (!isset($plan_status[$plan_id])) {
                $plan_status[$plan_id] = self::safe_call('get_post_status', array($plan_id), '');
            }
            // Rules on a draft or trashed plan do not apply. The rule's own 'active'
            // flag is never consulted for content restriction.
            if ('publish' !== $plan_status[$plan_id]) {
                continue;
            }

            $type = isset($rule['content_type']) ? $rule['content_type'] : '';
            $name = isset($rule['content_type_name']) ? $rule['content_type_name'] : '';

            // "Did the rule name any objects" and "which of them are usable" are two
            // different questions and WCM answers the first one on the RAW array:
            // src/class-wc-memberships-rules.php:497-512 tests empty( $rule_object_ids ),
            // and set_object_ids() stores an unfiltered absint(), so a 0 survives
            // (src/class-wc-memberships-membership-plan-rule.php:526-533). A rule whose
            // object_ids are all invalid is therefore an explicit ids rule that matches
            // nothing, NOT a post type wide gate. Deciding it on the filtered array
            // turned such a rule into a gate over every page on the site.
            $named   = (!empty($rule['object_ids']) && is_array($rule['object_ids']));
            $objects = $named ? array_filter(array_map('absint', $rule['object_ids'])) : array();

            if ('post_type' === $type && $name) {
                if ($named) {
                    $parsed['explicit_ids'] = array_merge($parsed['explicit_ids'], $objects);
                    // Only a content_restriction post type rule with explicit object ids
                    // can propagate to descendants, and only to descendants of its own
                    // post type. Verified: WCM's get_grandchildren() passes
                    // post_type => content_type_name to get_posts(), and its rule matcher
                    // reaches the inheritance branch only when the queried post's type
                    // equals content_type_name. Seeding the walk from anything else
                    // over-removes, twice measured. See access-pmpro-wcm.md section 2.
                    if ('content_restriction' === $rule_type) {
                        if (!isset($parsed['inherit_seeds'][$name])) {
                            $parsed['inherit_seeds'][$name] = array();
                        }
                        $parsed['inherit_seeds'][$name] = array_merge($parsed['inherit_seeds'][$name], $objects);
                    }
                } else {
                    $parsed['post_type_wide'][$name] = true;
                }
            } elseif ('taxonomy' === $type && $name) {
                // Same distinction as the post type branch above.
                if ($named) {
                    foreach ($objects as $term_id) {
                        $parsed['term_ids'][] = $term_id;
                        // Child terms inherit.
                        $children = self::safe_call('get_term_children', array($term_id, $name), array());
                        if (is_array($children)) {
                            $parsed['term_ids'] = array_merge($parsed['term_ids'], array_map('absint', $children));
                        }
                    }
                } else {
                    $parsed['taxonomy_wide'][$name] = true;
                }
                // The taxonomy a term rule belongs to, so the post type guard in
                // wc_memberships_restricted_ids() can be applied per taxonomy.
                $parsed['term_taxonomies'][$name] = $name;
            }
        }

        $force_public = self::db_var(
            "SELECT post_id FROM {$wpdb->postmeta}
              WHERE meta_key = '_wc_memberships_force_public' AND meta_value = 'yes' LIMIT 1",
            'wc_memberships_rules.force_public'
        );
        // On failure the safe reading is that the escape hatch is NOT in use, which
        // keeps post type wide rules in gated_post_types() rather than expanding them to
        // ids and then subtracting a set we could not read. The batch fails closed
        // anyway, because db_var() recorded the failure.
        $parsed['has_force_public'] = (false === $force_public) ? false : (bool) $force_public;

        self::$wc_rules[$blog] = $parsed;
        return self::$wc_rules[$blog];
    }

    /**
     * WooCommerce Memberships restricted post IDs.
     *
     * @return array
     */
    private static function wc_memberships_restricted_ids()
    {
        global $wpdb;

        $rules = self::wc_memberships_rules();
        $set   = self::id_set($rules['explicit_ids']);

        // Whole post types are handled by gated_post_types() when nothing can override
        // them. They only reach here when at least one post on the site carries
        // _wc_memberships_force_public, which is the final AND in the plugin's own test.
        if ($rules['post_type_wide'] && $rules['has_force_public']) {
            $in   = "'" . implode("','", array_map('esc_sql', array_keys($rules['post_type_wide']))) . "'";
            $rows = self::db_col(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status = 'publish'",
                'wc_memberships_restricted_ids.post_type_wide'
            );
            foreach (self::id_set(false === $rows ? array() : $rows) as $id => $unused) {
                $set[$id] = true;
            }
        }

        // Term rules, with the post type guard WCM itself applies.
        //
        // src/class-wc-memberships-rules.php guards its taxonomy match with
        // in_array( get_post_type( $object_id ), $taxonomy_object->object_type ), so a
        // post carrying a term from a taxonomy that is no longer registered for its post
        // type is served by WCM. Measured on MySQL: with category registered for the CPT
        // both agreed the post was withheld; removing only that registration flipped the
        // front end to READABLE while the class still said WITHHELD. Over-removal, so
        // the guard belongs here too.
        if ($rules['term_ids']) {
            $in   = implode(',', array_unique(array_map('absint', $rules['term_ids'])));
            $rows = self::db_results(
                "SELECT DISTINCT tr.object_id AS object_id, tt.taxonomy AS taxonomy, p.post_type AS post_type
                   FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                  WHERE tt.term_id IN ({$in})",
                'wc_memberships_restricted_ids.term_ids'
            );
            foreach (self::wcm_guarded_ids($rows) as $id => $unused) {
                $set[$id] = true;
            }
        }

        if ($rules['taxonomy_wide']) {
            $in   = "'" . implode("','", array_map('esc_sql', array_keys($rules['taxonomy_wide']))) . "'";
            $rows = self::db_results(
                "SELECT DISTINCT tr.object_id AS object_id, tt.taxonomy AS taxonomy, p.post_type AS post_type
                   FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                   INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
                  WHERE tt.taxonomy IN ({$in})",
                'wc_memberships_restricted_ids.taxonomy_wide'
            );
            foreach (self::wcm_guarded_ids($rows) as $id => $unused) {
                $set[$id] = true;
            }
        }

        // Ancestor inheritance is opt in, applies to content restriction only, and walks
        // one post type at a time. Seeding it from the whole restricted set was wrong in
        // two directions, both measured against anonymous curl on MySQL: a descendant of
        // a post restricted by a TAXONOMY rule was withheld while WCM served it, and a
        // descendant of a different POST TYPE than the rule's was withheld while WCM
        // served it. WCM inherits only from content_restriction post type rules that name
        // object ids, and only within content_type_name. Descendants of a
        // product_restriction target never inherit either.
        if ($rules['inherit_seeds'] && 'yes' === get_option('wc_memberships_inherit_restrictions')) {
            foreach ($rules['inherit_seeds'] as $post_type => $seed_ids) {
                $frontier = array_unique(array_map('absint', $seed_ids));
                $seen     = array_fill_keys($frontier, true);
                $depth    = 0;
                while ($frontier && $depth < self::ANCESTOR_DEPTH) {
                    $depth++;
                    $in   = implode(',', $frontier);
                    $rows = self::db_col(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts}
                              WHERE post_parent IN ({$in}) AND post_type = %s AND post_status = 'publish'",
                            $post_type
                        ),
                        'wc_memberships_restricted_ids.inherit'
                    );
                    if (false === $rows) {
                        break;
                    }
                    $next = array();
                    // $seen, not $set. A descendant already restricted by some other rule
                    // still has to be walked through, or its own children are missed.
                    foreach (self::id_set($rows) as $id => $unused) {
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            $set[$id]  = true;
                            $next[$id] = $id;
                        }
                    }
                    $frontier = $next;
                }
            }
        }

        // The public override wins over everything.
        if ($set && $rules['has_force_public']) {
            $rows   = self::db_col(
                "SELECT post_id FROM {$wpdb->postmeta}
                  WHERE meta_key = '_wc_memberships_force_public' AND meta_value = 'yes'",
                'wc_memberships_restricted_ids.force_public'
            );
            $public = self::id_set(false === $rows ? array() : $rows);
            if ($public) {
                $in       = implode(',', array_keys($public));
                $rows     = self::db_col(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_parent IN ({$in})",
                    'wc_memberships_restricted_ids.variations'
                );
                $variants = self::id_set(false === $rows ? array() : $rows);
                foreach ($variants as $id => $unused) {
                    $public[$id] = true;
                }
            }
            $set = array_diff_key($set, $public);
        }

        return $set;
    }

    /**
     * Apply WooCommerce Memberships' own taxonomy to post type guard to a term query's
     * rows.
     *
     * @param array|false $rows [ object_id, taxonomy, post_type ]
     * @return array [ post_id => true ]
     */
    private static function wcm_guarded_ids($rows)
    {
        if (false === $rows) {
            return array();
        }

        $object_types = array();
        $set          = array();

        foreach ((array) $rows as $row) {
            $taxonomy = isset($row['taxonomy']) ? (string) $row['taxonomy'] : '';
            $type     = isset($row['post_type']) ? (string) $row['post_type'] : '';
            $id       = isset($row['object_id']) ? absint($row['object_id']) : 0;
            if (!$id || '' === $taxonomy) {
                continue;
            }

            if (!isset($object_types[$taxonomy])) {
                $object = self::safe_call('get_taxonomy', array($taxonomy), null);
                // An unregistered taxonomy has no object_type list at all. WCM's guard
                // is in_array() against that list, so nothing matches and nothing is
                // restricted through it.
                $object_types[$taxonomy] = ($object && isset($object->object_type))
                    ? array_map('strval', (array) $object->object_type)
                    : array();
            }

            if (in_array($type, $object_types[$taxonomy], true)) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    /* ---------------------------------------------------------------------------
     * Tier 3 detection. Existence, never identity.
     * ------------------------------------------------------------------------ */

    /**
     * Restrict User Access.
     *
     * The decisive case this exists to get right: a site using RUA only for capability
     * edits, admin area denial, toolbar hiding and nav menu visibility, with zero
     * condition groups, gates no front end content at all. Failing closed on plugin
     * presence would silently empty that site's file.
     *
     * @return array [ gates_content, scope, post_types ]
     */
    private static function detect_rua()
    {
        $blog = self::blog_key();
        if (isset(self::$rua_detect[$blog])) {
            return self::$rua_detect[$blog];
        }

        global $wpdb;

        $memo = array('gates_content' => false, 'scope' => 'none', 'post_types' => array());
        if (!self::plugin_active('rua')) {
            self::$rua_detect[$blog] = $memo;
            return self::$rua_detect[$blog];
        }

        // Only an active level gates. A draft or trashed level does not.
        $levels = self::db_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='restriction' AND post_status='publish'",
            'detect_rua.levels'
        );
        // A failed read here would silently report "gates nothing". The batch fails
        // closed on the recorded failure; returning the empty memo is not a verdict
        // anyone acts on, because filter_publicly_readable() withholds everything.
        if (false === $levels) {
            return $memo;
        }
        if (!$levels) {
            self::$rua_detect[$blog] = $memo;
            return self::$rua_detect[$blog];
        }

        // NOT narrowed by the level's non-member action, and that is a measured decision
        // rather than an omission. A level whose action is "Redirect" with no page
        // selected looks inert, and is not: _ca_page = 0 reaches
        // get_permalink( 0 ) at level.php:363, and get_post( 0 ) falls back to
        // $GLOBALS['post'], so the level redirects the post TO ITSELF and the body is
        // never served. Measured on MySQL, RUA 2.8, WP 7.0.2: HTTP 302 to
        // /r3-rua-post/?redirect_to=%2Fr3-rua-post%2F, a redirect loop, canary absent.
        // The one shape that really does serve the full body is "Tease & Include" with
        // no page (the same get_post( 0 ) fallback feeds setup_postdata() at :426), and
        // narrowing on that would rest on that fallback rather than on anything RUA
        // states. Left as a known tier 3 over-removal. See fix-access-round3.md item
        // LOW-9.
        //
        // The deprecated 'negated' group status participates only when the legacy
        // option is on, and it inverts the match, so narrowing is unsound with it.
        $pt_options  = self::option_array('_ca_post_type_options');
        $use_negated = !empty($pt_options['restriction']['legacy']['negated_conditions']);
        $statuses    = array('publish', 'wpca_or', 'wpca_except');
        if ($use_negated) {
            $statuses[] = 'negated';
        }

        $in    = implode(',', array_map('absint', $levels));
        $slist = "'" . implode("','", array_map('esc_sql', $statuses)) . "'";
        // menu_order 2 is archive only and can never match a singular view. post_parent
        // is selected as well, because the except handling below is per LEVEL.
        $groups = self::db_results(
            "SELECT ID, post_status, post_parent FROM {$wpdb->posts}
              WHERE post_type='condition_group'
                AND post_parent IN ({$in})
                AND post_status IN ({$slist})
                AND menu_order <= 1",
            'detect_rua.groups'
        );
        if (false === $groups) {
            return $memo;
        }
        if (!$groups) {
            self::$rua_detect[$blog] = $memo;
            return self::$rua_detect[$blog];
        }

        $gin  = implode(',', array_map(function ($g) {
            return absint($g['ID']);
        }, $groups));
        $meta = self::db_results(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
              WHERE post_id IN ({$gin}) AND meta_key LIKE '\\_ca\\_%'",
            'detect_rua.group_meta'
        );
        if (false === $meta) {
            return $memo;
        }

        $by_group = array();
        foreach ((array) $meta as $row) {
            $by_group[(int) $row['post_id']][$row['meta_key']][] = $row['meta_value'];
        }

        // DENYLIST, not an allowlist. WPCA fires do_action('wpca/modules/init'), so an
        // add-on can register a module with an arbitrary id and an arbitrary _ca_<id>
        // key. An allowlist would silently ignore those and fail OPEN, and the spike's
        // first draft did exactly that.
        $not_conditions = array('_ca_autoselect', '_ca_opt_drip');
        $narrowable     = array('_ca_post_type', '_ca_taxonomy');

        $site_wide  = false;
        $post_types = array();
        $saw        = false;

        foreach ($groups as $group) {
            $gid = (int) $group['ID'];

            if ('negated' === $group['post_status']) {
                $site_wide = true;
                $saw       = true;
                continue;
            }

            // An EXCEPT group DISABLES its level for the views it matches, so its
            // conditions can only ever SHRINK the restricted set and must not be
            // harvested as scope. lib/wp-content-aware-engine/core.php:525-542: any
            // except group that is in context puts its level into $excepted, and every
            // group of an excepted level is then removed from $valid, so the level does
            // not apply at all on that view. The ordinary two group shape (restrict
            // posts, except on pages) previously contributed 'page' and deleted every
            // page on the site from the file.
            //
            // A level whose ONLY groups are except groups applies NOWHERE, not
            // everywhere: a level reaches WPCACore::get_posts() only through a group in
            // $valid (core.php:584-600, consumed by level.php:281 and :310), so with no
            // positive group there is nothing to validate on any view. It is still
            // counted as evidence that the level gates something, which on its own gates
            // no post type and is the conservative reading either way.
            if ('wpca_except' === $group['post_status']) {
                $saw = true;
                continue;
            }

            $gm = isset($by_group[$gid]) ? $by_group[$gid] : array();
            foreach ($not_conditions as $key) {
                unset($gm[$key]);
            }

            // An RUA condition group with NO conditions matches everything. WPCA builds
            // its context SQL as ( alias.meta_value IS NULL OR alias.meta_value IN (...) )
            // over LEFT JOINs, so an empty group satisfies every module clause. This is
            // the exact opposite of Content Control below.
            if (empty($gm)) {
                $site_wide = true;
                $saw       = true;
                continue;
            }

            foreach ($gm as $key => $values) {
                if ('_ca_static' === $key) {
                    // The front page is content. Search and 404 are not post content.
                    if (in_array('front-page', $values, true)) {
                        $saw          = true;
                        $post_types[] = 'page';
                    }
                    continue;
                }

                $saw = true;

                if (!in_array($key, $narrowable, true)) {
                    // author, page_template, date, post_format, bbpress, a language
                    // module or a third party module. Post type agnostic by
                    // construction, so the scope cannot be narrowed.
                    $site_wide = true;
                    continue;
                }

                if ('_ca_post_type' === $key) {
                    foreach ($values as $value) {
                        if (ctype_digit((string) $value)) {
                            $type = self::safe_call('get_post_type', array((int) $value), '');
                            if ($type) {
                                $post_types[] = $type;
                            }
                        } else {
                            $post_types[] = (string) $value;
                        }
                    }
                    continue;
                }

                foreach ($values as $value) {
                    if ('-1' === (string) $value) {
                        // Specific terms. Stored as TERM RELATIONSHIPS on the condition
                        // group post, not as postmeta. _ca_taxonomy holds only this
                        // sentinel, so a postmeta only reader learns nothing here.
                        $taxonomies = self::safe_call('get_taxonomies', array(array(), 'names'), array());
                        $terms      = self::safe_call('wp_get_object_terms', array($gid, $taxonomies), array());
                        if (!is_array($terms) || !$terms) {
                            $site_wide = true;
                            continue;
                        }
                        foreach ($terms as $term) {
                            $object = self::safe_call('get_taxonomy', array($term->taxonomy), null);
                            foreach (($object ? (array) $object->object_type : array()) as $type) {
                                $post_types[] = $type;
                            }
                        }
                        continue;
                    }

                    // A whole taxonomy. Narrow to the post types it is attached to.
                    $object = self::safe_call('get_taxonomy', array((string) $value), null);
                    $types  = $object ? (array) $object->object_type : array();
                    foreach ($types as $type) {
                        $post_types[] = $type;
                    }
                    if (!$types) {
                        $site_wide = true;
                    }
                }
            }
        }

        self::$rua_detect[$blog] = array(
            'gates_content' => $saw,
            'scope'         => !$saw ? 'none' : ($site_wide ? 'site' : 'post_types'),
            'post_types'    => array_values(array_unique($post_types)),
        );

        return self::$rua_detect[$blog];
    }

    /**
     * Content Control.
     *
     * @return array [ gates_content, scope, post_types ]
     */
    private static function detect_cc()
    {
        $blog = self::blog_key();
        if (isset(self::$cc_detect[$blog])) {
            return self::$cc_detect[$blog];
        }

        global $wpdb;

        $memo = array('gates_content' => false, 'scope' => 'none', 'post_types' => array());
        if (!self::plugin_active('cc')) {
            self::$cc_detect[$blog] = $memo;
            return self::$cc_detect[$blog];
        }

        $sets = array();

        // v2 and later: the CPT. Only published restrictions are ever loaded. Skipped
        // entirely on a 1.x site, where the post type is not registered by anything and
        // the query can only ever return nothing.
        if (post_type_exists('cc_restriction')) {
            $rows = self::db_results(
                "SELECT p.ID, pm.meta_value
                   FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'restriction_settings'
                  WHERE p.post_type = 'cc_restriction' AND p.post_status = 'publish'",
                'detect_cc.restrictions'
            );
            if (false === $rows) {
                return $memo;
            }
            foreach ((array) $rows as $row) {
                $sets[] = self::safe_unserialize($row['meta_value']);
            }
        }

        // v1: the option. Two different sites reach this. On a 2.x site it is loaded IN
        // PREFERENCE TO the CPT while the data version is still 1
        // (classes/Services/Restrictions.php:66-78), so a site that installed Content
        // Control before 2.0 can withhold content with zero cc_restriction posts. On a
        // site still RUNNING 1.x it is the only store there is, and the plugin replaces
        // the body through the_content (classes/Site/Posts.php:40-67). The parser below
        // is the same for both, including the 'who' sentinel: v1's Is::accessible()
        // (classes/Is.php:40-83) returns "not excluded" for logged_out when the visitor
        // is anonymous, exactly as v2's userStatus does.
        $legacy = self::option_array('jp_cc_settings');
        if (!empty($legacy['restrictions'])) {
            foreach ((array) $legacy['restrictions'] as $old) {
                $sets[] = array(
                    'userStatus' => isset($old['who']) ? $old['who'] : '',
                    '_v1'        => $old,
                );
            }
        }

        $site_wide  = false;
        $post_types = array();
        $saw        = false;

        foreach ($sets as $settings) {
            $settings = is_array($settings) ? $settings : array();

            // userStatus 'logged_out' is a public to anonymous sentinel. The plugin
            // returns whether the visitor MEETS the requirement and restricts when they
            // do not, and the logged_out branch is "return ! $logged_in", which is true
            // for our crawler. A restriction configured as "only logged out visitors may
            // see this" is fully public to us.
            if ('logged_out' === (isset($settings['userStatus']) ? $settings['userStatus'] : '')) {
                continue;
            }

            $items = array();
            if (isset($settings['_v1'])) {
                $conditions = isset($settings['_v1']['conditions']) ? (array) $settings['_v1']['conditions'] : array();
                foreach ($conditions as $group) {
                    foreach ((array) $group as $condition) {
                        if (!is_array($condition) || !isset($condition['target'])) {
                            $saw       = true;
                            $site_wide = true;
                            continue;
                        }
                        $items[] = array('name' => self::cc_remap_v1_target((string) $condition['target']));
                    }
                }
                if (!$items) {
                    $saw       = true;
                    $site_wide = true;
                    continue;
                }
            } elseif (isset($settings['conditions']['items']) && is_array($settings['conditions']['items'])) {
                $items = $settings['conditions']['items'];
            }

            // A Content Control restriction with NO conditions restricts NOTHING.
            // Restriction::check_rules() short circuits before the query is ever asked.
            // This is the opposite direction to RUA above, and the spike's first draft
            // had it backwards.
            if (!$items) {
                continue;
            }

            $flat = array();
            if (!self::cc_flatten($items, $flat, 0)) {
                // The condition tree was deeper than CC_FLATTEN_DEPTH, so part of it was
                // never classified and the scope cannot be trusted. Fail closed.
                self::record_reason('cc_tree_too_deep', 'Content Control condition tree exceeded ' . self::CC_FLATTEN_DEPTH . ' levels');
                $saw       = true;
                $site_wide = true;
                continue;
            }

            foreach ($flat as $rule) {
                $name = isset($rule['name']) ? $rule['name'] : '';

                // A negated rule inverts the blast radius: NOT content_is_doc restricts
                // every post type EXCEPT doc. The boolean stays sound, narrowing does not.
                if (!empty($rule['notOperand'])) {
                    $saw       = true;
                    $site_wide = true;
                    continue;
                }

                $class = self::cc_classify_rule($name);
                if ('none' === $class['kind']) {
                    continue;
                }
                $saw = true;
                if ('site' === $class['kind']) {
                    $site_wide = true;
                }
                if (!empty($class['post_type'])) {
                    $post_types[] = $class['post_type'];
                }
            }
        }

        self::$cc_detect[$blog] = array(
            'gates_content' => $saw,
            'scope'         => !$saw ? 'none' : ($site_wide ? 'site' : 'post_types'),
            'post_types'    => array_values(array_unique($post_types)),
        );

        return self::$cc_detect[$blog];
    }

    /**
     * Flatten Content Control's nested condition groups into a flat rule list.
     *
     * Bounded. The tree arrives from unserialize() on a database row, and unserialize()
     * can rebuild a self referential array through an R: reference, which would make
     * this recursion unbounded. Returning false rather than silently truncating keeps
     * the caller able to fail closed.
     *
     * @param array $items
     * @param array $flat
     * @param int   $depth
     * @return bool False when the depth cap was hit, so $flat is incomplete.
     */
    private static function cc_flatten($items, &$flat, $depth = 0)
    {
        if ($depth > self::CC_FLATTEN_DEPTH) {
            return false;
        }

        $complete = true;

        foreach ((array) $items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['type']) && 'group' === $item['type']) {
                $nested = isset($item['query']['items']) ? $item['query']['items'] : array();
                if (!self::cc_flatten($nested, $flat, $depth + 1)) {
                    $complete = false;
                }
                continue;
            }
            $flat[] = $item;
        }

        return $complete;
    }

    /**
     * Classify one Content Control rule name.
     *
     * The rule names are generated from the raw post type and taxonomy names, not from
     * labels and not from rewrite slugs, so the site's own registry supplies the exact
     * alternation and the name alone says which post type a rule addresses.
     *
     * @param string $name
     * @return array [ kind, post_type ]
     */
    private static function cc_classify_rule($name)
    {
        $none = array('kind' => 'none');

        // Fixed rules that cannot withhold a singular post body.
        if (in_array($name, array('user_is_logged_in', 'user_has_role', 'content_is_search_results', 'content_is_404_page'), true)) {
            return $none;
        }

        if ('entire_site' === $name) {
            return array('kind' => 'site');
        }
        if ('content_is_front_page' === $name || 'content_is_blog_index' === $name) {
            return array('kind' => 'post_type', 'post_type' => 'page');
        }

        // Objects rather than names, because has_archive is needed below.
        $objects = self::safe_call('get_post_types', array(array(), 'objects'), array());
        $objects = is_array($objects) ? $objects : array();
        $types   = array_keys($objects);
        $taxes   = self::safe_call('get_taxonomies', array(array(), 'names'), array());
        $taxes   = is_array($taxes) ? array_values($taxes) : array();

        // Archive rules first. They never withhold a singular body.
        //
        // Gated on has_archive, which is Content Control's own condition for registering
        // one at all: `if ( $post_type->has_archive || 'post' === $name )`
        // (classes/RuleEngine/Rules.php:362-369). Without that test this alternation also
        // swallowed the SINGULAR rule of any post type whose name ends in _archive, and a
        // site carrying post types 'x' and 'x_archive' would have had every x_archive
        // body published while Content Control withheld it. Contrived state, leak
        // direction, closed by asking the question the plugin asks. A post type with no
        // archive falls through to the singular alternation below, where
        // content_is_x_archive is matched as the singular rule of post type x_archive.
        foreach ($types as $type) {
            if ($name !== "content_is_{$type}_archive") {
                continue;
            }
            $object = isset($objects[$type]) ? $objects[$type] : null;
            if ('post' === $type || ($object && !empty($object->has_archive))) {
                return $none;
            }
        }
        foreach ($taxes as $tax) {
            if ($name === "content_is_{$tax}_archive"
                || $name === "content_is_selected_tax_{$tax}"
                || $name === "content_is_tax_{$tax}_with_id"
            ) {
                return $none;
            }
        }

        // Post with term, post type scoped.
        foreach ($types as $type) {
            foreach ($taxes as $tax) {
                if ($name === "content_is_{$type}_with_{$tax}") {
                    return array('kind' => 'post_type', 'post_type' => $type);
                }
            }
        }

        // Singular post type rules.
        foreach ($types as $type) {
            $candidates = array(
                "content_is_{$type}",
                "content_is_selected_{$type}",
                "content_is_{$type}_with_id",
                "content_is_child_of_{$type}",
                "content_is_ancestor_of_{$type}",
                "content_is_{$type}_with_template",
            );
            if (in_array($name, $candidates, true)) {
                return array('kind' => 'post_type', 'post_type' => $type);
            }
        }

        // Unrecognised. "Fail safe" is not one direction here, so ask Content Control's
        // own registry which of the two cases this is.
        //
        // A rule name that resolves to no registered rule, or to one with no callable
        // callback, restricts NOTHING: Models/RuleEngine/Rule.php:145-160 logs and
        // returns false, and a rule that returns false does not apply. Widening that to
        // site wide is pure over-removal, and cc_remap_v1_target() can generate exactly
        // such a name.
        //
        // A rule that IS registered but that this reader cannot classify, which is what a
        // Pro add-on rule looks like, does restrict, and for those widening is correct.
        $registered = self::cc_rule_registered($name);
        if (false === $registered) {
            return $none;
        }

        return array('kind' => 'site');
    }

    /**
     * Is a Content Control rule name backed by a registered rule with a callable
     * callback?
     *
     * @param string $name
     * @return bool|null Null when the registry could not be reached, which is treated as
     *                   "assume it restricts".
     */
    private static function cc_rule_registered($name)
    {
        if ('' === (string) $name || !function_exists('content_control')) {
            return null;
        }

        // content_control( 'rules' ) is Content Control's own container accessor
        // (content-control.php:145, inc/functions/globals.php:20), and
        // RuleEngine\Rules::get_rules() self initialises when the registration action has
        // not fired yet, so this is safe to ask at any point after the plugin loaded.
        $rules = self::safe_call('content_control', array('rules'), null);
        if (!is_object($rules) || !method_exists($rules, 'get_rule')) {
            return null;
        }

        $definition = self::safe_call(array($rules, 'get_rule'), array($name), null);
        if (!is_array($definition)) {
            return false;
        }

        $callback = isset($definition['callback']) ? $definition['callback'] : null;

        return (bool) ($callback && is_callable($callback));
    }

    /**
     * Map a Content Control v1 condition target onto a v2 rule name, mirroring the
     * plugin's own remap_condition_to_rule() for the name only.
     *
     * @param string $target
     * @return string
     */
    private static function cc_remap_v1_target($target)
    {
        if (preg_match('/^tax_(.+)_all$/', $target, $m)) {
            return 'content_is_' . $m[1] . '_archive';
        }
        if (preg_match('/^tax_(.+)_selected$/', $target, $m)) {
            return 'content_is_selected_tax_' . $m[1];
        }
        if (preg_match('/^tax_(.+)_ID$/', $target, $m)) {
            return 'content_is_tax_' . $m[1] . '_with_id';
        }

        // _w_ is tested here, second, because Content Control tests strpos( $target,
        // '_w_' ) > 0 BEFORE it splits off a generic modifier
        // (inc/functions/back-compat.php:90, :118, :127). Testing it last, after _all
        // and _template, classified a taxonomy named 'all' or 'template' differently
        // from the plugin.
        if (preg_match('/^(.+)_w_(.+)$/', $target, $m)) {
            return 'content_is_' . $m[1] . '_with_' . $m[2];
        }

        // The 'index' modifier, which is the first of the seven in back-compat.php's own
        // switch (:127-166) and was missing here. Without it a real v1 target such as
        // 'post_index' fell through to the raw target, which resolves to no registered
        // rule, which cc_classify_rule() then widened to a site wide gate: an empty
        // llms.txt for a site whose only v1 condition was a post type ARCHIVE, which
        // never withholds a singular body at all.
        if (preg_match('/^(.+)_index$/', $target, $m)) {
            return 'content_is_' . $m[1] . '_archive';
        }
        if (preg_match('/^(.+)_all$/', $target, $m)) {
            return 'content_is_' . $m[1];
        }
        if (preg_match('/^(.+)_selected$/', $target, $m)) {
            return 'content_is_selected_' . $m[1];
        }
        if (preg_match('/^(.+)_ID$/', $target, $m)) {
            return 'content_is_' . $m[1] . '_with_id';
        }
        if (preg_match('/^(.+)_children$/', $target, $m)) {
            return 'content_is_child_of_' . $m[1];
        }
        if (preg_match('/^(.+)_ancestors$/', $target, $m)) {
            return 'content_is_ancestor_of_' . $m[1];
        }
        if (preg_match('/^(.+)_template$/', $target, $m)) {
            return 'content_is_' . $m[1] . '_with_template';
        }

        // Unmapped v1 target. It is returned raw, exactly as Content Control leaves it
        // when post_type_exists() fails on the split (back-compat.php:127), and
        // cc_classify_rule() asks the registry what such a name actually does.
        return $target;
    }

    /* ---------------------------------------------------------------------------
     * Small helpers
     * ------------------------------------------------------------------------ */

    /**
     * Which Restrict Content engine is in force. '3.0', 'legacy', or ''.
     *
     * SYMBOLS WIN, and the option is consulted only when neither engine can be
     * identified from what is loaded.
     *
     * restrict_content_chosen_version is written and read only by the FREE Restrict
     * Content wrapper, restrictcontent.php:165-187 and :668. Neither engine touches it:
     * grep of 4.0.1 finds no hit anywhere under core/ or legacy/. So a site that ran the
     * free plugin in legacy mode and then moved to Restrict Content Pro keeps a stale
     * 'legacy' value forever, and reading 3.0-restricted posts with legacy semantics
     * ignores _is_paid, rcp_subscription_level, rcp_access_level, the post type rules and
     * the term rules: every 3.0-restricted post published.
     *
     * The engine symbols are disjoint by construction and describe what is actually
     * loaded, which is what decides behaviour:
     *   3.0     rcp_is_restricted_content()  core/includes/misc-functions.php:790
     *           rcp_user_can_access()        core/includes/member-functions.php:70
     *           Restrict_Content_Pro         core/includes/class-restrict-content.php:28
     *           RCP_PLUGIN_VERSION           core/includes/class-restrict-content.php:204
     *   legacy  RC_PLUGIN_VERSION            legacy/restrictcontent.php:24
     * and load_legacy_restrict_content() includes legacy/ only
     * (restrictcontent.php:245-248), so a legacy site defines none of the 3.0 symbols.
     * Where both resolve, which is the free plugin in legacy mode alongside RCP Pro, 3.0
     * wins because RCP Pro's 3.0 hooks are what withhold the body.
     *
     * Absence must NOT mean "skip the plugin". The option is absent forever on Restrict
     * Content 2.x, which predates it, and skipping leaked there: on a 2.2.2 install two
     * posts carrying rcp_user_level = 'Subscriber' and 'Administrator' were withheld from
     * anonymous curl while the skip published both. So a loaded plugin with no
     * identifiable engine falls back to legacy semantics rather than to nothing.
     *
     * @return string
     */
    private static function rcp_mode()
    {
        $has_30 = function_exists('rcp_is_restricted_content')
            || function_exists('rcp_user_can_access')
            || class_exists('Restrict_Content_Pro')
            || defined('RCP_PLUGIN_VERSION');

        if ($has_30) {
            return '3.0';
        }

        if (defined('RC_PLUGIN_VERSION')) {
            return 'legacy';
        }

        $mode = get_option('restrict_content_chosen_version');
        if ('3.0' === $mode || 'legacy' === $mode) {
            return $mode;
        }

        if (!self::plugin_active('rcp')) {
            return '';
        }

        return 'legacy';
    }

    /**
     * @param string $name
     * @return array
     */
    private static function option_array($name)
    {
        $value = get_option($name);
        return is_array($value) ? $value : array();
    }

    /**
     * First value of a candidate meta key for a post, or ''.
     *
     * @param array  $ctx
     * @param int    $post_id
     * @param string $key
     * @return string
     */
    private static function meta_single($ctx, $post_id, $key)
    {
        if (empty($ctx['meta'][$post_id][$key])) {
            return '';
        }
        return (string) $ctx['meta'][$post_id][$key][0];
    }

    /**
     * @param array $ids
     * @return array [ post_id => true ]
     */
    private static function id_set($ids)
    {
        $set = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            if ($id) {
                $set[$id] = true;
            }
        }
        return $set;
    }

    /**
     * OR-joined LIKE clause over a set of literal fragments.
     *
     * @param string   $column
     * @param string[] $fragments
     * @return string
     */
    private static function like_clause($column, array $fragments)
    {
        global $wpdb;

        $parts = array();
        foreach ($fragments as $fragment) {
            $parts[] = $wpdb->prepare("{$column} LIKE %s", '%' . $wpdb->esc_like($fragment) . '%');
        }

        return implode(' OR ', $parts);
    }

    /**
     * Public post types, used when a tier 3 verdict cannot be narrowed.
     *
     * is_post_type_viewable() is deliberately not used anywhere in this class. It
     * returns true === apply_filters( ... ), so a single third party callback returning
     * 1 instead of true would empty the entire file, and failing open does not help
     * because the gate is not failing.
     *
     * @return string[]
     */
    private static function public_post_types()
    {
        $types = self::safe_call('get_post_types', array(array('public' => true), 'names'), array());
        $types = is_array($types) ? array_values($types) : array();

        return array_values(array_diff($types, array('attachment', 'llms_txt')));
    }

    /**
     * Call a core or third party function and fail open on anything it throws.
     *
     * Everything routed through here is outside this plugin's control: core registry
     * lookups run third party filters, and an unregistered taxonomy or a broken add-on
     * must not be able to take the whole generation down.
     *
     * @param callable $callback
     * @param array    $args
     * @param mixed    $fallback
     * @return mixed
     */
    private static function safe_call($callback, array $args, $fallback)
    {
        if (!is_callable($callback)) {
            return $fallback;
        }
        try {
            return call_user_func_array($callback, $args);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
