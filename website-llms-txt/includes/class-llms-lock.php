<?php
/**
 * Cross-request lock.
 *
 * @package Website_LLMS_TXT
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A mutual exclusion lock decided by the database, not by PHP.
 *
 * add_option() cannot be used for this. It compiles down to
 * INSERT ... ON DUPLICATE KEY UPDATE, so the only thing that makes it look
 * exclusive is the get_option() call in front of it: two requests that both
 * read "not locked" both go on to take the lock. That check-then-act window is
 * the mechanism behind the truncated llms.txt seen when a cron run and an admin
 * run overlap and append to the same temp file.
 *
 * Everything here writes to the options table directly, so the claim is settled
 * by the unique index on option_name instead of by PHP, and every direct write
 * is followed by forget_cached_option() because a persistent object cache never
 * sees SQL we issue ourselves.
 *
 * The class is not final because the test fixtures subclass it.
 */
class LLMS_Lock
{
    /**
     * Seconds after which a held lock is treated as abandoned.
     *
     * A process that is killed mid-run cannot release its own lock, so without
     * a steal threshold one fatal error would stop the file regenerating for
     * good.
     */
    const STALE_AFTER = 300;

    /**
     * Longest lock name we will accept.
     *
     * option_name is VARCHAR(191) since WordPress 4.4. A longer name is a
     * programming error, and silently truncating it would let two different
     * locks collide.
     */
    const MAX_NAME_LENGTH = 191;

