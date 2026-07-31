<?php
/**
 * Plugin Name: Website LLMs.txt
 * Description: Generates and manages an llms.txt file, a structured, AI-ready index that helps large language models like ChatGPT, Claude, and Perplexity understand your site's most important content.
 * Version: 8.5.4
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
define('WEBSITE_LLMS_TXT_VERSION', '8.5.4');
// Schema version, moved only when the database layout changes. Kept apart from
// the plugin version so a patch release does not re-run the migration ladder. It
// must equal the highest step registered in LLMS_DB::steps(), which refuses to
// run the ladder at all if the two disagree.
//
// Not renamed with the others: LifterLMS defines no LLMS_DB_VERSION, checked
// against 10.0.10 and against every add-on constant in that tree, and this one
// is read only by our own settings screen.
define('LLMS_DB_VERSION', '3');

/*
 * Our own file, directory and URL, under a prefix taken from this plugin's own
 * slug so that nothing else can be holding it.
 *
 * These were LLMS_PLUGIN_FILE, LLMS_PLUGIN_DIR and LLMS_PLUGIN_URL, and the
 * first two of those belong to LifterLMS, which has defined them since long
 * before this plugin existed and requires its own autoloader from
 * LLMS_PLUGIN_DIR. Both plugins defining the same constant is a mutual fatal,
 * measured in both load orders: whichever loads second finds the constant
 * already defined and requires its own includes out of the other plugin's
 * directory. Guarding our defines does not fix it, it only moves which of the
 * two dies. The only fix is not to use the name, which matters now that 8.5.4
 * lists LifterLMS as a plugin it detects.
 *
 * LLMS_VERSION goes with them. LifterLMS defines that one too, from
 * llms_maybe_define_constant() in class-lifterlms.php, so on a site running both
 * plugins the old name is 10.0.10 rather than ours. That is not fatal, it is
 * worse than fatal in one place: it is the version this plugin would have
 * recorded against the file it generated.
 *
 * The old names are not defined at all, as aliases or otherwise. The note at the
 * bottom of this file says why, and it is worth reading before anybody adds them
 * back out of politeness.
 */
define('WEBSITE_LLMS_TXT_FILE', __FILE__);
define('WEBSITE_LLMS_TXT_DIR', plugin_dir_path(__FILE__));
define('WEBSITE_LLMS_TXT_URL', plugin_dir_url(__FILE__));

// Initialize plugin
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-db.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-lock.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-access.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-md.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-core.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-cache-manager.php';
require_once WEBSITE_LLMS_TXT_DIR . 'includes/class-llms-visibilitykit.php';

// Initialize the plugin
function llms_init() {
    new LLMS_MD();
    new LLMS_Core();
    new LLMS_Cache_Manager();
    new LLMS_VisibilityKit();
}

// Hook the initialization function
add_action('plugins_loaded', 'llms_init');

/**
 * Where the generated document can be served from.
 *
 * The root llms.txt, which the web server returns before WordPress is reached at
 * all, and the uploads copy, which get_llms_content() reads for the rewrite
 * rule. Those two and no others: these are the paths that answer the question
 * "does this site have a document right now".
 *
 * The uploads TEMP copy is deliberately absent. It is the scratch space a
 * generation appends to, 8.5.4 removed get_llms_content()'s fallback to it, and
 * nothing serves it on single site or on a network. Counting it here is what
 * made the post-update rebuild believe a killed run had left a document, so that
 * it declined to ask for the rebuild again and then deleted the scratch file
 * anyway: adversarial F5, and the site ended with no llms.txt and no route back.
 * A run's private working file is not evidence about the site.
 *
 * One home for the calculation, because two copies of it is how one of them ends
 * up missing the Flywheel case. Paths come from ABSPATH and the siteurl option,
 * not from LLMS_Generator::$llms_name, which is only assigned in
 * init_generator() on init:20 and is null anywhere earlier.
 *
 * wp_get_upload_dir() rather than wp_upload_dir(), because the latter creates
 * the directory as a side effect and this is called on ordinary requests to ask
 * a question, not to write anything.
 *
 * @return string[] Absolute paths, whether or not they exist.
 */
