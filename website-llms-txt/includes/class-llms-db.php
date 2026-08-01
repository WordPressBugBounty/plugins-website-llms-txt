<?php
/**
 * Database schema versioning.
 *
 * @package Website_LLMS_TXT
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The schema upgrade ladder for wp_llms_txt_cache.
 *
 * Until now the table was only ever created, never changed: dbDelta ran when
 * the table was missing and nothing else touched the schema, so there was no
 * way to ship a column or an index to the installs that already exist.
 *
 * The ladder is a list of numbered steps. Each step is written to be safe to
 * run on any install that has not run it yet, and safe to run again if the
 * request that ran it died before the number was recorded. The number is only
 * recorded once the step reports success, so a step that could not finish is
 * simply retried on the next request rather than being skipped for ever.
 *
 * Every step must also declare how it behaves when two requests run it at the
 * same time, because nothing serialises the hook it runs on. "Safe to run
 * twice" is not the same property and is not enough: two overlapping requests
 * both read the same version, both find the step pending, and both run it,
 * concurrently. Observed in the harness, six concurrent requests issuing
 * between two and six CREATE TABLEs per round.
 *
 *   STEP_CONVERGENT  Running it twice at once produces the same result as
 *                    running it once. CREATE TABLE and ALTER TABLE qualify.
 *                    Cheap: no lock is taken.
 *   STEP_EXCLUSIVE   Anything else. Deleting and rewriting the generated file
 *                    is the case that will actually arise. The ladder takes a
 *                    short lease lock before running one, and refuses to record
 *                    the version if the lease was lost while the step ran.
 *
 * The declaration is mandatory. A step registered in any other shape aborts the
 * ladder rather than being run unlocked on a guess.
 *
 * Alongside the ladder there is one repair path, ensure_baseline(), which runs on
 * every request, before any step. The stored version records that the steps ran,
 * not that their result is still there, and a table can be lost long after the
 * ladder has finished. The repair records no version. When it has had to recreate
 * the table it replays the convergent steps the install has already run, so the
 * table comes back with the schema its recorded version claims rather than as a
 * bare baseline.
 *
 * Anything the ladder cannot do is written to llms_db_condition, in a shape the
 * settings screen can render. _doing_it_wrong() is invisible with WP_DEBUG off
 * and a step's Throwable is swallowed on purpose, so without that record a
 * wedged ladder is completely silent on production.
 *
 * LLMS_DB_VERSION is deliberately its own constant. Keying the ladder on
 * LLMS_VERSION would re-run every step on every patch release, and a step that
 * rebuilds the generated file would make llms.txt disappear each time the
 * plugin updated.
 *
 * llms_db_version does not exist before 8.5.4, so its absence is what identifies
 * an install that predates the ladder. That is the entire pre-8.5.4 install base
 * and it is the reason step numbering starts at 1 rather than at 0.
 *
 * The class is not final because the test fixtures subclass it.
 */
class LLMS_DB
{
    /**
     * Running the step twice at the same time produces the same result as
     * running it once, so no lock is taken. CREATE TABLE and ALTER TABLE
     * qualify.
     */
    const STEP_CONVERGENT = 'convergent';

    /**
     * Anything else. Serialised behind a lease lock, and the version is not
     * recorded unless the lease was still held when the step returned.
     */
    const STEP_EXCLUSIVE = 'exclusive';

    /**
     * Name of the read index, in one place because two things ask about it.
     *
     * step_add_read_index() creates it and schema_status() reports on whether it
     * is there. A second literal is how the settings screen ends up reporting on
     * an index nothing creates.
     */
    const READ_INDEX = 'llms_read';

    /**
     * Oldest plugin version whose generated file may be kept.
     *
     * A site whose artifact record names a version older than this has its file
     * deleted and rebuilt, whatever its schema version says. This is the one
     * line that says "everybody rebuilds in this release", and it is deliberately
     * a plugin version and not a schema version: the file is a product of the
     * generator, not of the table, and 8.5.4 is the release that changed what is
     * allowed to be in it.
     *
     * Raise it in a release that changes what may appear in the file. Leave it
     * alone in a release that does not, because moving it makes 40,000 sites
     * regenerate.
     */
    const ARTIFACT_REBUILD_SINCE = '8.5.4';

    /**
     * The five states the generated document can be in. See lifecycle_state(),
     * which is the only place any of them is decided.
     */
    const LIFECYCLE_CURRENT   = 'current';
    const LIFECYCLE_NONE      = 'none';
    const LIFECYCLE_OWED      = 'owed';
    const LIFECYCLE_IN_FLIGHT = 'in_flight';
    const LIFECYCLE_STALE     = 'stale';

    /**
     * Attempts at an owed rebuild that are made as soon as anything notices.
     *
     * The first failure has to be retried immediately, because a single crash
     * must cost a site its document only until the next request notices. A site
     * where generation fails every time is a different thing, and paying a full
     * crawl for it on every admin page load, on every one of 40,000 installs, is
     * how a fix becomes an outage. After this many attempts the automatic routes
     * slow down to one per REBUILD_RETRY_AFTER.
     */
    const REBUILD_FREE_ATTEMPTS = 2;

    /**
     * Seconds between automatic rebuild attempts once the free ones are spent.
     *
     * Applies to the admin one-shot and to the interval the failure path
     * schedules its WP-Cron retry at. It does not apply to anything a human
     * pressed: Generate now, Clear caches and Delete and recreate all call
     * update_llms_file() directly and are never rate limited.
     */
    const REBUILD_RETRY_AFTER = 300;

    /**
     * Seconds before the same fault may rewrite its record with a new detail.
     *
     * The condition record is not written at all while nothing about it changes.
     * This bounds the one case where something changes on every request: an
     * exception message carrying a value or an id, from a step that fails every
     * time. See record_condition().
     */
    const CONDITION_REFRESH_AFTER = 60;

    /**
     * Delete-and-rebuild cycles allowed inside ARTIFACT_CYCLE_WINDOW.
     *
     * THREE, and the number is chosen against the one case that has to keep
     * working. The 8.5.4 migration is ONE cycle: the pre-fix document is found,
     * removed and rebuilt, and the site is current from then on. A rollback to
     * 8.5.3 and forward again is one more. A file restored from a backup is one
     * more. Nothing legitimate produces a fourth inside an hour, so three leaves
     * every case the cleanup exists for untouched while capping the cost of a
     * cause we cannot see at three full crawls an hour instead of one hundred and
     * twenty.
     *
     * It cannot be lower. Two would put the migration one cycle from the ceiling
     * on a site that also had a backup restored, and a site that stands down
     * during the migration is a site still serving the document 8.5.4 exists to
     * withdraw. The first cycle is never throttled, whatever this is set to; see
     * artifact_cycle_limit_reached().
     */
    const ARTIFACT_CYCLE_LIMIT = 3;

    /**
     * The window the cycle count is measured over, and ages out of.
     *
     * ONE HOUR, and it is a rolling window rather than a fixed one: the
     * timestamps are kept and the old ones drop off, so a site does not get a
     * fresh allowance on the hour.
     *
     * Long enough that a runaway is stopped rather than slowed. The loop this
     * bounds runs at one cycle every 30 to 60 seconds, so a window shorter than
     * about ten minutes would still let a site pay a crawl every few minutes
     * indefinitely, which on a large site is the same outage arriving slower.
     * Short enough that a site whose real trouble has been fixed by hand is
     * generating again within the hour without anybody having to know this
     * mechanism exists.
     */
    const ARTIFACT_CYCLE_WINDOW = 3600;

    /**
     * Whether this request has already counted an automatic rebuild attempt.
     *
     * Request scoped on purpose. It exists so the admin one-shot, which counts
     * its attempt up front in claim_rebuild_attempt(), is not counted a second
     * time by note_document_absent() when its run then fails. See
     * note_rebuild_attempt().
     *
     * @var bool
     */
    protected static $attempt_recorded_this_request = false;

    /**
     * Run every step this install has not run yet.
     *
     * Cheap to call on every request: one autoloaded option read, a numeric
     * comparison, and the one existence check that keeps the baseline repairable.
     * That existence check is the whole per-request cost of the ladder on a
     * healthy install, and it is one query rather than none. See
     * ensure_baseline().
     *
     * @return bool True when the install is usable at the target version by the
     *              time we return.
     */
    public static function maybe_upgrade()
    {
        $installed = static::installed_version();
        $target    = static::target_version();

        /*
         * The repair runs before the ladder, not only on the request where the
         * ladder has nothing left to climb.
         *
         * It used to sit on the $installed >= $target early return, which is one
         * of three ways out of this method, so an install part way up the ladder
         * never reached it: with installed = 1 and target = 2 the loop below
         * skips step 1 (it is already recorded) and runs step 2 against a table
         * that is not there, nothing recreates it, and the install is wedged for
         * good with a recorded condition that blames the wrong step. The other
         * two exits, the constant mismatch below and a step returning false, lost
         * the repair the same way. Measured on MySQL before the move, and again
         * after it.
         *
         * Nothing here depends on the version, so there is no ordering reason for
         * it to have been on that branch. Running it first also means a step
         * whose ALTER needs the table finds one.
         */
        $baseline = static::ensure_baseline($installed >= $target);

        /*
         * Not a schema concern, and deliberately not keyed on the schema version
         * or on any of this method's exits. See maybe_clean_artifacts(): a file
         * left behind by a version that should not have written it has to be
         * noticed however the install got into that state, and llms_db_version
         * cannot see a rollback because 8.5.3 does not move it.
         *
         * After the baseline, because the cleanup deletes cache rows and a table
         * that is not there yet would fail it for the wrong reason. Before the
         * ladder, because the ladder can exit three ways and this must not
         * depend on which.
         */
        if ($baseline) {
            static::maybe_clean_artifacts();
        }

        if ($installed >= $target) {
            // Nothing to climb, and the ladder saying "done" is not the same as
            // the table being there.
            return $baseline;
        }

        if (!$baseline) {
            /*
             * No table, and we could not make one. Running the steps now would
             * replace the accurate cache_table_missing record with whatever the
             * first step to touch the table failed with, which points a support
             * reader at the step instead of at the missing table. Retried on the
             * next request like any other ladder failure.
             */
            return false;
        }

        $steps = static::steps();
        ksort($steps, SORT_NUMERIC);

        /*
         * LLMS_DB_VERSION has to name a real step.
         *
         * Raising the constant past the last registered step used to be closed
         * by jumping the stored version straight to the target. That silently
         * skipped, for ever, any step later added at one of the numbers it
         * jumped over: the install already claims to be past them. Withdraw a
         * step by leaving a no-op in its place instead, so every number the
         * ladder passes through has something registered against it.
         */
        $last_step = empty($steps) ? 0 : (int) max(array_keys($steps));
        if ($last_step !== $target) {
            _doing_it_wrong(
                __METHOD__,
                'LLMS_DB_VERSION must match the highest registered ladder step.',
                '8.5.4'
            );
            static::record_condition('version_constant_mismatch', $target, 'last_registered_step=' . $last_step);
            return false;
        }

        foreach ($steps as $version => $step) {
            $version = (int) $version;

            if ($version <= $installed || $version > $target) {
                continue;
            }

            if (!static::run_step($version, $step)) {
                return false;
            }

            $installed = $version;
        }

        return true;
    }