    /**
     * Take the lock.
     *
     * @param string $lock_name   Option name to claim. Must be unique to the job.
     * @param int    $stale_after Seconds before an existing lock may be stolen.
     * @return string|false The token to release with, or false if someone else holds it.
     */
    public static function acquire($lock_name, $stale_after = self::STALE_AFTER)
    {
        global $wpdb;

        if (!is_string($lock_name) || '' === $lock_name || strlen($lock_name) > self::MAX_NAME_LENGTH) {
            return false;
        }

        // At most two passes. The second only happens when the holder released
        // between our insert and our read, which leaves nothing to steal and
        // nothing to report, so the only correct answer is to try again.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = static::make_token();

            // INSERT IGNORE is resolved by the unique index on option_name, so
            // of any number of concurrent callers exactly one comes out of this
            // with rows_affected === 1. Nothing is read first, so there is no
            // window to lose.
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $lock_name,
                $token
            ));
            $claimed = (1 === $wpdb->rows_affected);

            // autoload is 'no' on purpose. WordPress 6.6 added 'off'/'auto' and
            // friends, but the floor here is 5.8, whose wp_load_alloptions()
            // matches autoload = 'yes' exactly. 'no' is the one value that is
            // read as "do not autoload" on every version in range.

            // A row exists either way at this point: either we inserted it or
            // the holder we lost to did.
            static::forget_cached_option($lock_name, true);

            if ($claimed) {
                return $token;
            }

            $held = static::held_token($lock_name);
            if (null === $held) {
                // Released underneath us. Go around and claim it outright.
                continue;
            }

            if (!static::is_stale($held, $stale_after)) {
                return false;
            }

            // Compare and swap. Read the value, then write it back unconditionally
            // and every caller looking at the same stale token believes it won.
            // Pinning the update to the exact value we read means the database
            // hands the steal to one of them and no one else.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $token,
                $lock_name,
                $held
            ));
            $stolen = (1 === $wpdb->rows_affected);

            static::forget_cached_option($lock_name, true);

            if ($stolen) {
                return $token;
            }

            // Losing the swap does not mean somebody holds it. The row we pinned
            // to can also have gone away, because the rightful holder released
            // between our read and our update, and then reporting "someone else
            // has it" makes the caller wait out a whole request cycle for a lock
            // nobody holds. Go around instead: the next pass either claims the
            // free row outright or reads whoever really took it. Same two passes
            // as the released-between-insert-and-read case above, so this cannot
            // turn into an unbounded retry.
        }

        return false;
    }

    /**
     * Whether a token still names the holder of this lock.
     *
     * Compares identity, not the whole stored value. refresh() issues a new
     * token every time it extends the lease: same uuid, new timestamp. So a
     * long job that has refreshed holds a lock whose stored value no longer
     * equals the token acquire() handed it, and comparing whole strings reports
     * that its own lease belongs to somebody else.
     *
     * Cheap enough to call before a destructive step in a long job, which is
     * what it is here for. It is deliberately not used to decide whether a
     * release is allowed: that would be a read followed by a write on the
     * strength of the read, and release_identity() answers the same question
     * from the write itself.
     *
     * @param string $lock_name Option name.
     * @param string $token     Token from acquire() or from any refresh() of it.
     * @return bool
     */
    public static function owns($lock_name, $token)
    {
        $held = static::held_token($lock_name);

        return (null !== $held) && static::same_identity($held, $token);
    }

    /**
     * Give the lock up when the row is ours by identity.
     *
     * release() requires the exact stored value, so a job that refreshed its
     * lease cannot release with the token it was given, and its row is left
     * behind until the steal threshold expires. This is the release a caller
     * that may have refreshed should use.
     *
     * The return value is the answer to "was this still ours until now", taken
     * from the delete rather than from a separate read, so there is no window
     * between checking and acting. The delete is still pinned to the exact value
     * that was read, so a lease stolen in between deletes nothing and reports
     * false, and the thief's row is never removed by the previous holder.
     *
     * @param string $lock_name Option name.
     * @param string $token     Token from acquire() or from any refresh() of it.
     * @return bool True if this call released the lock.
     */
    public static function release_identity($lock_name, $token)
    {
        $held = static::held_token($lock_name);

        if (null === $held || !static::same_identity($held, $token)) {
            return false;
        }

        return static::release($lock_name, $held);
    }

    /**
     * The part of a token that does not change when the lease is extended.
     *
     * A token is "<unix timestamp>:<uuid>". refresh() keeps the uuid and moves
     * the timestamp, so the uuid is the holder's identity and the timestamp is
     * only the age.
     *
     * @param string $token Token or stored value.
     * @return string|false False for anything that is not shaped like a token.
     */
    protected static function identity($token)
    {
        if (!is_string($token) || '' === $token) {
            return false;
        }

        $at = strpos($token, ':');
        if (false === $at) {
            return false;
        }

        // The same shape is_stale() insists on. A value we did not write has no
        // identity at all, so it can never be mistaken for ours, and it is left
        // to the compare and swap in acquire() to heal rather than being
        // released by whoever happens to hold a token ending the same way.
        $stamp = substr($token, 0, $at);
        if ('' === $stamp || !ctype_digit($stamp)) {
            return false;
        }

        $identity = substr($token, $at + 1);

        return ('' === $identity) ? false : $identity;
    }

    /**
     * Whether two token values were issued to the same holder.
     *
     * A value we did not write has no identity, so it never matches anything,
     * including another value we did not write.
     *
     * @param string $a First value.
     * @param string $b Second value.
     * @return bool
     */
    protected static function same_identity($a, $b)
    {
        $ia = static::identity($a);
        $ib = static::identity($b);

        return (false !== $ia) && (false !== $ib) && ($ia === $ib);
    }

    /**
     * Extend the lease without giving up the lock.
     *
     * A full generation on a large site can outrun STALE_AFTER, and a lock that
     * expires under its own holder is worse than no lock at all: the second
     * process starts appending to the same temp file. Long jobs should call
     * this periodically and keep the token it returns.
     *
     * The value stored in the row changes on every refresh, so a job that
     * refreshes must give the lock back with release_identity() rather than
     * release(), and ask owns() rather than comparing tokens itself.
     *
     * @param string $lock_name Option name previously acquired.
     * @param string $token     Token returned by acquire().
     * @return string|false The refreshed token, or false if the lock is no longer ours.
     */
    public static function refresh($lock_name, $token)
    {
        global $wpdb;

        if (!is_string($token) || '' === $token) {
            return false;
        }

        $suffix = strpos($token, ':');
        if (false === $suffix) {
            return false;
        }
        // Same identity, new timestamp, so a refresh cannot be mistaken for a
        // different holder taking over.
        $new_token = time() . substr($token, $suffix);

        if ($new_token === $token) {
            // Same second, so the row already says what we want it to say.
            return $token;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $new_token,
            $lock_name,
            $token
        ));
        $refreshed = (1 === $wpdb->rows_affected);

        static::forget_cached_option($lock_name, true);

        return $refreshed ? $new_token : false;
    }

    /**
     * Give the lock up.
     *
     * The token has to match. A process whose lock was stolen while it was
     * stalled must not be able to delete the row the new holder is relying on.
     *
     * @param string $lock_name Option name previously acquired.
     * @param string $token     Token returned by acquire().
     * @return bool True if this call released the lock.
     */
    public static function release($lock_name, $token)
    {
        global $wpdb;

        if (!is_string($token) || '' === $token) {
            return false;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $lock_name,
            $token
        ));
        $released = (1 === $wpdb->rows_affected);

        // Only claim the row is gone when we are the ones who deleted it. If the
        // delete matched nothing, someone else's row may still be there, and
        // saying otherwise would make get_option() report a free lock that is
        // held. Being wrong in the other direction only costs a query.
        static::forget_cached_option($lock_name, !$released);

        return $released;
    }

    /**
     * The token currently stored for a lock, or null when it is free.
     *
     * Read straight from the table. get_option() answers from the object cache,
     * which our own direct writes never populate, so it can report a lock that
     * was released minutes ago.
     *
     * get_row() rather than get_var(). wpdb::get_var() returns null both when
     * there is no row and when the row holds an empty string, and the two mean
     * opposite things here: a lock that stores '' would read as free, so
     * INSERT IGNORE could never claim it and the staleness path that exists to
     * make sure nothing can wedge generation permanently would never run.
     *
     * @param string $lock_name Option name.
     * @return string|null Null only when no row exists.
     */
    public static function held_token($lock_name)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $lock_name
        ));

        return (null === $row) ? null : (string) $row->option_value;
    }

    /**
     * Drop every cached answer about this option.
     *
     * wp_cache_delete() alone is not enough. get_option() records a miss in the
     * 'notoptions' bucket, and a bucket entry written before the lock was
     * claimed survives the delete, so with a persistent object cache
     * get_option() keeps reporting no lock while one is genuinely held. Core's
     * own add_option() and delete_option() maintain that bucket for exactly this
     * reason, and anything writing to the options table by hand has to do the
     * same.
     *
     * Nothing in this class reads through get_option(), so this is here for the
     * callers around it rather than for the lock itself.
     *
     * @param string $lock_name Option name.
     * @param bool   $exists    Whether a row is known to exist after the write.
     * @return void
     */
    protected static function forget_cached_option($lock_name, $exists)
    {
        wp_cache_delete($lock_name, 'options');

        $notoptions = wp_cache_get('notoptions', 'options');
        if (!is_array($notoptions)) {
            $notoptions = array();
        }

        if ($exists) {
            unset($notoptions[$lock_name]);
        } else {
            $notoptions[$lock_name] = true;
        }

        wp_cache_set('notoptions', $notoptions, 'options');
    }

    /**
     * Build a token: the time it was issued, then something unique to this call.
     *
     * The timestamp is what makes the steal threshold readable without a second
     * option to keep in step. wp_generate_uuid4() is used rather than
     * wp_generate_password() because it is not pluggable, so no other plugin can
     * redefine it into something that repeats.
     *
     * @return string
     */
    protected static function make_token()
    {
        return time() . ':' . wp_generate_uuid4();
    }

    /**
     * Whether a stored token is old enough to take.
     *
     * Recovery after a holder is killed takes up to $stale_after seconds when
     * the two clocks agree, and up to **2 x $stale_after** at the far edge of the
     * skew the future-stamp branch below tolerates. A row stamped $stale_after
     * seconds ahead of us is not stale (measured: now+300 is held, now+301 is
     * stolen), and it does not become stale until it is $stale_after behind us,
     * which is another $stale_after later. That is the price of not stealing a
     * lease a slightly faster node has just taken, and it is bounded rather than
     * permanent, which is the property that matters: nothing wedges for good.
     * With the shipped 300 s that is a worst case of 600 s, and it needs a node
     * a full five minutes ahead to reach it.
     *
     * @param string $token       Value read from the options table.
     * @param int    $stale_after Seconds.
     * @return bool
     */
    protected static function is_stale($token, $stale_after)
    {
        $parts = explode(':', $token, 2);

        // A value we did not write carries no age we can read. Treating it as
        // stale lets a corrupted row heal itself instead of blocking generation
        // for ever, and the swap that follows is still a compare and swap, so
        // only one caller can take it. The empty string lands here too, which is
        // what makes an empty lock row recoverable rather than permanent.
        if (2 !== count($parts) || '' === $parts[0] || !ctype_digit($parts[0])) {
            return true;
        }

        $stamped = (int) $parts[0];

        // A timestamp in the future never ages, so a row carrying one would
        // block the job for ever. A clock set forward and later corrected is the
        // realistic route in. Treat it as stale: the compare and swap that
        // follows still hands the row to exactly one caller, so healing it
        // cannot let two callers in at once.
        //
        // Only once it is further ahead than the threshold, though. Two web
        // servers sharing one database do not share a clock, and a few seconds
        // of skew is ordinary, so treating any future stamp as stale means a
        // lock the server ahead has just taken reads as abandoned to the server
        // behind and is stolen on the spot. That is the concurrency this class
        // exists to prevent, and it is a likelier event than a clock set
        // forward. Inside the threshold the age comparison below answers it:
        // the age is negative, so the lease reads as fresh, which is what a
        // lock taken moments ago on another node is.
        if ($stamped > (time() + $stale_after)) {
            return true;
        }

        return (time() - $stamped) > $stale_after;
    }
}