function llms_served_file_paths() {
    $paths = array();

    // Root llms.txt, handling the Flywheel split directory layout
    if (defined('FLYWHEEL_PLUGIN_DIR')) {
        $paths[] = trailingslashit(dirname(ABSPATH)) . 'www/llms.txt';
    } else {
        $paths[] = ABSPATH . 'llms.txt';
    }

    $domain = llms_generated_file_domain();
    if ('' !== $domain) {
        $upload_dir = wp_get_upload_dir();
        $paths[] = $upload_dir['basedir'] . '/' . $domain . '.llms.txt';
    }

    return $paths;
}

/**
 * Every file a generation owns on disk: the two served copies and the scratch
 * copy.
 *
 * This is the deletion list. The temp copy belongs on it, both for the migration
 * (it is 8.5.3 content sitting in the uploads directory) and for a run that has
 * to clean up after itself.
 *
 * @return string[] Absolute paths, whether or not they exist.
 */
function llms_generated_file_paths() {
    $paths = llms_served_file_paths();

    $domain = llms_generated_file_domain();
    if ('' !== $domain) {
        $upload_dir = wp_get_upload_dir();
        $paths[] = $upload_dir['basedir'] . '/' . $domain . '.temp.llms.txt';
    }

    return $paths;
}

/**
 * The host name the uploads copies are named after.
 *
 * @return string Empty when siteurl cannot be parsed, in which case there are no
 *                uploads copies to name.
 */
function llms_generated_file_domain() {
    $siteurl = get_option('siteurl');

    if (!$siteurl) {
        return '';
    }

    $domain = wp_parse_url($siteurl, PHP_URL_HOST);

    return $domain ? $domain : '';
}

/**
 * What is on disk right now, in a form that can be compared later.
 *
 * Size and modification time of each SERVED artifact that exists, in path order.
 * The scratch copy is not one of them; see llms_served_file_paths(). This
 * is how the plugin tells a file it wrote itself from one it did not, which is
 * the whole of the downgrade problem: llms_db_version is a schema number and
 * survives a rollback to 8.5.3 untouched, so keying the artifact cleanup on it
 * means a site that goes 8.5.4, back to 8.5.3, then forward again keeps serving
 * the document 8.5.3 regenerated in between. A fingerprint of the file itself
 * does not care what happened, only whether what is there now is what we left.
 *
 * Deliberately not a hash of the contents. This runs on every request and the
 * file can be megabytes; stat is a few microseconds. The failure mode of size
 * and mtime is that a rewrite producing a byte-identical file in the same second
 * is not noticed, and a byte-identical file is not a leak.
 *
 * The stat cache has to go first, and that is not decoration. This function is
 * called twice in the request that generates a file: once on init, when the
 * destinations hold the previous document or nothing at all, and once after the
 * generation has replaced them. PHP caches stat results per path, and a second
 * reading that came out of that cache would record the state from before the run
 * against a file written during it. The next request would then find a mismatch
 * it could not resolve and would delete and rebuild the document it had just
 * built, on every request, for ever. clearstatcache() with a path clears one
 * entry rather than the whole cache, so nothing else in the request pays for it.
 *
 * @return string Empty string when no artifact exists at all.
 */
function llms_generated_artifact_fingerprint() {
    $parts = array();

    foreach (llms_served_file_paths() as $path) {
        clearstatcache(true, $path);

        if (!file_exists($path)) {
            continue;
        }

        $parts[] = $path . ':' . (int) filesize($path) . ':' . (int) filemtime($path);
    }

    if (empty($parts)) {
        return '';
    }

    return md5(implode('|', $parts));
}