    /**
     * Run one step, serialising it first unless it is convergent.
     *
     * The concurrency declaration is mandatory and is checked here rather than
     * trusted. Nothing serialises the hook the ladder runs on, so a step
     * registered without a declaration would otherwise run unlocked on a guess,
     * which is exactly the mistake this is here to make impossible.
     *
     * @param int   $version Step number.
     * @param mixed $step    Step declaration.
     * @return bool True when the step ran and its version was recorded.
     */
    protected static function run_step($version, $step)
    {
        if (!is_array($step)
            || !isset($step['callback'])
            || !isset($step['concurrency'])
            || !is_callable($step['callback'])
        ) {
            _doing_it_wrong(
                __METHOD__,
                'Ladder step ' . (int) $version . ' must declare a callback and a concurrency mode.',
                '8.5.4'
            );
            static::record_condition('step_declaration_invalid', $version, '');
            return false;
        }

        if (self::STEP_CONVERGENT === $step['concurrency']) {
            /*
             * Wrapped for the same reason the exclusive branch is: the ladder
             * runs on init on every request, so a step whose exception escapes
             * takes the whole site down instead of failing the migration. There
             * is no lease to give back here, so there is no finally.
             *
             * The version is written last on purpose. A step that rewrites the
             * generated file depends on WP_Filesystem, and write_file() quietly
             * does nothing when WP_Filesystem is unavailable while
             * update_llms_file() fatals on the same condition. Recording the
             * version first would mark the migration done on an install where
             * it did nothing at all, and nothing would ever come back to it.
             */
            try {
                $ran = (true === call_user_func($step['callback']));
            } catch (\Throwable $e) {
                static::record_condition(
                    'step_threw',
                    $version,
                    get_class($e) . ': ' . $e->getMessage()
                );
                return false;
            }

            if (!$ran) {
                static::record_condition('step_failed', $version, '');
                return false;
            }

            static::set_installed_version($version);
            static::clear_condition();
            return true;
        }

        if (self::STEP_EXCLUSIVE !== $step['concurrency']) {
            _doing_it_wrong(
                __METHOD__,
                'Ladder step ' . (int) $version . ' declares an unknown concurrency mode.',
                '8.5.4'
            );
            // The declaration is whatever the step registered, so it can be an
            // array or an object, and casting one of those to string is a
            // warning of its own.
            $declared = is_scalar($step['concurrency'])
                ? (string) $step['concurrency']
                : gettype($step['concurrency']);

            static::record_condition('step_concurrency_unknown', $version, $declared);
            return false;
        }

        $lock_name = static::option_name() . '_step_' . (int) $version;
        $token     = LLMS_Lock::acquire($lock_name);

        if (false === $token) {
            // Another request holds it and is running this step now. Leave it to
            // them and pick the ladder up again on a later request.
            return false;
        }

        /*
         * The lock name and token are handed to the step so that a long one can
         * call LLMS_Lock::refresh() and keep its lease. Serialisation only holds
         * for STALE_AFTER, so a step that runs longer than that without
         * refreshing can have its lock stolen and be applied twice
         * concurrently. The file-rewriting step is the case most likely to
         * exceed it.
         *
         * try/finally so a step that throws still gives the lease back. Without
         * it the ladder is blocked for the rest of STALE_AFTER on a site where
         * the step is failing, which is exactly when retrying promptly matters.
         */
        $ran   = false;
        $threw = null;

        try {
            $ran = (true === call_user_func($step['callback'], $lock_name, $token));
        } catch (\Throwable $e) {
            /*
             * Swallowed on purpose. The ladder runs on init on every request, so
             * letting a step's exception escape would take the whole site down
             * rather than just failing the migration. The version is not
             * recorded, so the step is retried on the next request, and an
             * install where it never succeeds stays visibly on the old version.
             */
            $ran   = false;
            $threw = get_class($e) . ': ' . $e->getMessage();
        } finally {
            /*
             * Only record the version if the lease was still ours when the step
             * returned. A step that outran STALE_AFTER may have had its lock
             * stolen and run a second time alongside it, so neither its result
             * nor the state it left behind can be trusted.
             *
             * By identity, not by the token we started with. A step that took
             * the advice above and refreshed its lease holds a row whose value
             * has moved on, and comparing the original string would tell a step
             * that never lost the lock that it did: no version recorded, work
             * repeated on the next request for ever, and the row left behind
             * for the rest of STALE_AFTER because release() could not match it
             * either.
             *
             * release_identity() answers "still ours until now" from the delete
             * itself rather than from a read taken just before it, so there is
             * no window in which a steal can land between the check and the act,
             * and it never deletes a row that a thief has already replaced.
             */
            $still_held = LLMS_Lock::release_identity($lock_name, $token);
        }

        if (!$ran || !$still_held) {
            if (null !== $threw) {
                static::record_condition('step_threw', $version, $threw);
            } elseif (!$ran) {
                static::record_condition('step_failed', $version, '');
            } else {
                static::record_condition('step_lease_lost', $version, '');
            }

            return false;
        }

        static::set_installed_version($version);
        static::clear_condition();
        return true;
    }

    /**
     * Schema version this install is currently at.
     *
     * @return int Zero for any install that predates 8.5.4.
     */
    public static function installed_version()
    {
        return (int) get_option(static::option_name(), 0);
    }

    /**
     * Whether the table really carries what the recorded version claims.
     *
     * llms_db_version records that a step reported success, not that its result
     * is still there. Those two come apart: a hosting restore that carries
     * wp_options and an older copy of the table, a migration tool that rebuilds
     * tables from its own dump, an ALTER rolled back by a crash after the number
     * was written. The install then looks healthy on the settings screen and is
     * not, and the symptom is a slow site rather than an error.
     *
     * 'complete' is about the RECORDED version, not the target. An install
     * sitting correctly at 2 while this copy of the plugin wants 3 is mid-ladder,
     * not incomplete, and the two deserve different sentences on the screen.
     * 'missing' is written for a person: "cache table", "index llms_read".
     *
     * NOT for ordinary requests. It issues SHOW COLUMNS and SHOW INDEX, which is
     * two metadata queries the front end has no reason to pay for. Call it from
     * the settings screen. Everything the plugin does per request to keep itself
     * healthy is in maybe_upgrade(), which costs one SHOW TABLES.
     *
     * It also clears a repair condition that has been resolved. Those two
     * records survive an ordinary healthy request on purpose, because nothing
     * about such a request disproves them, so this is the only place that can
     * establish that the schema they describe is complete after all. See
     * condition_survives_healthy_request().
     *
     * @return array version, target, complete, missing.
     */
    public static function schema_status()
    {
        global $wpdb;

        $installed = static::installed_version();

        $out = array(
            'version'  => $installed,
            'target'   => static::target_version(),
            'complete' => true,
            'missing'  => array(),
        );

        if (!static::cache_table_exists()) {
            // Everything else would be a guess, and this is the state
            // ensure_baseline() exists for, so it is the one worth saying.
            $out['complete'] = false;
            $out['missing'][] = 'cache table';

            return $out;
        }

        $table = $wpdb->prefix . 'llms_txt_cache';

        $suppressed = $wpdb->suppress_errors(true);
        $columns    = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $indexes    = $wpdb->get_col("SHOW INDEX FROM `{$table}`", 2);
        $error      = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ('' !== $error) {
            // The table answered SHOW TABLES and not this, so something is wrong
            // with it that we cannot describe. Saying nothing would report the
            // install as healthy.
            $out['complete'] = false;
            $out['missing'][] = 'cache table (unreadable)';

            return $out;
        }

        $columns = array_map('strtolower', (array) $columns);
        $indexes = array_map('strtolower', (array) $indexes);

        $steps = static::steps();
        ksort($steps, SORT_NUMERIC);

        foreach ($steps as $version => $step) {
            if ((int) $version > $installed || !is_array($step) || empty($step['expects'])) {
                continue;
            }

            $expects = $step['expects'];

            if (!empty($expects['columns'])) {
                foreach ($expects['columns'] as $column) {
                    if (!in_array(strtolower($column), $columns, true)) {
                        $out['missing'][] = 'column ' . $column;
                    }
                }
            }

            if (!empty($expects['indexes'])) {
                foreach ($expects['indexes'] as $index) {
                    if (!in_array(strtolower($index), $indexes, true)) {
                        $out['missing'][] = 'index ' . $index;
                    }
                }
            }
        }

        $out['complete'] = empty($out['missing']);

        if ($out['complete']) {
            $autoloaded = wp_load_alloptions();
            $name       = static::condition_option_name();

            if (is_array($autoloaded) && isset($autoloaded[$name])) {
                $existing = maybe_unserialize($autoloaded[$name]);
                $code     = (is_array($existing) && isset($existing['code'])) ? (string) $existing['code'] : '';

                if (static::condition_survives_healthy_request($code)) {
                    static::clear_condition();
                }
            }
        }

        return $out;
    }

    /**
     * Schema version this copy of the plugin expects.
     *
     * @return int
     */
    protected static function target_version()
    {
        return (int) LLMS_DB_VERSION;
    }

    /**
     * Option holding the installed schema version.
     *
     * Site scoped, which is what makes multisite work without any extra code:
     * each site in the network reads and writes its own row, so each one climbs
     * the ladder on its own first request and against its own table prefix.
     *
     * @return string
     */
    protected static function option_name()
    {
        return 'llms_db_version';
    }

    /**
     * The ladder itself, oldest step first.
     *
     * Each entry is [ version number => [ 'callback' => callable, 'concurrency'
     * => STEP_CONVERGENT | STEP_EXCLUSIVE ] ]. Both keys are required;
     * run_step() refuses a step that omits either rather than guessing. A step
     * must not assume any earlier step ran in the same request, and must finish
     * its file side effects before it returns.
     *
     * A step must return exactly boolean true to be counted as done. Anything
     * else, including a truthy WP_Error, is treated as failure and retried, so
     * the ordinary WordPress habit of returning WP_Error on failure works here
     * without the step having to know about it. An exclusive step is handed
     * ( $lock_name, $token ) so that one running longer than
     * LLMS_Lock::STALE_AFTER can call LLMS_Lock::refresh() and keep its lease.
     * Refreshing is recognised: run_step() decides whether the lease is still
     * ours by identity, so a step that refreshes still records its version.
     *
     * A step that changes the cache table schema must be STEP_CONVERGENT, and
     * must put its change here rather than in create_cache_table(). That is what
     * lets the repair path put the change back on an install that loses the
     * table after running it. See replay_convergent_steps().
     *
     * An optional third key, 'schema' => false, says the step changes nothing
     * about the table, so the repair path has nothing to put back for it and
     * says nothing about it. Absence means "assume it does", which is the safe
     * reading: an unreplayable schema step is reported rather than passed over.
     * Only a step that is not STEP_CONVERGENT needs to declare it, since a
     * convergent step is replayed either way.
     *
     * An optional fourth key, 'expects' => [ 'columns' => [], 'indexes' => [] ],
     * names what the table carries once the step has run. schema_status() reads
     * it to answer whether an install is really at the version it claims, which
     * is a different question from whether the ladder recorded a number and is
     * the one that matters when an ALTER was rolled back or a table was restored
     * from a backup taken before it. A step that declares nothing is assumed to
     * leave nothing behind that can be checked, so a step author who omits it
     * makes the card silent rather than wrong.
     *
     * The highest number here must equal LLMS_DB_VERSION. See maybe_upgrade().
     *
     * @return array
     */
    protected static function steps()
    {
        return array(
            1 => array(
                'callback'    => array(__CLASS__, 'step_create_cache_table'),
                'concurrency' => self::STEP_CONVERGENT,
                'expects'     => array(
                    'columns' => static::baseline_columns(),
                    'indexes' => array('PRIMARY'),
                ),
            ),
            2 => array(
                'callback'    => array(__CLASS__, 'step_add_read_index'),
                'concurrency' => self::STEP_CONVERGENT,
                'expects'     => array(
                    'indexes' => array(self::READ_INDEX),
                ),
            ),
            3 => array(
                'callback'    => array(__CLASS__, 'step_rebuild_generated_file'),
                'concurrency' => self::STEP_EXCLUSIVE,
                // Nothing in it touches the schema, so the repair path has
                // nothing to put back and must not report the table as
                // incomplete for it. See replay_convergent_steps().
                'schema'      => false,
            ),
        );
    }

    /**
     * Record the version reached.
     *
     * Autoloaded on purpose. It is read on every request, so leaving it out of
     * the autoload set would add a query per request to every install.
     *
     * @param int $version Version number.
     * @return void
     */
    protected static function set_installed_version($version)
    {
        update_option(static::option_name(), (string) (int) $version, true);
    }

    /**
     * The baseline is there, whatever the ladder thinks it has already done.
     *
     * This is a repair path and not a ladder step. It runs on the request where
     * the ladder has nothing to climb, records no version and moves nothing, and
     * it never runs any step's body but the one that creates the baseline table.
     *
     * It exists because "the ladder is finished" and "the table is there" are
     * different facts, and only the first one is written down. 8.5.3 checked
     * SHOW TABLES on every request and recreated the table whenever it was
     * missing, so a table lost to a botched restore, a partial import, a
     * migration tool that carries wp_options but not the non-core tables, or a
     * cleanup plugin came back on the next page view. Keying creation to the
     * version alone dropped that: llms_db_version stays at the target, the
     * ladder early-returns, and generation is broken for good. Measured, with a
     * control: 8.5.3 healed a dropped table in one ordinary request, and the
     * ladder alone still had not healed it after four.
     *
     * Cost is one SHOW TABLES per request, which is exactly what 8.5.3 paid, so
     * this is not a performance regression against the shipped plugin. It is,
     * deliberately, one query per request rather than none: the ladder on its own
     * needed no query at all once the version was current, and that is what let a
     * dropped table go unnoticed for ever. Caching the answer in an option was
     * rejected for the same reason, only harder: the option cannot see the table
     * being dropped out from under it, and the window in which it does not is
     * precisely the case this path exists to catch. The check is a metadata
     * lookup, not a table read. Measured at 0.6 to 1.5 ms.
     *
     * A recreated table comes back as the baseline, so anything a later step
     * added to it is missing while llms_db_version still says that step ran. The
     * convergent steps up to the recorded version are therefore replayed here.
     * See replay_convergent_steps() for what that requires of a step author.
     *
     * @param bool $ladder_current Whether the ladder has nothing left to climb.
     *                             A condition record left over from earlier
     *                             trouble is only cleared when it has: a step
     *                             that is still failing is about to record one,
     *                             and clearing it first would move the recorded
     *                             start time forward on every request and write
     *                             to wp_options on every request with it.
     * @return bool True when the baseline is in place and carries the schema the
     *              recorded version claims.
     */
    protected static function ensure_baseline($ladder_current = true)
    {
        if (static::cache_table_exists()) {
            if ($ladder_current) {
                static::clear_condition_if_present();
            }

            return true;
        }

        if (!static::create_cache_table()) {
            static::record_condition('cache_table_missing', 1, 'recreate failed');
            return false;
        }

        $replay = static::replay_convergent_steps(static::installed_version());

        if (!empty($replay['failed'])) {
            $first  = (int) $replay['failed'][0];
            $detail = 'steps=' . implode(',', $replay['failed']);

            if (isset($replay['threw'][$first])) {
                // The exception text is the only part of this a human can act on,
                // so carry it the way run_step() carries a step's own.
                $detail .= ' ' . $replay['threw'][$first];
            }

            static::record_condition('baseline_replay_failed', $first, $detail);
            return false;
        }

        if (!empty($replay['skipped'])) {
            // The table is there and usable, so this is not a failure, but the
            // schema is only as complete as the convergent steps could make it.
            // Recorded rather than cleared so the admin surface can say so.
            static::record_condition(
                'baseline_replay_incomplete',
                (int) $replay['skipped'][0],
                'steps=' . implode(',', $replay['skipped'])
            );
            return true;
        }

        static::clear_condition();
        return true;
    }