/**
 * Delete the three generated artifacts.
 *
 * Returns how many of them were there, which is what tells the migration whether
 * this site had a generated file at all. A site that has never generated one has
 * nothing to rebuild, and the difference matters: without it every brand new
 * install would run a full generation inside its first admin page load.
 *
 * @return int Number of artifacts that existed and are now gone.
 */
function llms_delete_generated_files() {
    $found = 0;

    foreach (llms_generated_file_paths() as $path) {
        if (file_exists($path)) {
            $found++;
            wp_delete_file($path);
        }
    }

    return $found;
}

/*
 * At file scope, not in LLMS_Core::__construct(), and this is not a style
 * preference. WordPress includes the plugin file during an activation request
 * AFTER plugins_loaded has already fired, so llms_init() never runs in that
 * request, LLMS_Core is never constructed and a registration made in its
 * constructor is never made at all. Measured on a network: the only callback on
 * activate_website-llms-txt/website-llms-txt.php was the probe looking for ours.
 * The consequence was that the rewrite rule was never added on activation, so
 * /llms.txt 404ed on every blog of every network and on every single site whose
 * ABSPATH is read only, which are exactly the sites with no root file to mask it.
 */
register_activation_hook(__FILE__, array('LLMS_Core', 'activate'));

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('llms_update_llms_file_cron');
    wp_clear_scheduled_hook('llms_scheduled_update');

    llms_delete_generated_files();

    flush_rewrite_rules();
});

/**
 * Rebuild the generated file once, on the first admin request after the
 * migration, when nothing else has rebuilt it yet.
 *
 * The migration deletes the file and schedules llms_update_llms_file_cron. On a
 * site whose WP-Cron never fires, and there are many, that scheduled event is
 * never run and the file would stay deleted for ever. Deleted is the safe state,
 * but the release notes say the file is rebuilt when you update, so it has to
 * actually be rebuilt. This is the same belt-and-braces the plugin already
 * settled on for "Clear caches", which stopped depending on cron in 8.5.3 for
 * exactly this reason.
 *
 * NOTHING IS CLAIMED HERE. This used to delete the rebuild flag before the work
 * and treat "the action returned" as "the document was rebuilt", and there are
 * three normal returns from update_llms_file() that produce no document at all,
 * plus every stop that never unwinds. The flag was gone, the scheduled event was
 * cleared on the way out, and the site was left with no llms.txt and no route
 * back to one. That is adversarial F1, and F5 is the same mistake wearing a
 * shutdown handler.
 *
 * So the outcome decides, and the outcome is recorded where it happens: the flag
 * is deleted by LLMS_DB::note_document_promoted() at the moment a document is
 * promoted, and by nothing else. A run that does nothing leaves it exactly as it
 * found it. There is no shutdown backstop here any more, because there is nothing
 * left for one to put back.
 *
 * claim_rebuild_attempt() only records that an attempt is being made, which is
 * what keeps a site whose generation always fails from paying a full crawl on
 * every admin page load for ever. Two overlapping admin requests can both pass
 * it; the generation lease behind them lets exactly one do any work.
 *
 * Not on AJAX. admin_init runs on admin-ajax.php too, and a full regeneration
 * inside somebody's heartbeat or inline-save response is a good way to turn a
 * one-off rebuild into a broken editor.
 *
 * Administrators only, and for two reasons rather than as a permission check.
 * admin_init also fires on admin-post.php, which runs before the auth check so
 * that admin_post_nopriv_* can work, so without this an anonymous request to
 * that endpoint would carry the regeneration. And a regeneration is slow enough
 * on a large site that the request wearing it should be one belonging to whoever
 * updated the plugin, not one belonging to a subscriber editing their profile.
 * On a site where no administrator visits, the scheduled event is the route.
 *
 * @return void
 */