    /**
     * Re-apply the schema a recreated table has lost.
     *
     * The repair recreates the baseline table. An install that had climbed past
     * step 1 was carrying whatever the later steps added to it, and llms_db_version
     * still says those steps ran, so without this the install is permanently
     * missing a column or an index with no path back to it. Recording no version
     * and letting the ladder replay was rejected: the ladder would also replay the
     * exclusive steps, and rewriting the generated file is one of those.
     *
     * Only STEP_CONVERGENT steps are replayed, because convergent is exactly the
     * declaration that says running the body again is the same as having run it
     * once. That is the whole rule a future step author has to follow:
     *
     *   A step that changes the cache table schema must be STEP_CONVERGENT.
     *
     * DDL qualifies anyway, so this costs a schema step nothing. The step must
     * also tolerate its own change already being present, which the ladder
     * already required of it: a request that ran the ALTER and died before the
     * version was recorded retries it on the next request. Spec 3.13's
     * KEY llms_read is the first step this applies to.
     *
     * What it means for create_cache_table(): its CREATE is the baseline and does
     * not have to be kept in step with later schema steps. That invariant was the
     * thing nobody could be relied on to maintain, and it is gone rather than
     * documented. A step whose change is not replayable from its own callback,
     * because it is exclusive, is reported as baseline_replay_incomplete rather
     * than passing silently.
     *
     * Runs on the repair path only, which is rare by construction, so replaying
     * step 1 (whose body is the create the caller has just done) costs one extra
     * SHOW TABLES there and nothing at all on a healthy install.
     *
     * A step that declares 'schema' => false is passed over silently rather than
     * reported as skipped. Step 3 deletes the generated file and asks for a
     * rebuild; it is exclusive, so it is not replayable, and it has nothing to
     * do with the table. Without the declaration every repair on an install that
     * has run it would record baseline_replay_incomplete, which tells a support
     * reader the recreated table is missing part of its schema when it is not.
     *
     * @param int $through Highest step number the install claims to have run.
     * @return array replayed, skipped, ignored and failed step numbers, plus
     *               threw keyed by step number for anything that raised a
     *               Throwable.
     */
    protected static function replay_convergent_steps($through)
    {
        $result = array(
            'replayed' => array(),
            'skipped'  => array(),
            'ignored'  => array(),
            'failed'   => array(),
            'threw'    => array(),
        );

        $through = (int) $through;
        if ($through < 1) {
            // A fresh install has nothing behind it. This is the ordinary case
            // on the one request where the table is first created, and it is why
            // the repair costs a new install nothing.
            return $result;
        }

        $steps = static::steps();
        ksort($steps, SORT_NUMERIC);

        foreach ($steps as $version => $step) {
            $version = (int) $version;

            if ($version > $through) {
                break;
            }

            if (!is_array($step)
                || !isset($step['callback'])
                || !isset($step['concurrency'])
                || !is_callable($step['callback'])
                || self::STEP_CONVERGENT !== $step['concurrency']
            ) {
                if (is_array($step) && isset($step['schema']) && false === $step['schema']) {
                    // Declared as carrying no schema, so there is nothing here
                    // for the repair to put back and nothing worth reporting.
                    $result['ignored'][] = $version;
                    continue;
                }

                $result['skipped'][] = $version;
                continue;
            }

            /*
             * No lock and no version write. A convergent step needs neither, and
             * writing the version here would let a replay that is really a
             * partial repair look like a completed ladder. Wrapped for the same
             * reason run_step() wraps a convergent step: this runs on init on
             * every request, so an escaping Throwable would take the site down
             * instead of failing the repair.
             */
            try {
                $ran = (true === call_user_func($step['callback']));
            } catch (\Throwable $e) {
                $result['failed'][]          = $version;
                $result['threw'][$version]   = get_class($e) . ': ' . $e->getMessage();
                continue;
            }

            if ($ran) {
                $result['replayed'][] = $version;
            } else {
                $result['failed'][] = $version;
            }
        }

        return $result;
    }

    /**
     * Whether the cache table is there right now.
     *
     * SHOW TABLES rather than a read of the table, so a missing table is an
     * empty result instead of a database error the caller has to interpret.
     *
     * @return bool
     */
    protected static function cache_table_exists()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'llms_txt_cache';