function llms_maybe_rebuild_generated_file() {
    if (wp_doing_ajax() || !current_user_can('manage_options')) {
        return;
    }

    // Cheapest question first: on an install that owes nothing this is one lookup
    // in the autoload array every request already has in memory, and nothing else
    // here runs at all.
    if (!class_exists('LLMS_DB') || !LLMS_DB::rebuild_pending()) {
        return;
    }

    // Owed, and the state agrees. Anything else means somebody else's business:
    // in_flight is another request generating right now, and stale means the
    // artifact cleanup has not run yet in this request, which it does on init,
    // so the next admin page load will find this in order.
    if (LLMS_DB::LIFECYCLE_OWED !== LLMS_DB::lifecycle_state()) {
        return;
    }

    if (!LLMS_DB::claim_rebuild_attempt()) {
        return;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    try {
        // The action LLMS_Generator already listens on, so this is the same
        // rebuild WP-Cron would have run, with no second entry point to keep in
        // step.
        do_action('llms_update_llms_file_cron');
    } catch (\Throwable $e) {
        /*
         * Nothing that runs on admin_init on 40,000 installs may take wp-admin
         * down, whatever the cause. This is not theoretical: the assembled 8.5.4
         * turned the first admin page load after the update into a WordPress
         * critical error on any host whose WP_Filesystem transport cannot
         * connect, because the generator's own failure path threw. That
         * particular throw is fixed at source, and this is the backstop for the
         * next one, including one raised by somebody else's hook.
         *
         * Nothing is put back here, because nothing was taken. Whether a rebuild
         * is still owed was decided inside the run, at the point where the files
         * were settled, and a throw from somebody else's save_post after a
         * successful promote must not turn into a rebuild on every admin page
         * load for as long as that hook keeps throwing.
         */
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic for a failed rebuild, WP_DEBUG only.
            error_log('website-llms-txt: the post-update rebuild failed: ' . $e->getMessage());
        }

        return;
    }

    // Only a rebuild that actually produced a document makes the scheduled event
    // redundant, and the flag being gone is exactly that fact: it is deleted at
    // the promote and by nothing else. Clearing the event on a run that did
    // nothing is how a site loses its second route, which is half of adversarial
    // F1. A failed run has already re-armed the event through request_rebuild().
    if (!LLMS_DB::rebuild_pending()) {
        wp_clear_scheduled_hook('llms_update_llms_file_cron');
    }
}
add_action('admin_init', 'llms_maybe_rebuild_generated_file', 20);

/*
 * LLMS_PLUGIN_FILE, LLMS_PLUGIN_DIR, LLMS_PLUGIN_URL and LLMS_VERSION are
 * deliberately not defined anywhere in this plugin. Do not add them back.
 *
 * They were this plugin's constants up to 8.5.3 and they are also LifterLMS's,
 * which has defined the first three since long before this plugin existed and
 * requires its own autoloader out of LLMS_PLUGIN_DIR. Whichever plugin defines
 * them first owns them, and the other one then loads its own includes from the
 * wrong directory, which is a fatal on every request. 8.5.4 renamed ours to
 * WEBSITE_LLMS_TXT_FILE, WEBSITE_LLMS_TXT_DIR, WEBSITE_LLMS_TXT_URL and
 * WEBSITE_LLMS_TXT_VERSION.
 *
 * Defining the old names as aliases as well, guarded and late on plugins_loaded,
 * was tried and is worse than doing nothing. Activating LifterLMS from the
 * Plugins screen includes its main file inside a request where plugins_loaded
 * has already fired, so the alias is already defined, its own guarded define is
 * skipped, and it requires vendor/autoload.php out of this plugin's directory.
 * Measured: the site goes down on activation, and we are the cause. Renaming our
 * constants fixed our half of the collision and the alias handed the other half
 * straight back.
 *
 * A third party reading LLMS_PLUGIN_DIR and expecting this plugin's directory
 * was already getting LifterLMS's answer on any site running both, so there is
 * no working behaviour being withdrawn here.
 */