        return ($table === $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table
        )));
    }

    /**
     * Step 1: the cache table exists.
     *
     * Baseline for the ladder. On the installs that already run 8.5.3 the table
     * is already there and this does nothing but record that they are on step 1.
     * On a fresh install it creates it. An install that loses the table after the
     * ladder has finished is repaired by ensure_baseline() instead, which calls
     * the same creation code without recording anything.
     *
     * @return bool
     */
    protected static function step_create_cache_table()
    {
        return static::create_cache_table();
    }

    /**
     * Create the cache table if it is not there, and report on what is there
     * afterwards.
     *
     * The CREATE below is the baseline, the schema as of step 1, and a later
     * schema step must NOT be copied into it. The repair path recreates the
     * baseline and then replays the convergent steps the install has already
     * run, so a change that lived in both places would be applied twice: an
     * ALTER TABLE ADD KEY replayed against a table that already carries the key
     * is an error, not a no-op. One home per change, and that home is the step.
     * See replay_convergent_steps().
     *
     * @return bool
     */
    protected static function create_cache_table()
    {
        global $wpdb;

        if (static::cache_table_exists()) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(static::cache_table_create_sql($wpdb->prefix . 'llms_txt_cache'));

        // Report on what actually happened rather than on dbDelta's return
        // value, which lists the statements it decided to run and says nothing
        // about whether they worked.
        return static::cache_table_exists();
    }

    /**
     * The baseline CREATE, in one place.
     *
     * One home, because baseline_columns() reads the column list back out of
     * this string rather than carrying a second copy of it. Two lists of columns
     * is how the settings screen ends up reporting on a column nothing creates,
     * or missing one that is genuinely gone.
     *
     * @param string $table Table name, already prefixed.
     * @return string
     */
    protected static function cache_table_create_sql($table)
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE $table (
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
    }

    /**
     * The columns the baseline table carries, read back out of the CREATE.
     *
     * Parsed rather than listed, so that there is exactly one place a column is
     * named. The pattern matches a backtick quoted identifier at the start of a
     * line, which in that string is a column definition and nothing else: the
     * table name is not quoted and the charset clause carries no backticks.
     *
     * @return string[] Lower case column names.
     */
    protected static function baseline_columns()
    {
        $sql     = static::cache_table_create_sql('llms_txt_cache');
        $matches = array();

        preg_match_all('/^[ \t]*`([A-Za-z0-9_]+)`/m', $sql, $matches);

        return isset($matches[1]) ? array_map('strtolower', $matches[1]) : array();
    }

    /**
     * Step 2: the read index.
     *
     * The table has carried one key since it was introduced, the primary key on
     * post_id, and neither read query looks anything up by post_id. Both of them
     * select on type, status and show and order by published, so every read is a
     * full scan of the table plus a filesort, and at a deep OFFSET the sort is
     * over the whole table each time. KEY llms_read (type, status, show,
     * published) is that access path in the order the queries ask for it: the
     * three equality columns first, the ordering column last, so the index
     * satisfies the WHERE and delivers the rows already sorted.
     *
     * Convergent, so the repair path replays it. That is what puts the index
     * back on an install whose table is dropped after it has reached version 2:
     * ensure_baseline() recreates the baseline table, which by design does not
     * carry this index, and then replays this step. The index therefore has
     * exactly one home, this method, and create_cache_table() is deliberately
     * left alone. See replay_convergent_steps().
     *
     * Written as check, act, check rather than as a bare ALTER. MySQL has no
     * ADD KEY IF NOT EXISTS, and the step has to survive both of the ways it can
     * meet an index that is already there: a request that ran the ALTER and died
     * before the version was recorded, and a replay on the repair path. Two
     * concurrent requests can still both pass the first check and both issue the
     * ALTER, which is why the errors are suppressed and the answer comes from the
     * second check: the loser of that race sees Duplicate key name, and the index
     * is there, which is what the caller asked about.
     *
     * @return bool
     */
    protected static function step_add_read_index()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'llms_txt_cache';
        $index = self::READ_INDEX;

        if (static::index_exists($table, $index)) {
            return true;
        }

        $suppressed = $wpdb->suppress_errors(true);
        // Identifiers, not values: $wpdb->prefix is ours and the column list is
        // a literal, so there is nothing here prepare() could bind. `show` is a
        // reserved word, which is why every column is quoted.
        $wpdb->query(
            "ALTER TABLE `{$table}` ADD KEY `{$index}` (`type`, `status`, `show`, `published`)"
        );
        $wpdb->suppress_errors($suppressed);

        return static::index_exists($table, $index);
    }

    /**
     * Whether a named index is on the table right now.
     *
     * SHOW INDEX rather than information_schema: it is the same kind of metadata
     * lookup as the SHOW TABLES the baseline check already does, it needs no
     * privileges beyond the ones the site already has on its own table, and on a
     * shared host information_schema queries are the slow ones.
     *
     * Errors are suppressed and read as "no", so a missing table answers false
     * instead of putting a database error on the screen. The caller's ALTER then
     * fails too and the step is retried, which is the same outcome as any other
     * failed step.
     *
     * @param string $table Table name, already prefixed.
     * @param string $index Key name.
     * @return bool
     */
    protected static function index_exists($table, $index)
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        $found = $wpdb->get_var($wpdb->prepare(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
            $index
        ));
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ('' !== $error) {
            return false;
        }

        return (null !== $found);
    }

    /**
     * Step 3: the 8.5.4 migration.
     *
     * Everything 8.5.3 wrote is suspect, so the file it wrote goes, whatever is
     * in it. Three artifacts: the root llms.txt, the uploads copy and the uploads
     * temp copy. The temp copy goes too, because it is 8.5.3 content sitting in
     * the uploads directory and there is no reason to keep it. It is NOT one of
     * the served artifacts: 8.5.4 removed get_llms_content()'s fallback to it, so
     * nothing reads it and the artifact fingerprint does not count it. The
     * difference is not academic. Counting a run's own scratch file as evidence
     * that a document exists is what made the post-update rebuild's shutdown
     * backstop decline to re-request the rebuild it exists for (adversarial F5).
     * See llms_generated_file_paths() and llms_served_file_paths().
     *
     * Unconditional. The at-risk probe earlier plans described is deliberately
     * not here: it could not see WooCommerce Memberships, because that plugin
     * loads at plugins_loaded:15 and the probe ran at :10; its post_password scan
     * was a full table scan, since wp_posts has no index on that column; and it
     * was scoped to installs running a gating plugin when the password and draft
     * leak affects every install. The cheapest correct answer is to delete the
     * file on every install and rebuild it.
     *
     * Exclusive, because deleting the file and asking for it to be rebuilt is not
     * something two requests should do at once. Short enough that it never needs
     * to refresh its lease.
     *
     * Ordering inside the step is deliberate. The DELETE runs first, because it
     * is the only part that can fail on a database we do not control, and a
     * failure there returns false with nothing yet destroyed and the step retried
     * on the next request. Then the file goes. Then the rebuild is asked for,
     * both ways, because either one alone is not enough: WP-Cron does not fire at
     * all on plenty of hosts, and admin_init does not fire for a site nobody logs
     * into.
     *
     * The rebuild is only asked for when there was actually a file to delete. A
     * site that has never generated one has nothing to put back, and asking
     * anyway would make every new install run a full first crawl inside whatever
     * admin page load happened to come first, which on a large site is minutes.
     *
     * The work itself is no longer here. It lives in maybe_clean_artifacts(),
     * which runs on every request and is keyed on what is on disk rather than on
     * llms_db_version, because a schema number cannot see a rollback to 8.5.3
     * and forward again. That method has almost always already done this by the
     * time the step is reached, and the step then finds the stamp current and
     * returns true without touching anything. The step is kept rather than
     * withdrawn so that llms_db_version still means what it meant when 8.5.4
     * shipped, and so an install that reaches this point with the cleanup owed
     * does it under the ladder's lease.
     *
     * @param string $lock_name Lease held by the ladder. Unused: this is short.
     * @param string $token     Lease token. Unused, same reason.
     * @return bool
     */
    protected static function step_rebuild_generated_file($lock_name = '', $token = '')
    {
        if (static::maybe_clean_artifacts()) {
            return true;
        }

        /*
         * A served path this process cannot remove is NOT a ladder failure, and
         * reporting it as one does three bad things at once, all measured:
         *
         *  - run_step() records a step_failed condition with an empty detail,
         *    which is a false statement about the schema: nothing about the
         *    schema failed;
         *  - llms_db_version stays pinned below the target, so a schema state
         *    that is genuinely complete reports as incomplete;
         *  - this step is STEP_EXCLUSIVE, so the ladder takes a lease and runs
         *    on EVERY request for ever. Measured at 40 requests: 40 lease
         *    acquisitions and 120 queries, against 1 and 3 before this change.
         *    That is precisely the unbounded per-request cost the rate limit in
         *    maybe_clean_artifacts() exists to prevent, arriving through a door
         *    the rate limit does not cover.
         *
         * The step's own work is done: everything that could be deleted was, the
         * survivor is recorded, and the retry lives on the per-request cleanup
         * call in run(), where artifact_blocked_recently() bounds it. So report
         * the step complete and let that path own the healing.
         *
         * Only the artifact-blocked case is forgiven. A deferral for a
         * generation in flight, or a lease we could not take, still returns
         * false so the ladder tries again on the next request, which is the
         * behaviour those two have always had.
         *
         * The cycle limit is forgiven for the same reasons, all three of them,
         * and it has to be. It is the second way maybe_clean_artifacts() can
         * refuse without anything having failed, and a stand-down reported as a
         * ladder failure would pin llms_db_version below the target, record a
         * step_failed condition that says nothing true about the schema, and,
         * because this step is STEP_EXCLUSIVE, take a lease on every request for
         * ever. That last one is the exact unbounded per-request cost the limiter
         * was added to stop, so leaving this line unchanged would have had the
         * fix reintroduce the fault it fixes, one door along. Found by reading
         * this method after adding the gate, not by measuring it.
         */
        return (static::artifact_blocked_recently() || static::artifact_cycle_limit_reached());
    }

    /**
     * Drop the cache rows for posts an anonymous visitor cannot read at all.
     *
     * The rows 8.5.3 left behind for drafts, pending, private, trashed and
     * auto-draft posts, and for password protected ones, carry the full content
     * and overview of content that was never public. Both are decided by
     * wp_posts alone, which is what makes them safe to remove in one statement:
     * no plugin is consulted and no judgement is made.
     *
     * Membership restricted rows are deliberately left alone. Deleting them
     * refills them with full content on the next generation, and blanking them
     * makes the post permanently absent if the restriction is later lifted, so it
     * undoes itself either way. 8.6.0 truncates the table and settles it.
     *
     * One statement, driven from the cache table into wp_posts on the primary
     * key, so the cost is one primary key lookup per cached row rather than a
     * scan of wp_posts, which has no index on post_status or post_password.
     * Multi-table DELETE is MySQL and MariaDB syntax; the IN form underneath it
     * is there for anything else claiming to be wpdb, and it is only reached when
     * the first statement reports an error.
     *
     * @return bool True when the rows are gone.
     */
    protected static function purge_unreadable_cache_rows()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'llms_txt_cache';

        $suppressed = $wpdb->suppress_errors(true);
        $wpdb->query(
            "DELETE c FROM `{$table}` c
             INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id
             WHERE p.post_status <> 'publish' OR p.post_password <> ''"
        );
        $joined_error = $wpdb->last_error;

        if ('' === $joined_error) {
            $wpdb->suppress_errors($suppressed);
            return true;
        }

        $wpdb->query(
            "DELETE FROM `{$table}`
             WHERE post_id IN (
                 SELECT ID FROM {$wpdb->posts}
                 WHERE post_status <> 'publish' OR post_password <> ''
             )"
        );
        $fallback_error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        return ('' === $fallback_error);
    }

    /**
     * Option that records a rebuild is owed.
     *
     * @return string
     */
    protected static function rebuild_option_name()
    {
        return 'llms_rebuild_pending';
    }

    /**
     * Ask for the generated file to be rebuilt, by both routes.
     *
     * wp_schedule_single_event is the route the plugin already uses everywhere
     * else, and on a healthy site it is the one that runs. It is not enough on
     * its own: WP-Cron is disabled or broken on a large minority of installs, and
     * on those the file would stay deleted for ever, which is safe but is not
     * what the release notes promise. The flag is the second route, read and
     * acted on by the one-shot on admin_init in the main plugin file.
     *
     * Autoloaded so that the one-shot can answer "is a rebuild owed" out of the
     * alloptions array every request already loads, rather than adding a query to
     * every admin request for ever. The absent option is not in that array and
     * costs nothing at all.
     *
     * THE FLAG IS A STATE, NOT A TOKEN. Nothing consumes it but a promoted
     * document (note_document_promoted()). It was consumed up front by the
     * one-shot up to 8.5.4-rc, which is the whole of adversarial F1 and half of
     * F5: a generation has three normal returns that produce nothing, and a hard
     * stop has more, and every one of them ended with the flag spent and the site
     * with no document and no route back. A state that only a result can clear
     * cannot be lost by a crash.
     *
     * The value carries the attempt record, "<attempts>:<unix time>", so that the
     * automatic routes can slow down on an install where generation always fails
     * without needing a second option. Every reader outside this class only ever
     * asks whether the option is set. See claim_rebuild_attempt().
     *
     * Public because both the generator's failure path and the one-shot have to
     * be able to say a rebuild is owed.
     *
     * ASKING FOR A REBUILD IS AN ATTEMPT, AND IT NOW COUNTS AS ONE. Up to 8.5.5
     * this method read the attempt record and wrote back exactly what it had just
     * read:
     *
     *     $attempts = static::rebuild_attempts();
     *     update_option($name, $attempts . ':' . static::rebuild_last_attempt(), true);
     *
     * On a site that owed nothing yet, both of those read nothing and the record
     * was written as "0:0". rebuild_attempts() then returned 0 for ever, because
     * nothing incremented it and because its own ageing-out is guarded on
     * $parts[1] > 0, which "0:0" fails. rebuild_retry_delay() therefore returned
     * its 30 second default on every call and claim_rebuild_attempt() never
     * refused anything. The 8.5.5 notes say repeated failures back off. They did
     * not: the only writers that incremented were claim_rebuild_attempt(), whose
     * one caller is the admin one-shot, and note_document_absent(), and the cron
     * route reaches neither on the paths that return without generating.
     * Confirmed on a production install: llms_rebuild_pending held "0:0" while
     * the site rebuilt every 30 seconds.
     *
     * The delay is now derived rather than passed, for the same reason. A caller
     * that has to name the back-off itself is a caller that can forget to, and
     * clean_artifacts() did forget: it called this with no argument, took the 30
     * second default and never consulted rebuild_retry_delay() at all.
     *
     * @param int|null $delay Seconds until the WP-Cron retry. Null, which is what
     *                        every caller in the plugin passes, means "whatever
     *                        the attempt record says it should be now".
     * @return void
     */
    public static function request_rebuild($delay = null)
    {
        // Before the delay is read, so that the delay reflects the attempt being
        // made rather than the one before it.
        static::note_rebuild_attempt();

        if (null === $delay) {
            $delay = static::rebuild_retry_delay();
        }

        static::schedule_rebuild($delay);
    }

    /**
     * Book the scheduled route, without moving one that would already arrive in
     * time.
     *
     * wp_clear_scheduled_hook() followed by wp_schedule_single_event() was
     * unconditional here, which is a rebuild that can be starved indefinitely on
     * a site with ordinary traffic. Every request that finds the state stale
     * pushed the event out by another delay, so on a site taking a request more
     * often than the delay, the event's due time moved away faster than the clock
     * moved towards it and WP-Cron never ran it. The site then owed a document it
     * had no route to, on the one mechanism, WP-Cron, that a site with no
     * administrator logging in depends on entirely.
     *
     * An existing booking is left alone unless this call wants the rebuild
     * SOONER. That keeps the due time monotonically decreasing while an
     * obligation stands, so it always arrives, and it costs nothing: the cron
     * array is autoloaded, so the lookup is an array read, and the writes it
     * avoids are two option writes per request on exactly the sites that could
     * least afford them.
     *
     * A booking already in the past is left alone by the same test, which is the
     * important half: that is an event WP-Cron is late running, and re-booking it
     * is precisely how it got starved.
     *
     * ensure_rebuild_scheduled() makes the same decision from the other side, for
     * a route that has been lost rather than one being asked for.
     *
     * @param int $delay Seconds from now.
     * @return void
     */
    protected static function schedule_rebuild($delay)
    {
        $when   = time() + max(1, (int) $delay);
        $booked = wp_next_scheduled('llms_update_llms_file_cron');

        if (false !== $booked && (int) $booked <= $when) {
            return;
        }

        if (false !== $booked) {
            wp_clear_scheduled_hook('llms_update_llms_file_cron');
        }

        wp_schedule_single_event($when, 'llms_update_llms_file_cron');
    }

    /**
     * Whether a rebuild is still owed.
     *
     * Reads the autoload set rather than the option, for the reason in
     * request_rebuild(). Same technique as clear_condition_if_present().
     *
     * This was briefly changed to get_option() during 8.5.6, on the theory that
     * the alloptions array not carrying the option was the cause of the runaway
     * regeneration. It was not, it cost one query per request on every healthy
     * install, and the change was withdrawn. A snapshot read is safe HERE
     * because being wrong about it is not destructive: too low an answer means a
     * rebuild is not re-armed as promptly as it could be, and the document on
     * disk is untouched either way. The read that IS destructive when it is
     * wrong is the artifact stamp, and that one is confirmed against the
     * database before anything acts on it. See lifecycle_state().
     *
     * @return bool
     */
    public static function rebuild_pending()
    {
        $autoloaded = wp_load_alloptions();

        return (is_array($autoloaded) && isset($autoloaded[static::rebuild_option_name()]));
    }

    /**
     * How many automatic attempts this owed rebuild has already had recently.
     *
     * The count ages out after REBUILD_RETRY_AFTER, which is what keeps the back
     * off a property of a burst of failures rather than a permanent mark on the
     * install. Without it a site that failed twice in March would still be
     * serving out its cooldown when an unrelated rollback in June asks for a
     * rebuild, and the release's promise is that an update rebuilds the file.
     *
     * @return int
     */
    protected static function rebuild_attempts()
    {
        $parts = static::rebuild_record();

        if (null === $parts) {
            return 0;
        }

        if ($parts[1] > 0 && (time() - $parts[1]) >= static::REBUILD_RETRY_AFTER) {
            return 0;
        }

        return $parts[0];
    }

    /**
     * When the last automatic attempt was made, 0 when there has been none.
     *
     * @return int
     */
    protected static function rebuild_last_attempt()
    {
        $parts = static::rebuild_record();

        return (null === $parts) ? 0 : $parts[1];
    }

    /**
     * The stored flag, parsed.
     *
     * "1" is what every build up to 8.5.4-rc wrote and is read as "owed, never
     * attempted", so an install that updates mid-cycle is not treated as having
     * already used its attempts.
     *
     * @return int[]|null [attempts, last attempt] or null when nothing is owed.
     */
    protected static function rebuild_record()
    {
        $autoloaded = wp_load_alloptions();
        $name       = static::rebuild_option_name();

        if (!is_array($autoloaded) || !isset($autoloaded[$name])) {
            return null;
        }

        $parts = explode(':', (string) $autoloaded[$name], 2);

        if (2 !== count($parts)) {
            return array(0, 0);
        }

        return array((int) $parts[0], (int) $parts[1]);
    }

    /**
     * May this request make an automatic attempt at the owed rebuild, and record
     * that it did.
     *
     * Replaces claim_rebuild(), which deleted the flag before the work. This
     * neither deletes nor rewrites what is owed: it only advances the attempt
     * record, so a crash inside the attempt leaves the site owing exactly what it
     * owed before, which is the property adversarial F1 and F5 both needed.
     *
     * Two overlapping admin requests can both come out of this true. That is not
     * a race worth a lock: the generation lease behind them lets exactly one do
     * the work and the other returns in microseconds, and paying for a lock on
     * every admin page load of every install to save that would be the wrong
     * trade.
     *
     * @return bool
     */
    public static function claim_rebuild_attempt()
    {
        if (!static::rebuild_pending()) {
            return false;
        }

        $attempts = static::rebuild_attempts();

        if ($attempts >= static::REBUILD_FREE_ATTEMPTS
            && (time() - static::rebuild_last_attempt()) < static::REBUILD_RETRY_AFTER
        ) {
            return false;
        }

        update_option(
            static::rebuild_option_name(),
            ($attempts + 1) . ':' . time(),
            true
        );

        static::$attempt_recorded_this_request = true;

        return true;
    }

    /**
     * How long the automatic routes should wait before the next attempt.
     *
     * @return int Seconds.
     */
    protected static function rebuild_retry_delay()
    {
        return (static::rebuild_attempts() >= static::REBUILD_FREE_ATTEMPTS)
            ? static::REBUILD_RETRY_AFTER
            : 30;
    }

    /**
     * Make sure the scheduled route to an owed rebuild exists, without moving one
     * that already does.
     *
     * Called on every request that finds a rebuild owed, which sounds expensive
     * and is not: the cron array is one of WordPress's autoloaded options, so
     * this is an array lookup, and it is only reached at all while the site is
     * owed a document.
     *
     * It exists because the scheduled event is lost in more ways than the code
     * that schedules it can see. Firing it consumes it, and a run that then does
     * nothing (no WP_Filesystem transport, or a lease orphaned by a killed run,
     * which refuses every generation for up to 300 seconds) has spent the route
     * without doing the work. Deactivating the plugin clears it outright. On a
     * site whose only route is WP-Cron, any one of those is the difference
     * between a document coming back and one that never does.
     *
     * @return void
     */
    public static function ensure_rebuild_scheduled()
    {
        if (!static::rebuild_pending()) {
            return;
        }

        if (wp_next_scheduled('llms_update_llms_file_cron')) {
            return;
        }

        wp_schedule_single_event(time() + static::rebuild_retry_delay(), 'llms_update_llms_file_cron');
    }

    /**
     * A run promoted a document. Record it, and nothing is owed any more.
     *
     * Called from update_llms_file() the moment both destinations are settled and
     * before anything third party runs, so that there is no window in which a
     * good document is on disk and the stamp still describes the one before it.
     * That window used to be the whole of wp_update_post() and every save_post
     * callback on the site, and a hard stop inside it made the NEXT request
     * delete the document that had just been generated.
     *
     * @return void
     */
    public static function note_document_promoted()
    {
        static::stamp_artifacts();

        if (static::rebuild_pending()) {
            delete_option(static::rebuild_option_name());
        }

        // The obligation is discharged, so the retry booked against it should go
        // with it. Until 8.5.5 only the admin one-shot cleared the scheduled
        // event, and only when it had run the generation itself, so a rebuild
        // that succeeded from cron, from a post save or from "Generate now" left
        // the next llms_update_llms_file_cron standing and the site ran one
        // extra full generation for nothing. Same omission as A2, smaller.
        wp_clear_scheduled_hook('llms_update_llms_file_cron');

        static::forget_artifact_block_if_clear();
    }

    /**
     * Retire the undeletable-artifact record once the path really has gone.
     *
     * There are two ways a blocked site heals and only one of them used to be
     * covered. clear_artifact_blocked() has a single caller, inside
     * clean_artifacts(), which is only reachable while lifecycle_state() is
     * stale. The rebuild is scheduled 30 seconds out and the block window is
     * 300, so the ordinary sequence is: the host makes the file removable, the
     * owed rebuild fires first, a document is promoted, the state becomes
     * current, and clean_artifacts() is never reached again. That orphaned the
     * record with nothing left able to clear it, and the card went on telling
     * the owner that an older file was being served when it was not. Measured:
     * still showing 4,000 seconds past the window, on a site serving its own
     * freshly generated document.
     *
     * The other ordering, where the window expires while the state is still
     * stale, does clear, which is exactly why this was easy to miss.
     *
     * Checked against the filesystem rather than assumed, because a promote does
     * NOT prove the survivor is gone. promote_generated_file() returns true when
     * the uploads copy promoted even if the root copy could be neither replaced
     * nor removed, which is a deliberate trade for the many sites that cannot
     * write to ABSPATH at all. On a site where the root file is genuinely
     * immutable the warning is still true after a successful generation, and it
     * must stay on the screen. So: clear only when every recorded path has
     * actually gone.
     *
     * Runs after a generation, not on ordinary requests, so it costs one option
     * read per promote rather than a query per request on every healthy install.
     *
     * @return void
     */
    protected static function forget_artifact_block_if_clear()
    {
        $block = static::artifact_block();

        if (null === $block) {
            return;
        }

        if (!function_exists('llms_served_file_paths')) {
            return;
        }

        /*
         * The question is NOT "has the path gone". A first attempt asked that
         * and could essentially never fire on a single site: the recorded path
         * is usually ABSPATH/llms.txt, and by the time this runs the promote has
         * put OUR OWN new document back at exactly that path, so it exists and
         * the record was kept for ever.
         *
         * The question is "is this still a foreign document". Two ways it is
         * not: the path is gone, or what is there now is what this run just
         * wrote.
         *
         * The uploads copy is the reference for the second test. The promote
         * moves the temp file onto it before it does anything else and returns
         * false if that fails, so reaching here means it holds this run's
         * document. Identified by its directory rather than by position in the
         * list, because relying on the order of llms_served_file_paths() is the
         * kind of assumption that breaks quietly.
         */
        $uploads = wp_get_upload_dir();
        $basedir = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) : '';
        $fresh   = null;

        if ('' !== $basedir) {
            foreach (llms_served_file_paths() as $served) {
                if (0 === strpos($served, $basedir) && file_exists($served)) {
                    $fresh = @md5_file($served);
                    break;
                }
            }
        }

        foreach ($block['paths'] as $path) {
            clearstatcache(true, $path);

            if (!file_exists($path)) {
                continue;
            }

            if (null !== $fresh && false !== $fresh && @md5_file($path) === $fresh) {
                // This run wrote it. Not a survivor any more.
                continue;
            }

            // Still a document we did not write, at a path we could not clear.
            // The card is telling the truth; leave it alone. An unreadable
            // reference copy lands here too, which is the conservative side.
            return;
        }

        static::clear_artifact_blocked();
    }

    /**
     * A run ended without a document and has removed the destinations.
     *
     * Only ever called after the removal, so the stamp it writes is the truth
     * about the disk rather than a blessing of whatever happens to be there.
     *
     * The rebuild is asked for on every such run, not only when one was already
     * owed: a generation that destroyed the previous document has taken the
     * site's llms.txt away and owes it back, whether the run came from cron, from
     * a post save or from the post-update one-shot.
     *
     * @return void
     */
    public static function note_document_absent()
    {
        static::stamp_artifacts();

        // The attempt is counted, and the delay derived from the count, inside
        // request_rebuild(). 8.5.5 did both of those here instead, which left
        // every OTHER caller of request_rebuild() outside the back-off: it took
        // the 30 second default and wrote the attempt record back unchanged. Both
        // now live in the one method that every route goes through, so there is
        // no longer a caller that can be written without them.
        static::request_rebuild();
    }

    /**
     * Advance the automatic-retry counter, once per request.
     *
     * The admin one-shot advances it in claim_rebuild_attempt(), before the run,
     * because a run that dies in a way no handler sees (a SIGKILL, an OOM) must
     * still count against the budget or that route could retry for ever. This
     * method is for the routes that have no claim step, and since 8.5.6 it is
     * called by request_rebuild(), which is every one of them. The request-scoped
     * guard keeps the admin route at one attempt per run rather than two, which
     * is what REBUILD_FREE_ATTEMPTS is documented to mean and what the acceptance
     * bar was measured against, and it is also what keeps clean_artifacts() at
     * one attempt when both of its request_rebuild() calls fire in one pass.
     *
     * The guard cannot leave the obligation unrecorded. It is only ever set by
     * this method or by claim_rebuild_attempt(), and both of those write the flag
     * before setting it, so a request that reaches here with the guard already
     * true is a request in which the flag has already been written.
     *
     * @return void
     */
    protected static function note_rebuild_attempt()
    {
        if (static::$attempt_recorded_this_request) {
            return;
        }

        update_option(
            static::rebuild_option_name(),
            (static::rebuild_attempts() + 1) . ':' . time(),
            true
        );

        static::$attempt_recorded_this_request = true;
    }

    /**
     * Option recording which plugin version left which file on disk.
     *
     * @return string
     */
    protected static function artifact_option_name()
    {
        return 'llms_artifact_stamp';
    }

    /**
     * The running plugin version, or 0 where the constant is not defined.
     *
     * @return string
     */
    protected static function plugin_version()
    {
        // WEBSITE_LLMS_TXT_VERSION, never LLMS_VERSION. On a site that also runs
        // LifterLMS the old name is LifterLMS's version, and recording 10.0.10
        // against a file this plugin generated would make the floor comparison
        // above meaningless for exactly the sites that need it.
        return defined('WEBSITE_LLMS_TXT_VERSION') ? (string) WEBSITE_LLMS_TXT_VERSION : '0';
    }

    /**
     * Record that this version of the plugin left what is on disk now.
     *
     * Called after every completed generation and at the end of the cleanup
     * below, so the record is always a statement about the current state of the
     * files rather than about a moment in the past.
     *
     * @return void
     */
    public static function stamp_artifacts()
    {
        if (!function_exists('llms_generated_artifact_fingerprint')) {
            return;
        }

        update_option(
            static::artifact_option_name(),
            static::plugin_version() . ' ' . llms_generated_artifact_fingerprint(),
            true
        );
    }

    /**
     * The stamp as stored, or null when there is none.
     *
     * Out of the autoload set rather than with get_option(), because this is
     * consulted on every request and an install with no stamp must not pay a
     * query for it. The value is a plain string precisely so that it can be
     * compared straight out of that array with no unserialize.
     *
     * THIS IS A SNAPSHOT, NOT A LIVE READ, AND THAT IS THE WHOLE OF THE 8.5.5
     * BUG. WordPress fills the alloptions array once, early in the bootstrap,
     * and every later call in the request answers out of that photograph. The
     * fingerprint it gets compared against in lifecycle_state() is deliberately
     * live: llms_generated_artifact_fingerprint() clears the stat cache per path
     * and asks the filesystem now. So a request whose bootstrap happened before
     * another process finished a generation compares an OLD stamp against a NEW
     * file, concludes the file is foreign, and deletes it.
     *
     * Measured, on 8.5.5, with no second plugin and no object cache: stored
     * eab30af7..., computed 5ba1937c..., and 5ba1937c... was already in the
     * database, written by the generation that had just finished. The two values
     * that "disagreed" were the same value. Front-end traffic only, one trigger,
     * six minutes: 12 full regenerations and 116 deletions of the plugin's own
     * document. Each rebuild writes a fresh stamp, which re-arms the same race,
     * so it never exits.
     *
     * The read is left exactly as it was, because it is correct for what it is
     * and it is free. What changed is that nothing DESTRUCTIVE is allowed to act
     * on it any more. lifecycle_state() confirms against the database before it
     * will answer `stale`. See fresh_artifact_stamp().
     *
     * @return string|null
     */
    protected static function stored_artifact_stamp()
    {
        $autoloaded = wp_load_alloptions();
        $name       = static::artifact_option_name();

        if (!is_array($autoloaded) || !isset($autoloaded[$name])) {
            return null;
        }

        return (string) $autoloaded[$name];
    }

    /**
     * The stamp as it is in the database right now, bypassing every cache.
     *
     * The confirming read, and it exists because none of the ordinary ways of
     * asking are actually live:
     *
     *   - wp_load_alloptions() is the request's bootstrap snapshot.
     *   - get_option() consults that same snapshot FIRST, so it returns the same
     *     stale value whenever the option is autoloaded, which this one is. It
     *     would close the case where the option is MISSING from the array, which
     *     is real and was measured, but not the case where the array simply has
     *     not caught up, which is the one driving the loop.
     *   - wp_load_alloptions(true) does not help either. Its $force_cache
     *     argument is passed to wp_cache_get(), and WordPress's default,
     *     non-persistent object cache documents that argument as unused. On the
     *     majority of installs, which have no persistent object cache, it
     *     returns the same array.
     *
     * So it is $wpdb, directly. One primary key lookup on wp_options, and the
     * table has a UNIQUE index on option_name.
     *
     * WHERE THE COST LANDS IS THE ENTIRE POINT. This is not called on the healthy
     * path. A site whose document is its own answers `current` from the snapshot
     * and two stats, exactly as before, and never reaches here. It is called only
     * where the plugin is about to delete every generated file and crawl every
     * post on the site, and against that a single indexed lookup does not
     * register.
     *
     * THREE ANSWERS, NOT TWO, AND THE THIRD ONE MATTERS. "I asked and there is no
     * stamp" and "I could not ask" are different facts, and collapsing them into
     * null made a failed confirming read produce the destructive verdict: null
     * cannot describe the disk, so lifecycle_state() answered `stale`, and a
     * database that would not answer one SELECT deleted every generated file and
     * booked a full crawl of the site. That is precisely backwards. This release
     * exists to stop the plugin destroying anything on evidence it does not have,
     * and an unavailable database is the strongest possible case of not having
     * it. The two are kept apart so the caller can defer on one and act on the
     * other, because "no row" is a real state that a legitimately stale install
     * has to be able to reach: an install that has never stamped anything.
     *
     * @return string|null|false The stamp; null when the row genuinely does not
     *                           exist; false when the question could not be
     *                           asked at all.
     */
    protected static function fresh_artifact_stamp()
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return false;
        }

        // Suppressed and then inspected, the same way purge_unreadable_cache_rows()
        // does it a few hundred lines up. The failure is handled here, by
        // deferring, so it does not also need to be printed into somebody's page.
        $suppressed = $wpdb->suppress_errors(true);
        $value      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                static::artifact_option_name()
            )
        );
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ('' !== $error) {
            // get_var() returns null for a query that failed and for a query that
            // matched nothing, so the error is the only thing that tells them
            // apart.
            return false;
        }

        return (null === $value) ? null : (string) $value;
    }

    /**
     * Does this stamp describe this disk.
     *
     * Extracted so lifecycle_state() and its confirming pass cannot drift apart.
     * They were written as two copies of the comparison first, and two copies of
     * a three-clause test is how one of them ends up missing the version floor.
     *
     * @param string|null $stored      A stamp, "<plugin version> <fingerprint>".
     * @param string      $fingerprint What is on disk now.
     * @return bool
     */
    protected static function stamp_describes($stored, $fingerprint)
    {
        if (null === $stored) {
            return false;
        }

        $parts   = explode(' ', $stored, 2);
        $version = $parts[0];
        $digest  = isset($parts[1]) ? $parts[1] : null;

        return (null !== $digest
            && $digest === $fingerprint
            && version_compare($version, self::ARTIFACT_REBUILD_SINCE, '>='));
    }

    /**
     * Where this site stands with its generated document. One decision, in one
     * place, for every caller.
     *
     * The lock, the artifact stamp, the rebuild flag and the shutdown handler are
     * one mechanism and were built as three. Each of the three inferred the state
     * from the disk in its own way and none of them could see the others, which is
     * how a release that deletes a document before rebuilding it produced, in
     * every round, a site left with no document at all. This method is that
     * mechanism's single reading of the world.
     *
     * The five states, and the only thing each of them permits:
     *
     *   current    a document is on disk, we wrote it, nothing is owed. Do
     *              nothing.
     *   none       nothing on disk, we deliberately left nothing, nothing owed.
     *              Do nothing. This is a site that has never generated, and a
     *              fully failed rebuild is NOT this state, because the flag
     *              would be set.
     *   owed       a rebuild is owed. Whatever is on disk is ours or is nothing,
     *              so nothing is deleted; the rebuild routes do the work.
     *   in_flight  a generation holds a fresh lease. The files belong to it.
     *   stale      what is on disk is not what we left: a document from before
     *              8.5.4, one restored from a backup, one written by a rollback
     *              to 8.5.3, or our own document removed by something outside
     *              the plugin. This is the only state that deletes anything.
     *
     * COST. The order is the order of expense. A healthy install answers current
     * or none from the autoload set and two stats, with no query at all; the
     * lease, which is one of the two queries here, is only read when the disk
     * already disagrees with the stamp, which on a healthy install never
     * happens. The other, the confirming read of the stamp, is behind that
     * again and is only paid where the alternative is deleting the site's
     * document and crawling every post to rebuild it.
     *
     * @return string One of the LIFECYCLE_* constants.
     */
    public static function lifecycle_state()
    {
        if (!function_exists('llms_generated_artifact_fingerprint')) {
            // The main plugin file defines it and is loaded long before this
            // runs. Answering in_flight means "not mine to act on", which is the
            // safe answer when the question cannot be asked.
            return self::LIFECYCLE_IN_FLIGHT;
        }

        $stored      = static::stored_artifact_stamp();
        $fingerprint = llms_generated_artifact_fingerprint();

        if (static::stamp_describes($stored, $fingerprint)) {
            return static::settled_state($fingerprint);
        }

        if (static::generation_in_flight()) {
            return self::LIFECYCLE_IN_FLIGHT;
        }

        /*
         * ABOUT TO ANSWER `stale`, WHICH IS THE ONLY STATE THAT DELETES ANYTHING.
         *
         * Everything above this line was decided from the request's bootstrap
         * snapshot of the options table, and that snapshot can be older than the
         * files it is being compared against. On 8.5.5 that was enough, on its
         * own, to make a busy site delete and rebuild its own document every
         * thirty to ninety seconds for ever. See stored_artifact_stamp() for the
         * measurement.
         *
         * So the verdict is not acted on until it has been confirmed against the
         * database. Three reads, in this order, and the order is the argument:
         *
         *   1. the fingerprint, live      (already taken, above)
         *   2. the stamp, live from wp_options
         *   3. the fingerprint, live, again
         *
         * If (1) and (3) disagree, the files changed while we were asking, which
         * means a generation settled underneath this request. Nothing here is
         * entitled to judge that: answer in_flight and let the next request,
         * which will see a settled disk, decide. That is the same deferral the
         * lease check above makes, reached by evidence rather than by a lock,
         * and it covers the gap between a run settling its files and that run
         * writing its stamp.
         *
         * If they agree, the disk was stable across the whole confirmation, the
         * stamp was read inside that stable window, and a mismatch is now a
         * statement about the world rather than about this request's timing.
         *
         * COST. None of this is on the healthy path. A site whose document is its
         * own returns at settled_state() above, from the snapshot and two stats,
         * with no query at all. This runs only where the alternative is deleting
         * every generated file and crawling every post on the site.
         *
         * It also closes, as a side effect rather than as its purpose, the case
         * where the option is missing from the alloptions array altogether: a
         * persistent object cache serving a stale blob, an autoload flag that has
         * drifted. Measured at four deletions before it self-healed on 8.5.5.
         * Here it produces none.
         */
        $fresh = static::fresh_artifact_stamp();

        if (false === $fresh) {
            /*
             * The confirming read could not be made. A confirmation that could
             * not be obtained is not a confirmation, and the one thing this must
             * never do is treat "I could not check" as "I checked, and it is not
             * mine". Deferring here is the same answer, for the same reason, as
             * the one at the top of this method when the fingerprint function is
             * not loaded: not ours to act on.
             *
             * Nothing is recorded, nothing is stamped and nothing is deleted, so
             * a database that recovers between one request and the next simply
             * gets the decision made then.
             */
            return self::LIFECYCLE_IN_FLIGHT;
        }

        if ($fresh === $stored) {
            // The snapshot was already the truth. Nothing to re-evaluate, and no
            // second fingerprint to compute. Null on both sides lands here too,
            // which is the install that has never stamped anything arriving at
            // the migration, and it must still be able to reach stale.
            return self::LIFECYCLE_STALE;
        }

        $confirm = llms_generated_artifact_fingerprint();

        if ($confirm !== $fingerprint) {
            return self::LIFECYCLE_IN_FLIGHT;
        }

        if (static::stamp_describes($fresh, $confirm)) {
            // Confirmed, so the rebuild flag is read the same way. See
            // settled_state().
            return static::settled_state($confirm, true);
        }

        return self::LIFECYCLE_STALE;
    }

    /**
     * The answer when the stamp and the disk agree: which of the three settled
     * states this site is in.
     *
     * One place, because lifecycle_state() reaches this conclusion twice now,
     * once from the snapshot and once from the confirmation, and two copies of
     * it is how the owed case ends up handled on one path and not the other.
     *
     * $confirmed IS NOT A REFINEMENT, IT CLOSES A SECOND LOOP. Reaching here from
     * the confirming path means this request's options snapshot has been PROVEN
     * out of date, and note_document_promoted() writes the stamp and deletes the
     * rebuild flag in the same breath. So a snapshot too old to have the new
     * stamp is also too old to have lost the flag, and reading the flag out of it
     * answers "a rebuild is owed" about a rebuild that has already happened.
     *
     * That is not harmless. `owed` sends maybe_clean_artifacts() into
     * ensure_rebuild_scheduled(), which books another generation, which promotes,
     * which writes another stamp, which is another chance for the next request to
     * hold a snapshot older than it. It is the same self-sustaining loop as the
     * one this release is about, one state along and without the deletions:
     * llms.txt would stop disappearing and go on being rebuilt about once a
     * minute, which is the other half of what the report describes. Found by
     * reading this path after writing it, not by measuring it.
     *
     * The extra read costs a second primary key lookup, on a path that is already
     * rare and that only exists to avoid a full crawl of the site.
     *
     * @param string $fingerprint What is on disk.
     * @param bool   $confirmed   True when the snapshot is known to be stale.
     * @return string
     */
    protected static function settled_state($fingerprint, $confirmed = false)
    {
        if ($confirmed) {
            $pending = static::fresh_rebuild_pending();

            if (null === $pending) {
                // Could not ask. Same rule as the stamp read above: defer rather
                // than answer from evidence we do not have. Deferring is cheap
                // here, because the document has already been confirmed to be
                // ours and is on disk; the only thing not decided is whether a
                // further rebuild is owed, and the next request decides it.
                return self::LIFECYCLE_IN_FLIGHT;
            }
        } else {
            $pending = static::rebuild_pending();
        }

        if ($pending) {
            return self::LIFECYCLE_OWED;
        }

        return ('' === $fingerprint) ? self::LIFECYCLE_NONE : self::LIFECYCLE_CURRENT;
    }

    /**
     * Whether a rebuild is owed according to the database rather than according
     * to this request's bootstrap snapshot.
     *
     * Only for settled_state()'s confirmed path. Everywhere else the snapshot is
     * the right read: it is free, and being wrong about it there costs a delayed
     * re-arm rather than a generation.
     *
     * IT DOES NOT FALL BACK TO THE SNAPSHOT. It did, and that was wrong twice
     * over. The snapshot is the thing this method exists to distrust, and its
     * characteristic error on this path is the expensive direction: a snapshot
     * older than the promote still carries llms_rebuild_pending, so falling back
     * to it answers "owed" about a rebuild that has already happened and books a
     * full crawl of the site. Falling back to a known-stale read, on the one path
     * that exists because the read is stale, to answer a question the database
     * just declined, is not a fallback. Returning null and letting the caller
     * defer costs one request's delay and cannot book anything.
     *
     * @return bool|null True or false when the database answered; null when it
     *                   could not be asked.
     */
    protected static function fresh_rebuild_pending()
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
            return null;
        }

        $suppressed = $wpdb->suppress_errors(true);
        $value      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                static::rebuild_option_name()
            )
        );
        $error = $wpdb->last_error;
        $wpdb->suppress_errors($suppressed);

        if ('' !== $error) {
            return null;
        }

        return (null !== $value);
    }

    /**
     * Remove a generated file this version of the plugin did not write, and ask
     * for it to be rebuilt.
     *
     * This is the 8.5.4 migration, and it is deliberately NOT a ladder step, or
     * rather it is no longer only a ladder step. Keying it on llms_db_version
     * alone was wrong, and wrong in the leak direction:
     *
     *   An install reaches 8.5.4 and llms_db_version becomes 3. The owner does
     *   not like the smaller file and rolls the plugin back to 8.5.3, which has
     *   no ladder and leaves the option at 3. 8.5.3 regenerates, and the file
     *   contains restricted content again. The owner updates forward to 8.5.4.
     *   maybe_upgrade() finds installed (3) >= target (3) and returns, so step 3
     *   never runs a second time, nothing is deleted and nothing is rebuilt, and
     *   the site serves the pre-fix document with the fix installed. Measured on
     *   MySQL end to end. Rolling back is an ordinary support-forum answer and
     *   spec 3.10 names it as the thing the per post include exists to prevent,
     *   so this is a path a real site takes.
     *
     * The schema version cannot express this, by design: it only ever moves when
     * the table changes, and it moves in one direction. So the artifact carries
     * its own record, which says which plugin version wrote what is on disk, and
     * this runs whenever that record does not describe the files that are there.
     * That covers a rollback in either direction, a file restored from a backup
     * taken before the update, and an install that never had the ladder at all.
     *
     * Cost on a healthy install is one read out of the autoload set, which is
     * already in memory, and one stat per artifact. No query, and nothing is
     * written.
     *
     * The decision itself is not made here. lifecycle_state() makes it, once, for
     * every caller that needs it, which is what stops this mechanism drifting
     * apart again: the one-shot in the main plugin file, the generator and this
     * method used to each infer the state from the disk in their own way, and
     * every round since they were written has produced a defect in the seam.
     *
     * @return bool True when nothing is owed by the time this returns.
     */
    public static function maybe_clean_artifacts()
    {
        if (!function_exists('llms_generated_artifact_fingerprint')) {
            // The main plugin file defines it and is loaded long before this
            // runs. Nothing to do rather than fatal.
            return false;
        }

        $state = static::lifecycle_state();

        if (self::LIFECYCLE_OWED === $state) {
            // What is on disk is ours, or is nothing, so there is nothing here to
            // delete. The one thing worth doing is making sure the route to the
            // rebuild still exists, because the ways it can be lost are not all
            // visible to the code that created it. See ensure_rebuild_scheduled().
            static::ensure_rebuild_scheduled();

            return true;
        }

        if (self::LIFECYCLE_CURRENT === $state || self::LIFECYCLE_NONE === $state) {
            // What is on disk is what we left there, and it was left by a version
            // allowed to have written it.
            return true;
        }

        if (self::LIFECYCLE_IN_FLIGHT === $state) {
            /*
             * A generation is running in another request, and everything about
             * the files is its business until it finishes.
             *
             * Without this the cleanup can destroy the run: the destinations stop
             * matching the stamp at the moment of the promote, and a concurrent
             * request that took the artifact lease in that window would delete the
             * document the run has just put there.
             *
             * Deferred, not abandoned. Nothing is recorded and nothing is
             * stamped, so whichever request comes after the run either finds the
             * fingerprint the run stamped, or does this work then.
             */
            return false;
        }

        /*
         * The state is stale, and one reason it can be permanently stale is a
         * served path this process cannot remove (see clean_artifacts()). That
         * site would otherwise run a cache purge query and take a lease on every
         * request, for ever, which is the cost A2 was. One attempt per
         * REBUILD_RETRY_AFTER is enough to heal the moment the host makes the
         * path removable.
         */
        if (static::artifact_blocked_recently()) {
            return false;
        }

        /*
         * The hard stop on the cycle itself, and it is deliberately the last
         * thing asked before the lease is taken and the first thing that does not
         * care WHY the state is stale.
         *
         * Everything above this line reasons about a known cause. This does not.
         * It counts how many times the plugin has deleted the document and asked
         * for it back, and refuses once that has happened too often to be any of
         * the causes the cleanup exists for. A site can reach a permanently stale
         * state through a second plugin writing /llms.txt, through an alloptions
         * array that never carries our stamp, through a stale value in one, or
         * through something nobody has thought of yet, and all four look
         * identical from here: delete, rebuild, delete, rebuild. The three brakes
         * this release inherited all fail open on the one path where the rebuild
         * SUCCEEDS, because success is what clears them. This one is not cleared
         * by success. See artifact_cycle_limit_reached().
         *
         * Nothing is stamped on the way out, which is the whole difference
         * between standing down and adopting. Stamping here would make
         * lifecycle_state() answer `current` against a document we did not write,
         * which is exactly the 8.5.4 defect that 8.5.5 exists to fix, arriving
         * through a new door. The state stays stale, the plugin stops acting on
         * it, and the settings screen says so.
         */
        if (static::artifact_cycle_limit_reached()) {
            return false;
        }

        /*
         * Serialised, because two requests both deleting the file and both
         * asking for a rebuild is wasteful rather than wrong, and because the
         * DELETE below is worth doing once. A request that cannot take the lease
         * does nothing and the next one picks it up; nothing is recorded, so
         * this is not a failure state.
         */
        $lock  = static::artifact_lock_name();
        $token = LLMS_Lock::acquire($lock);

        if (false === $token) {
            return false;
        }

        try {
            $cleaned = static::clean_artifacts();
        } catch (\Throwable $e) {
            // This runs on init on every request. A throw here would take the
            // site down rather than leaving the cleanup owed.
            static::record_condition('artifact_cleanup_threw', 0, get_class($e) . ': ' . $e->getMessage());
            $cleaned = false;
        } finally {
            LLMS_Lock::release_identity($lock, $token);
        }

        return $cleaned;
    }

    /**
     * Lease name for the artifact cleanup.
     *
     * @return string
     */
    protected static function artifact_lock_name()
    {
        return 'llms_artifact_lock';
    }

    /**
     * Whether a generation holds its lease right now.
     *
     * One query, and only ever reached when the fingerprint already disagrees
     * with what was stamped, which on a healthy install is never. The lock row
     * is autoload = no by design, so this cannot come out of the autoload set
     * and there is no cheaper way to ask.
     *
     * The token format and the staleness threshold both belong to LLMS_Lock.
     * The threshold is read from its constant; the parse is repeated here
     * because LLMS_Lock::is_stale() is protected, and a lease left behind by a
     * run that was killed rather than unwound has to age out or the cleanup
     * would be deferred for ever. Anything unparseable is treated as not in
     * flight, which is the same direction that class treats it: a value we did
     * not write carries no age we can read, so it must not block the work.
     *
     * @return bool
     */
    protected static function generation_in_flight()
    {
        if (!class_exists('LLMS_Lock')) {
            return false;
        }

        $held = LLMS_Lock::held_token(static::generation_lock_name());

        if (!is_string($held)) {
            return false;
        }

        $parts = explode(':', $held, 2);

        if (2 !== count($parts) || '' === $parts[0] || !ctype_digit($parts[0])) {
            return false;
        }

        $stamped = (int) $parts[0];

        // Further ahead than the threshold is not clock skew, it is a row nobody
        // is going to release, and LLMS_Lock::is_stale() calls that stale so a
        // generation can steal it. Calling it in flight here instead would defer
        // the cleanup for as long as the row sat there.
        if ($stamped > (time() + LLMS_Lock::STALE_AFTER)) {
            return false;
        }

        $age = time() - $stamped;

        // A future stamp inside the threshold is a clock difference between two
        // web servers sharing one database, and a lease taken moments ago on the
        // node that is ahead is exactly the case this must not walk into. The
        // age is negative there, so it reads as in flight, which is what it is.
        return ($age <= LLMS_Lock::STALE_AFTER);
    }

    /**
     * Lease name the generator takes for a run.
     *
     * Here rather than read off the generator, which holds it in a private
     * property and is not loaded on every request that reaches this class.
     * LLMS_Generator::$lock_name has to match.
     *
     * @return string
     */
    protected static function generation_lock_name()
    {
        return 'llms_generation_lock';
    }

    /**
     * Do the cleanup itself.
     *
     * Order is deliberate and is the order the ladder step used. The DELETE is
     * first, because it is the only part that can fail on a database we do not
     * control, and a failure there leaves the file in place with nothing yet
     * destroyed. Then the file goes. Then the rebuild is asked for. Then the
     * stamp, which is what stops this running again on the next request.
     *
     * "Are the artifacts gone" and "is a rebuild owed" are two questions and
     * this used to answer both with one test, `$deleted > 0`. That is wrong on
     * the path where the files were already removed by somebody else, and this
     * release created that path: the generator's shutdown handler unlinks both
     * destinations when a run dies mid flight, so the next request finds nothing
     * to delete, asks for no rebuild, re-stamps, and the site serves 404 until
     * its next scheduled run. On a site whose cron does not fire, which is the
     * population the admin_init one shot exists for, that is until somebody
     * saves a post.
     *
     * The stamp answers the second question, because it records what this plugin
     * last left on disk deliberately. A non-empty fingerprint in it means there
     * was a document and it is now missing, whoever removed it. An empty one
     * means we left nothing there and nothing is owed. No stamp at all is the
     * pre-8.5.4 install arriving at the migration, and there `$deleted` is the
     * only evidence available and still the right test: a site that has never
     * generated a file must not run a full first crawl inside somebody's admin
     * page load.
     *
     * @return bool
     */
    protected static function clean_artifacts()
    {
        if (!static::purge_unreadable_cache_rows()) {
            return false;
        }

        if (!function_exists('llms_delete_generated_files')) {
            return false;
        }

        // Read before the delete and before the re-stamp, because both destroy
        // the evidence this is about.
        $claimed = static::stamp_claims_a_document();

        // Likewise. Whatever is answering /llms.txt at this moment is what the
        // notice has to be able to name, and in a moment it will not be there.
        $present = static::present_served_paths();

        $survivors = array();
        $deleted   = (int) llms_delete_generated_files($survivors);

        /*
         * Count the cycle, once, here.
         *
         * HERE, and not on the success path, because the success path is where
         * every other brake in this class is released. note_document_promoted()
         * deletes llms_rebuild_pending and with it the attempt count that
         * rebuild_attempts() reads; it calls forget_artifact_block_if_clear(),
         * and clean_artifacts() itself calls clear_artifact_blocked() a few lines
         * below. A rebuild that WORKS therefore resets all three, which is
         * correct for all three: they measure failure, and there was none. The
         * runaway is made of successes. Nothing that a successful rebuild does
         * may touch this count, and nothing does: it is written here and it is
         * removed by the window and by uninstall, and by nothing else at all.
         *
         * ONCE, because the survivor branch below asks for a rebuild a second
         * time in the same pass, and two option writes for one delete would halve
         * the allowance for no reason.
         *
         * Both branches count. A pass that deleted a served document and asked
         * for it back is a cycle whether or not one of the paths survived, and
         * the survivor case does the same work: the deletable copies are gone,
         * the crawl is asked for, and the site pays for it. Its own 300 second
         * rate limit already bounds it at twelve an hour, so this only ever
         * lowers that, on a site that is by definition already broken and already
         * being told so by name on the settings screen.
         *
         * A pass that deleted nothing, was owed nothing and found no survivor is
         * not a cycle. That is a site which has never generated arriving at the
         * migration, and it must not spend an allowance for doing nothing.
         */
        $cycled = ($deleted > 0 || $claimed || !empty($survivors));

        if ($cycled) {
            static::note_artifact_cycle($present);
        }

        if ($deleted > 0 || $claimed) {
            static::request_rebuild();
        }

        if (!empty($survivors)) {
            /*
             * A served document we could not remove is still answering
             * /llms.txt. On an 8.5.3 -> 8.5.4 update that is very likely the
             * pre-fix document, which is the one carrying content this release
             * exists to withhold.
             *
             * DO NOT STAMP. The stamp records what this plugin deliberately
             * left on disk, and we did not leave this. Stamping it would make
             * lifecycle_state() answer `current` on the next request and the
             * cleanup would never look at it again, which is exactly how 8.5.4
             * shipped and why 8.5.5 exists.
             *
             * Leaving the stamp disagreeing with the disk keeps the state at
             * `stale`, which is the state that retries this cleanup. So the
             * site heals itself the moment the host makes the path removable,
             * and until then the record below says so on the settings screen
             * instead of the failure being silent. maybe_clean_artifacts() rate
             * limits the retry.
             *
             * KNOWN RESIDUAL, and it is the honest limit of this fix. This
             * covers the migration. It does not cover a later rebuild adopting
             * the same survivor: promote_generated_file() returns true when the
             * uploads copy promoted even if the root copy could be neither
             * replaced nor removed, note_document_promoted() then stamps what is
             * on disk, and the state becomes `current` with the survivor blessed.
             * From there this cleanup is never reached again.
             *
             * Not closed in 8.5.5 on purpose. The common shape of "PHP cannot
             * write the root file", a read-only ABSPATH with a writable
             * llms.txt, is already handled: promote's last-resort overwrite
             * replaces the contents in place, so there is no stale document
             * left. What remains is a genuinely immutable file (chflags uchg,
             * chattr +i), and for that the plugin has no move available: it
             * cannot delete it, cannot overwrite it, and retrying forever would
             * add cost without changing anything, while a cleanup pass that DID
             * run again would delete the good uploads copy trying. The remedy is
             * the owner's, so what 8.5.5 owes them is a permanent, accurate
             * notice naming the path, which is what the block record is and why
             * forget_artifact_block_if_clear() re-checks the filesystem rather
             * than trusting a successful promote. Fixing the adoption itself
             * means making the fingerprint reflect ownership rather than what
             * happens to be on disk, which is an 8.6.0-sized change.
             */
            static::request_rebuild();

            static::note_artifact_blocked($survivors);

            return false;
        }

        static::clear_artifact_blocked();

        static::stamp_artifacts();

        return true;
    }

    /**
     * The served paths that exist right now.
     *
     * Read immediately before the delete, so the notice can name what was
     * actually answering /llms.txt rather than describe it in the abstract. The
     * stats are free at that point: llms_generated_artifact_fingerprint() has
     * already cleared and refilled the stat cache entry for each of these paths
     * in this request, in lifecycle_state(), a few microseconds earlier.
     *
     * @return string[]
     */
    protected static function present_served_paths()
    {
        $present = array();

        if (!function_exists('llms_served_file_paths')) {
            return $present;
        }

        foreach (llms_served_file_paths() as $path) {
            if (file_exists($path)) {
                $present[] = (string) $path;
            }
        }

        return $present;
    }

    /**
     * Option holding the recent delete-and-rebuild cycles.
     *
     * ITS OWN OPTION, and that is the point of it rather than an implementation
     * detail. This has to survive everything the success path clears, and the
     * success path clears a great deal: note_document_promoted() deletes
     * llms_rebuild_pending, clean_artifacts() calls clear_artifact_blocked(), and
     * llms_db_condition is wiped by five separate callers whenever the schema
     * looks healthy. 8.5.5 already learned this the expensive way with the
     * undeletable-artifact record: it was put in the shared condition slot and
     * then exempted at the clearing sites one at a time, and it was still being
     * deleted in the same request, in the end by the settings screen itself. The
     * lesson recorded there is the rule followed here. An option nothing else
     * knows about cannot be cleared by anything else, and no exemption has to be
     * remembered by whoever writes the next clearing site.
     *
     * autoload = no. A healthy install never reads it, because lifecycle_state()
     * answers current, none or owed and maybe_clean_artifacts() returns before
     * this is reached. Autoloading it would put it in the alloptions array of
     * every request of every install that has ever run one cycle, for ever, which
     * is a permanent cost for a transient record.
     *
     * @return string
     */
    protected static function artifact_cycle_option_name()
    {
        return 'llms_artifact_cycles';
    }

    /**
     * The stored cycle record, normalised, with anything outside the window
     * already dropped.
     *
     * The window is applied on read rather than on write, which is what makes it
     * a rolling window and not a bucket that refills on the hour: a timestamp
     * ages out ARTIFACT_CYCLE_WINDOW seconds after the cycle it records, one at a
     * time, wherever the hour boundary happens to be.
     *
     * A timestamp in the future is dropped rather than trusted. A clock set
     * forward and later corrected would otherwise hold the limiter closed until
     * the clock caught up, and this mechanism refusing to work is a site with no
     * llms.txt.
     *
     * @return array ['at' => int[], 'paths' => string[]]
     */
    protected static function artifact_cycle_record()
    {
        $stored = get_option(static::artifact_cycle_option_name());
        $now    = time();
        $at     = array();
        $paths  = array();

        if (is_array($stored)) {
            if (isset($stored['at']) && is_array($stored['at'])) {
                foreach ($stored['at'] as $stamp) {
                    $stamp = (int) $stamp;

                    if ($stamp > 0 && $stamp <= $now && ($now - $stamp) < static::ARTIFACT_CYCLE_WINDOW) {
                        $at[] = $stamp;
                    }
                }
            }

            if (isset($stored['paths']) && is_array($stored['paths'])) {
                $paths = array_values(array_map('strval', $stored['paths']));
            }
        }

        sort($at, SORT_NUMERIC);

        return array('at' => $at, 'paths' => $paths);
    }

    /**
     * Record that a delete-and-request-rebuild cycle has just been performed.
     *
     * Only the timestamps still inside the window are written back, so the option
     * cannot grow: it holds at most ARTIFACT_CYCLE_LIMIT entries plus the one
     * being added, and the read above drops the rest. There is no separate
     * cleanup to forget to run and no way for this to become large.
     *
     * @param string[] $paths The served paths that were on disk for this cycle.
     * @return void
     */
    protected static function note_artifact_cycle($paths)
    {
        $record = static::artifact_cycle_record();
        $at     = $record['at'];

        $at[] = time();

        // Keep the most recent, in the impossible case that a clock jump or a
        // hand-edited option leaves more than the limit inside the window. The
        // count is what the limit is read from, so an unbounded list here would
        // be an unbounded option.
        if (count($at) > (static::ARTIFACT_CYCLE_LIMIT + 1)) {
            $at = array_slice($at, -(static::ARTIFACT_CYCLE_LIMIT + 1));
        }

        update_option(
            static::artifact_cycle_option_name(),
            array(
                'at'    => array_values($at),
                'paths' => array_values(array_map('strval', (array) $paths)),
            ),
            false
        );
    }

    /**
     * Has this site cycled too often to keep cycling.
     *
     * THE FIRST CYCLE IS NEVER REFUSED, and that is load bearing rather than
     * incidental. The migration this whole mechanism was built for is one cycle:
     * an 8.5.3-era document, which may carry content the site does not publish,
     * is found, deleted and rebuilt. A limiter that could stand down before that
     * happened would leave the file in place, which is the outcome 8.5.4 and
     * 8.5.5 were both released to prevent. So the count has to reach the limit
     * before anything is refused, and the limit is three.
     *
     * A file that keeps coming back is, by definition, not that migration. The
     * migration happens once per install per rollback; a fourth cycle inside an
     * hour is a cause the plugin cannot fix by deleting the file again, and
     * deleting it again costs a full crawl of every post on the site.
     *
     * Reached only while lifecycle_state() is stale, so a healthy install never
     * reads the option and never pays the query.
     *
     * @return bool
     */
    protected static function artifact_cycle_limit_reached()
    {
        $record = static::artifact_cycle_record();

        return (count($record['at']) >= static::ARTIFACT_CYCLE_LIMIT);
    }

    /**
     * The cycling record for anything that needs to show it, or null when the
     * site is not standing down.
     *
     * Reports only while the limit is actually reached. A site that cycled once
     * yesterday is a site that did what this mechanism is for, and a notice about
     * it would be noise on a screen whose notices have to mean something.
     *
     * @return array|null ['since' => int, 'last' => int, 'count' => int,
     *                    'until' => int, 'paths' => string[]], or null.
     */
    public static function artifact_cycling()
    {
        $record = static::artifact_cycle_record();

        if (count($record['at']) < static::ARTIFACT_CYCLE_LIMIT) {
            return null;
        }

        $first = $record['at'][0];
        $last  = $record['at'][count($record['at']) - 1];

        return array(
            'since' => $first,
            'last'  => $last,
            'count' => count($record['at']),
            // When the oldest entry ages out and one cycle is allowed again.
            'until' => $first + static::ARTIFACT_CYCLE_WINDOW,
            'paths' => $record['paths'],
        );
    }

    /**
     * Option recording when the cleanup last found a served path it could not
     * remove.
     *
     * Deliberately NOT the condition record, even though that carries a
     * timestamp. record_condition() returns early without refreshing its
     * timestamp when the same code and the same detail are recorded again,
     * which is the ordinary case here, so a rate limit read from it would open
     * after the first interval and never close again. That is the same shape of
     * unbounded retry as A2, and getting it wrong here would put a query and a
     * lease acquisition on every request of a broken site.
     *
     * autoload = no, because a healthy install never reads it: lifecycle_state()
     * answers current, none or owed and maybe_clean_artifacts() returns before
     * this is consulted.
     *
     * @return string
     */
    protected static function artifact_block_option_name()
    {
        return 'llms_artifact_undeletable_at';
    }

    /**
     * Record that the cleanup could not clear a served path.
     *
     * @return void
     */
    protected static function note_artifact_blocked($paths)
    {
        update_option(
            static::artifact_block_option_name(),
            array(
                'at'    => time(),
                'paths' => array_values(array_map('strval', (array) $paths)),
            ),
            false
        );
    }

    /**
     * The current block, for anything that needs to show it.
     *
     * DELIBERATELY NOT a condition record, and that is the whole point of this
     * option. llms_db_condition is the schema machinery's slot, and five
     * separate places clear it: run_step() on success, the two repair paths,
     * ensure_baseline() through clear_condition_if_present(), and schema_status()
     * when it finds the schema complete. Every one of them is correct about a
     * SCHEMA record and wrong about this one, which is a statement about the
     * filesystem that a healthy database does not disprove.
     *
     * 8.5.5 tried putting it in that slot and then exempting it. Measured, it was
     * still deleted in the same request by run_step()'s success path, and once
     * that was understood, by the settings screen itself: the screen calls
     * schema_status(), which cleared it, so the one page whose job was to show
     * the path rendered it once and then wiped it. Exempting five call sites one
     * at a time is how a cross-cutting change leaves exactly one line unconverted.
     * Its own option cannot be reached by any of them.
     *
     * @return array|null ['at' => int, 'paths' => string[]], or null when clear.
     */
    public static function artifact_block()
    {
        $stored = get_option(static::artifact_block_option_name());

        if (!is_array($stored) || !isset($stored['at']) || empty($stored['paths'])) {
            return null;
        }

        return array(
            'at'    => (int) $stored['at'],
            'paths' => array_values((array) $stored['paths']),
        );
    }

    /**
     * Forget any previous block, because the cleanup has just succeeded.
     *
     * @return void
     */
    protected static function clear_artifact_blocked()
    {
        if (false !== get_option(static::artifact_block_option_name(), false)) {
            delete_option(static::artifact_block_option_name());
        }
    }

    /**
     * Whether the cleanup was blocked recently enough that retrying now is
     * only cost.
     *
     * A record stamped in the future, which a clock set forward and later
     * corrected produces, is treated as no record rather than blocking the
     * retry until the clock catches up.
     *
     * @return bool
     */
    protected static function artifact_blocked_recently()
    {
        $block = static::artifact_block();

        if (null === $block) {
            return false;
        }

        $at = $block['at'];

        if ($at <= 0 || $at > time()) {
            return false;
        }

        return (time() - $at) < static::REBUILD_RETRY_AFTER;
    }

    /**
     * Whether the stamp says we left a document on disk.
     *
     * The stamp is "<plugin version> <fingerprint>", and the fingerprint is
     * empty when nothing was there at the moment it was written. So a non-empty
     * fingerprint is this plugin's own record that a document existed, which is
     * the only thing that distinguishes "the file went missing" from "this
     * install has never had one".
     *
     * @return bool False when there is no stamp at all, which is every install
     *              arriving from 8.5.3.
     */
    protected static function stamp_claims_a_document()
    {
        $stored = static::stored_artifact_stamp();

        if (null === $stored) {
            return false;
        }

        $parts = explode(' ', $stored, 2);

        return (isset($parts[1]) && '' !== $parts[1]);
    }

    /**
     * Option holding the last thing that went wrong.
     *
     * @return string
     */
    protected static function condition_option_name()
    {
        return 'llms_db_condition';
    }

    /**
     * Write down, in a shape a machine can read, that the ladder is not well.
     *
     * A ladder failure is otherwise completely silent on production.
     * _doing_it_wrong() fires an action and triggers nothing when WP_DEBUG is
     * off, and a step's Throwable is swallowed by design, so a shipped build
     * whose constant and steps disagree, or a step that fails on every request,
     * would stop the ladder for good on 40,000 installs with nothing to see.
     * This is the state the settings screen renders later; no UI is built here.
     *
     * Autoloaded on purpose, and this is the one thing that makes the record free
     * on a healthy install: an option that is absent is not in the autoload set,
     * so nothing reads it and nothing pays for it, while on an install that does
     * carry one, clear_condition_if_present() finds it in the alloptions array
     * that every request already loads. Reading it with get_option() on every
     * request would add a query per request to every install instead.
     *
     * Written when anything in it changes, including the detail, because the
     * detail is the only part a human can act on: it carries the exception class
     * and message, and a step failing for a new reason with the same code would
     * otherwise show the first reason for ever. time is the first time this code
     * and step were seen, updated is the last, so the surface can say both when
     * the trouble started and whether it is still current.
     *
     * Rewriting for a changed detail alone is throttled, because that is the one
     * shape that can write on every request: a message carrying an id or a value
     * differs each time a permanently failing step runs. Same code and step plus
     * a changed detail is therefore written at most once every
     * CONDITION_REFRESH_AFTER seconds, which bounds the writes without letting
     * the surface go meaningfully stale. A changed code or step is never
     * throttled: that is a different fault and it is written immediately.
     *
     * @param string $code    Machine-readable condition.
     * @param int    $version Step the condition belongs to.
     * @param string $detail  Free text for a human reading the record.
     * @return void
     */
    protected static function record_condition($code, $version, $detail)
    {
        $existing = get_option(static::condition_option_name());
        $now      = time();

        $same_fault = (is_array($existing)
            && isset($existing['code'], $existing['step'])
            && $code === $existing['code']
            && (int) $version === (int) $existing['step']);

        $first_seen = $now;

        if ($same_fault) {
            if (isset($existing['detail']) && (string) $detail === (string) $existing['detail']) {
                // Nothing has changed. This is the ordinary case on an install
                // where a step fails on every request, and it is why that install
                // writes once rather than once per request.
                return;
            }

            if (isset($existing['updated'])) {
                $last_write = (int) $existing['updated'];
            } elseif (isset($existing['time'])) {
                // A record written before this method carried an updated key.
                $last_write = (int) $existing['time'];
            } else {
                $last_write = 0;
            }

            // $last_write <= $now so that a record stamped in the future, which a
            // clock set forward and later corrected produces, refreshes at once
            // instead of being throttled until the clock catches up.
            if ($last_write <= $now && ($now - $last_write) < self::CONDITION_REFRESH_AFTER) {
                return;
            }

            if (isset($existing['time'])) {
                $first_seen = (int) $existing['time'];
            }
        }

        update_option(
            static::condition_option_name(),
            array(
                'code'      => (string) $code,
                'step'      => (int) $version,
                'time'      => $first_seen,
                'updated'   => $now,
                'target'    => static::target_version(),
                'installed' => static::installed_version(),
                'detail'    => (string) $detail,
            ),
            true
        );
    }

    /**
     * Clear the record, because the thing it describes is over.
     *
     * Called on the paths that just did work: a step that recorded its version,
     * and a repair that put the table back. Both are rare, so the read
     * delete_option() does costs nothing on the normal path.
     *
     * @return void
     */
    protected static function clear_condition()
    {
        delete_option(static::condition_option_name());
    }

    /**
     * Clear the record on the request where there was nothing to do.
     *
     * Reads the autoload set rather than the option, so an install with no
     * record pays nothing: wp_load_alloptions() is already loaded and cached by
     * the time the ladder runs, and an absent option is not in it. An install
     * carrying a record from trouble that has since resolved gets it dropped on
     * the next ordinary request.
     *
     * "There was nothing to do" means the table is there and the ladder is at
     * the target. That disproves most of what can be recorded, and it is why
     * clearing here is right at all. It does not disprove everything, and the
     * two records it does not disprove used to be wiped by the very next
     * WordPress bootstrap, which is how a condition that describes a permanent
     * state ended up invisible on the screen built to show it. A repair that
     * could not replay a step leaves a table that is missing an index while the
     * table itself is present and the ladder is current, so the healthy-looking
     * request is exactly the request that must not clear it. See
     * condition_survives_healthy_request().
     *
     * @return void
     */
    protected static function clear_condition_if_present()
    {
        $autoloaded = wp_load_alloptions();
        $name       = static::condition_option_name();

        if (!is_array($autoloaded) || !isset($autoloaded[$name])) {
            return;
        }

        $existing = maybe_unserialize($autoloaded[$name]);
        $code     = (is_array($existing) && isset($existing['code'])) ? (string) $existing['code'] : '';

        if (static::condition_survives_healthy_request($code)) {
            return;
        }

        static::clear_condition();
    }

    /**
     * Whether an ordinary healthy request leaves this record alone.
     *
     * The repair path records these two when it has put the table back but could
     * not put all of the schema back with it. Nothing about a later request
     * establishes that the schema is complete: the table exists, the ladder is
     * at the target, and the missing index is still missing. They are cleared by
     * clear_condition(), which runs when a step records its version or when a
     * repair replays cleanly, and by schema_status() when it looks and finds
     * nothing missing. That last one is what stops them being immortal, and it
     * is on the admin screen because that is the only place that can afford to
     * ask the database.
     *
     * @param string $code Condition code.
     * @return bool
     */
    protected static function condition_survives_healthy_request($code)
    {
        // Schema records only. An undeletable served artifact was briefly added
        // here and it was the wrong home: every consumer of this list treats
        // "the schema is fine" as disproving the record, and a file the
        // filesystem will not let go of is not disproved by a healthy database.
        // It has its own option now. See LLMS_DB::artifact_block().
        return in_array(
            $code,
            array('baseline_replay_incomplete', 'baseline_replay_failed'),
            true
        );
    }
}
