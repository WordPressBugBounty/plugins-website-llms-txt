<?php
if (!defined('ABSPATH')) {
    exit;
}

/*
 * Every card on this screen is its own form, and each one posts the whole
 * llms_generator_settings option, so a form has to re-submit the settings it
 * does not itself edit as hidden inputs or saving one card would wipe the
 * others.
 *
 * The carry is written by key at every depth. Writing an array member as
 * "[post_name][]" drops the key, and post_name is a map of post type label to
 * the custom name shown in the file, so an owner who renamed a custom post
 * type lost that name on every save of any other card. Recursing also means a
 * setting nested more than two deep is carried rather than turned into the
 * string "Array".
 */
if (!function_exists('llms_render_settings_carry')) {
    function llms_render_settings_carry($settings, $skip = array(), $prefix = 'llms_generator_settings')
    {
        if (!is_array($settings)) {
            return;
        }
        foreach ($settings as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $name = $prefix . '[' . $key . ']';
            if (is_array($value)) {
                llms_render_settings_carry($value, array(), $name);
                continue;
            }
            if (is_object($value) || is_null($value)) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '';
            }
            printf(
                '<input type="hidden" name="%s" value="%s"/>',
                esc_attr($name),
                esc_attr((string) $value)
            );
        }
    }
}

global $wpdb;
$table = $wpdb->prefix . 'llms_txt_cache';

$latest_post = apply_filters('get_llms_content', '');
$settings = apply_filters('get_llms_generator_settings', []);

// Visibility Kit state
$vk_connected = !empty(get_option('vk_embed_token'));
$vk_email = get_option('vk_connected_email', '');
$vk_domain = wp_parse_url(home_url(), PHP_URL_HOST);

// Fetch Visibility Kit summary data (connected only)
$vk_summary = null;
if ($vk_connected) {
    $cached = get_option('vk_summary_data', null);
    $fresh = get_transient('vk_summary_fresh');

    if ($fresh && $cached) {
        $vk_summary = $cached;
    } else {
        $embed_token = get_option('vk_embed_token');
        $response = wp_remote_get('https://api.visibilitykit.ai/api/v1/plugin/summary', [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $embed_token,
            ],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($body['success']) && !empty($body['data'])) {
                $vk_summary = $body['data'];
                update_option('vk_summary_data', $vk_summary);
                set_transient('vk_summary_fresh', 1, HOUR_IN_SECONDS);
            } else {
                $vk_summary = $cached;
            }
        } else {
            $vk_summary = $cached;
        }
    }
}

// Verify cache cleared nonce and display message
if (isset($_GET['cache_cleared']) && $_GET['cache_cleared'] === 'true' &&
    isset($_GET['_wpnonce'])) {
    $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
    if (wp_verify_nonce($nonce, 'llms_cache_cleared')) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Caches cleared and llms.txt regenerated.', 'website-llms-txt') . '</p></div>';
    }
}

// A scheduled llms.txt event whose due time is well in the past means WP-Cron
// is not firing on this site, so automatic updates will never happen. Surface
// that instead of letting them fail silently.
$llms_cron_overdue = false;
foreach (array('llms_update_llms_file_cron', 'llms_scheduled_update') as $llms_cron_hook) {
    $llms_cron_next = wp_next_scheduled($llms_cron_hook);
    if ($llms_cron_next && $llms_cron_next < time() - 15 * MINUTE_IN_SECONDS) {
        $llms_cron_overdue = true;
        break;
    }
}
if ($llms_cron_overdue) {
    echo '<div class="notice notice-warning"><p><strong>' . esc_html__('WP-Cron appears not to be running on this site.', 'website-llms-txt') . '</strong> ' .
        esc_html__('A scheduled llms.txt update is overdue, so automatic updates (after saving a post, and the daily/weekly schedule) will not happen until cron is working. The buttons on this page still work. Ask your hosting provider about setting up a real cron job for wp-cron.php.', 'website-llms-txt') .
        '</p></div>';
}

// Verify settings updated nonce and display message
if (isset($_GET['settings-updated']) &&
    isset($_GET['_wpnonce'])) {
    $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
    if (wp_verify_nonce($nonce, 'llms_options_update')) {
        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved successfully!', 'website-llms-txt') . '</p></div>';
    }
}

// VK announcement banner now renders via admin_notices across wp-admin (see LLMS_Core::render_vk_banner).

// ---------------------------------------------------------------------------
// Excluded content, spec 3.9.
//
// Two different things, kept apart on purpose. The RECORD is what the last
// generation actually left out, snapshotted by LLMS_Core::record_excluded_summary()
// and stored in llms_last_excluded. The LIVE read is what would be left out if the
// file were generated now. They disagree whenever a plugin has been activated or
// deactivated since, and the disagreement is exactly what somebody looking at this
// screen needs to see.
// ---------------------------------------------------------------------------
$llms_excluded = get_option(LLMS_Core::EXCLUDED_OPTION);
$llms_excluded = is_array($llms_excluded) ? $llms_excluded : array();
$llms_rows = LLMS_Core::excluded_summary_rows($llms_excluded);

$llms_live_types = array();
$llms_live_site_gated = false;
if (class_exists('LLMS_Access')) {
    $llms_live_types = LLMS_Access::gated_post_types();
    $llms_live_site_gated = LLMS_Access::site_is_gated();
}

// Group the live post types by the reason they carry, so a site running one
// plugin gets one line naming it rather than one line per post type.
$llms_live_by_reason = array();
foreach ($llms_live_types as $llms_type => $llms_reason) {
    $llms_obj = get_post_type_object($llms_type);
    $llms_live_by_reason[$llms_reason][] = ($llms_obj && isset($llms_obj->labels->name)) ? $llms_obj->labels->name : $llms_type;
}

$llms_precise_on = LLMS_Core::precise_access_enabled();

// When the file was last generated to completion. Read once here because the
// Excluded content card needs it as well as the File Status card: the record
// above is written by a generation ATTEMPT and llms_last_generated is only
// advanced by an attempt that finished, so an attempt that died after the read
// pass leaves a summary describing a document that was never written.
$llms_last_generated = (int) get_option('llms_last_generated');
$llms_record_time = isset($llms_excluded['time']) ? (int) $llms_excluded['time'] : 0;
$llms_record_unfinished = ($llms_record_time > 0 && $llms_record_time > $llms_last_generated);

// ---------------------------------------------------------------------------
// The schema condition, spec 3.1 and the round 3 verification of it.
//
// The record is RE-CHECKED rather than trusted, and there are two separate
// reasons for that.
//
// A failed baseline replay writes llms_db_condition and the very next ordinary
// request deletes it again while the schema stays incomplete, so a screen that
// only rendered the record would show nothing on exactly the install that needs
// it.
//
// And the recorded version can be right while the schema is not. A step that
// records its version and whose ALTER did not take leaves llms_db_version equal
// to LLMS_DB_VERSION over a table that is missing a column or an index, which no
// version comparison can see. LLMS_DB::schema_status() answers that by looking at
// the table, and it is called here and nowhere else because it costs SHOW COLUMNS
// and SHOW INDEX. The card still works without it, on the two checks below, so
// this screen does not depend on that method existing.
// ---------------------------------------------------------------------------
$llms_db_condition = get_option('llms_db_condition');
$llms_db_condition = is_array($llms_db_condition) ? $llms_db_condition : array();
$llms_db_installed = class_exists('LLMS_DB') ? LLMS_DB::installed_version() : 0;
$llms_db_target = defined('LLMS_DB_VERSION') ? (int) LLMS_DB_VERSION : 0;
$llms_table_missing = !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
$llms_db_behind = ($llms_db_target > 0 && $llms_db_installed < $llms_db_target);

$llms_schema_incomplete = false;
$llms_schema_missing = array();
if (!$llms_table_missing && class_exists('LLMS_DB') && method_exists('LLMS_DB', 'schema_status')) {
    $llms_schema = LLMS_DB::schema_status();
    if (is_array($llms_schema)) {
        if (isset($llms_schema['version'])) {
            $llms_db_installed = (int) $llms_schema['version'];
        }
        if (isset($llms_schema['target']) && (int) $llms_schema['target'] > 0) {
            $llms_db_target = (int) $llms_schema['target'];
        }
        $llms_db_behind = ($llms_db_target > 0 && $llms_db_installed < $llms_db_target);
        $llms_schema_incomplete = isset($llms_schema['complete']) && !$llms_schema['complete'];
        if (!empty($llms_schema['missing']) && is_array($llms_schema['missing'])) {
            foreach ($llms_schema['missing'] as $llms_gap) {
                if (is_scalar($llms_gap)) {
                    $llms_schema_missing[] = (string) $llms_gap;
                }
            }
        }
    }
}

$llms_db_trouble = ($llms_table_missing || $llms_db_behind || $llms_schema_incomplete || !empty($llms_db_condition));
?>

<div class="wrap">
    <h1><?php esc_html_e('Website llms.txt', 'website-llms-txt'); ?></h1>
    <div class="card-wrap">

        <!-- ========== LEFT COLUMN ========== -->
        <div class="card-column">

            <?php if ($llms_db_trouble): ?>
            <!-- Database update, rendered only when there is something wrong -->
            <div class="card llms-condition-card">
                <h2><?php esc_html_e('Database update', 'website-llms-txt'); ?></h2>
                <?php if ($llms_table_missing): ?>
                    <p><?php esc_html_e('The table this plugin stores its content in is missing, so the file cannot be generated.', 'website-llms-txt'); ?></p>
                <?php elseif ($llms_schema_incomplete && !$llms_db_behind): ?>
                    <p><?php esc_html_e('The plugin\'s database update reports that it finished, but part of the table it should have changed is not there. The file may be missing content or may not update.', 'website-llms-txt'); ?></p>
                <?php elseif ($llms_db_behind): ?>
                    <p><?php esc_html_e('The plugin\'s database update did not finish, so the file may be missing content or may not update.', 'website-llms-txt'); ?></p>
                <?php else: ?>
                    <p><?php esc_html_e('The plugin recorded a problem with its database update.', 'website-llms-txt'); ?></p>
                <?php endif; ?>
                <ul class="llms-bullet-list">
                    <li>
                        <?php
                        /* translators: 1: schema version this install is at, 2: schema version this plugin version expects */
                        printf(esc_html__('Database version %1$s of %2$s.', 'website-llms-txt'), esc_html($llms_db_installed), esc_html($llms_db_target));
                        ?>
                    </li>
                    <?php foreach ($llms_schema_missing as $llms_gap): ?>
                        <li>
                            <?php
                            /* translators: %s: name of a database column or index that is absent */
                            printf(esc_html__('Missing from the table: %s.', 'website-llms-txt'), esc_html($llms_gap));
                            ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!empty($llms_db_condition['code'])): ?>
                        <li>
                            <?php
                            /* translators: 1: machine-readable condition code, 2: migration step number */
                            printf(esc_html__('Condition %1$s at step %2$s.', 'website-llms-txt'), '<code>' . esc_html($llms_db_condition['code']) . '</code>', esc_html(isset($llms_db_condition['step']) ? $llms_db_condition['step'] : '?'));
                            ?>
                        </li>
                        <?php if (!empty($llms_db_condition['updated'])): ?>
                            <li>
                                <?php
                                /* translators: %s: date and time the condition was last seen */
                                printf(esc_html__('Last seen %s.', 'website-llms-txt'), esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $llms_db_condition['updated'])));
                                ?>
                            </li>
                        <?php endif; ?>
                        <?php if (!empty($llms_db_condition['detail'])): ?>
                            <li><?php echo esc_html($llms_db_condition['detail']); ?></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <p><?php esc_html_e('Deactivating and reactivating the plugin runs the update again. If it comes back, send this card to support.', 'website-llms-txt'); ?></p>
            </div>
            <?php endif; ?>

            <!-- File Status -->
            <div class="card">
                <h2><?php esc_html_e('File Status', 'website-llms-txt'); ?></h2>
                <?php if ($latest_post): ?>
                    <p><?php esc_html_e('File is being auto-generated based on your settings.', 'website-llms-txt'); ?></p>
                    <?php if ($llms_last_generated): ?>
                        <p>
                            <?php
                            /* translators: %s: date and time the llms.txt file was last generated */
                            printf(esc_html__('Last generated: %s', 'website-llms-txt'), '<strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $llms_last_generated)) . '</strong>');
                            ?>
                        </p>
                    <?php endif; ?>
                    <p><?php esc_html_e('View files:', 'website-llms-txt'); ?></p>
                    <ul>
                        <li><a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank"><?php echo esc_url(home_url('/llms.txt')); ?></a></li>
                        <?php if(isset($settings['llms_allow_indexing']) && $settings['llms_allow_indexing']): ?>
                            <?php if (class_exists('RankMath') || (defined('WPSEO_VERSION') && class_exists('WPSEO_Sitemaps'))): ?>
                                <li><a href="<?php echo esc_url(home_url('/sitemap_index.xml')); ?>" target="_blank"><?php echo esc_url(home_url('/sitemap_index.xml')); ?></a></li>
                                <li><a href="<?php echo esc_url(home_url('/llms-sitemap.xml')); ?>" target="_blank"><?php echo esc_url(home_url('/llms-sitemap.xml')); ?></a></li>
                            <?php endif; ?>
                        <?php endif; ?>
                    </ul>
                <?php else: ?>
                    <p style="color: red;">&#10007; <?php esc_html_e('No LLMS.txt file found in root directory', 'website-llms-txt'); ?></p>
                <?php endif; ?>
                <?php $generate_url = wp_nonce_url(admin_url('admin-post.php?action=run_manual_update_llms_file'), 'generate_llms_txt_nonce'); ?>
                <a href="<?php echo esc_url($generate_url); ?>" class="button button-primary" id="llms-generate-now"><?php esc_html_e('Generate now', 'website-llms-txt'); ?></a>
                <div id="llms-progress" style="display:none;margin-top:12px;max-width:560px">
                    <div style="height:12px;background:#eef2f7;border-radius:8px;overflow:hidden">
                        <div id="llms-progress-bar" style="height:12px;width:0;background:#0ea5e9"></div>
                    </div>
                    <div id="llms-progress-text" style="margin-top:8px;font-weight:600">0%</div>
                </div>
            </div>

            <!-- Excluded content -->
            <div class="card">
                <h2><?php esc_html_e('Excluded content', 'website-llms-txt'); ?></h2>

                <?php if ($llms_live_site_gated): ?>
                    <p><?php esc_html_e('Everything on this site is currently being left out of llms.txt, because an anonymous visitor cannot read any of it.', 'website-llms-txt'); ?></p>
                <?php elseif ($llms_live_by_reason): ?>
                    <p><?php esc_html_e('If the file were generated now, these post types would be left out.', 'website-llms-txt'); ?></p>
                    <ul class="llms-bullet-list">
                        <?php foreach ($llms_live_by_reason as $llms_reason => $llms_labels): ?>
                            <li>
                                <strong><?php echo esc_html(implode(', ', $llms_labels)); ?></strong>.
                                <?php echo esc_html($llms_reason); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif (!$llms_precise_on): ?>
                    <p><?php esc_html_e('Nothing is being left out of llms.txt beyond content an anonymous visitor cannot read, which is drafts, password protected posts and anything you have excluded yourself.', 'website-llms-txt'); ?></p>
                <?php endif; ?>

                <?php if ($llms_precise_on): ?>
                    <?php
                    /*
                     * With per post evaluation on, the post type list is not the whole
                     * answer. The readers decide one post at a time, and nothing short of
                     * running them over the whole site knows what that comes to, which is
                     * what the record below is. Saying "nothing is being left out" here
                     * would be a claim this screen cannot support.
                     */
                    ?>
                    <p><?php esc_html_e('Per post evaluation is on, so each post is judged on its own restriction data and what that came to is only known from the last time the file was generated.', 'website-llms-txt'); ?></p>
                <?php endif; ?>

                <?php if (empty($llms_excluded['time'])): ?>
                    <p><?php esc_html_e('The file has not been generated since this version was installed, so there is nothing recorded yet.', 'website-llms-txt'); ?></p>
                <?php else: ?>
                    <?php
                    /*
                     * The record is written by a generation ATTEMPT, on shutdown, and
                     * llms_last_generated is only advanced by an attempt that reached the
                     * end. A run that stopped after the read pass therefore leaves a
                     * summary of a document that was never written, and describing it as
                     * "what was left out of the file" would be describing a file the site
                     * is not serving. Say which one this is.
                     */
                    ?>
                    <?php if ($llms_record_unfinished): ?>
                        <p>
                            <?php
                            /* translators: %s: date and time of the generation attempt */
                            printf(esc_html__('A generation started on %s and did not finish, so this is what that attempt had left out. It did not replace the file.', 'website-llms-txt'), '<strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $llms_record_time)) . '</strong>');
                            ?>
                        </p>
                        <?php if ($llms_last_generated): ?>
                            <p>
                                <?php
                                /* translators: %s: date and time the llms.txt file was last generated in full */
                                printf(esc_html__('The file being served is the one generated on %s.', 'website-llms-txt'), '<strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $llms_last_generated)) . '</strong>');
                                ?>
                            </p>
                        <?php else: ?>
                            <p><?php esc_html_e('No generation has finished on this site yet, so there is no file to compare this against.', 'website-llms-txt'); ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>
                            <?php
                            /* translators: %s: date and time the llms.txt file was last generated */
                            printf(esc_html__('When the file was generated on %s, this is what was left out of it.', 'website-llms-txt'), '<strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $llms_record_time)) . '</strong>');
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!$llms_rows['types'] && !$llms_rows['included'] && !$llms_rows['notes']): ?>
                        <p><?php esc_html_e('That run left nothing out for a reason this plugin records. Content an anonymous visitor cannot read is never in the file and is not counted here.', 'website-llms-txt'); ?></p>
                    <?php endif; ?>

                    <?php if ($llms_rows['types']): ?>
                        <table class="llms-excluded-table">
                            <thead>
                                <tr>
                                    <th scope="col"><?php esc_html_e('Post type', 'website-llms-txt'); ?></th>
                                    <th scope="col"><?php esc_html_e('Left out', 'website-llms-txt'); ?></th>
                                    <th scope="col"><?php esc_html_e('Cause', 'website-llms-txt'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($llms_rows['types'] as $llms_row): ?>
                                    <tr>
                                        <td><?php echo esc_html($llms_row['label']); ?></td>
                                        <td><?php echo esc_html($llms_row['count']); ?></td>
                                        <td><?php echo esc_html($llms_row['detail']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if ($llms_rows['included']): ?>
                        <p>
                            <?php
                            $llms_included_bits = array();
                            foreach ($llms_rows['included'] as $llms_type => $llms_count) {
                                $llms_obj = get_post_type_object($llms_type);
                                $llms_name = $llms_type;
                                if ($llms_obj) {
                                    $llms_name = (1 === (int) $llms_count && !empty($llms_obj->labels->singular_name))
                                        ? $llms_obj->labels->singular_name
                                        : $llms_obj->labels->name;
                                }
                                $llms_included_bits[] = (int) $llms_count . ' ' . $llms_name;
                            }
                            /* translators: %s: list of counts and post types, e.g. "3 Posts, 1 Page" */
                            printf(esc_html__('The include setting in the editor kept %s in the file.', 'website-llms-txt'), '<strong>' . esc_html(implode(', ', $llms_included_bits)) . '</strong>');
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($llms_rows['notes']): ?>
                        <ul class="llms-bullet-list">
                            <?php foreach ($llms_rows['notes'] as $llms_note): ?>
                                <li>
                                    <?php echo esc_html($llms_note['detail']); ?>
                                    <?php if ($llms_note['count'] > 1): ?>
                                        <?php
                                        /* translators: %s: number of entries this reason applied to */
                                        printf(esc_html__('(%s entries)', 'website-llms-txt'), esc_html($llms_note['count']));
                                        ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($llms_excluded['truncated'])): ?>
                        <p><?php esc_html_e('There were more reasons than this record keeps. The counts above are complete for the reasons shown.', 'website-llms-txt'); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($llms_excluded['precise']) !== $llms_precise_on): ?>
                        <p><strong><?php esc_html_e('Per post evaluation has been changed since this file was generated, so what is above is not what you would get now.', 'website-llms-txt'); ?></strong></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Per post evaluation -->
            <div class="card">
                <h2><?php esc_html_e('Per post evaluation', 'website-llms-txt'); ?></h2>
                <p><?php esc_html_e('While an access control plugin is active, your posts and pages are left out of llms.txt, because there is no reliable way to tell from the database which of them a visitor can read.', 'website-llms-txt'); ?></p>
                <p><?php esc_html_e('This setting hands that judgement to the plugin. It reads each access control plugin\'s own restriction data and decides for itself, post by post, which content is public. Where it decides wrong, restricted content is published in a file anyone can read. Several access control plugins store nothing at all on a restricted post, and some of them decide at the moment a visitor asks, in a way no stored data shows.', 'website-llms-txt'); ?></p>
                <form method="post" action="options.php" id="llms-settings-access-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="llms_generator_settings[precise_access]" value="1" <?php checked($llms_precise_on); ?> />
                            <strong><?php esc_html_e('Let the plugin decide for itself which restricted content is public, accepting that a wrong decision publishes content that should not be published', 'website-llms-txt'); ?></strong>
                        </label>
                    </p>
                    <p><?php esc_html_e('The other way to get a specific post back into the file is the include setting on that post in the editor, which leaves the judgement with you.', 'website-llms-txt'); ?></p>
                    <?php llms_render_settings_carry($settings, array('precise_access')); ?>
                    <?php submit_button(esc_html__('Save settings', 'website-llms-txt')); ?>
                </form>
            </div>

            <!-- Content Settings -->
            <div class="card">
                <h2><?php esc_html_e('Content Settings', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-form">
                    <?php
                    settings_fields('llms_generator_settings');
                    $settings = apply_filters('get_llms_generator_settings', []);
                    ?>
                    <h3><?php esc_html_e('Post Types', 'website-llms-txt'); ?></h3>
                    <p class="description"><?php esc_html_e('Select and order the post types to include in your llms.txt file. Drag to reorder.', 'website-llms-txt'); ?></p>
                    <div id="llms-post-types-sortable" class="sortable-list">
                        <?php
                        $post_types = get_post_types(array('public' => true), 'objects');
                        $ordered_types = array_flip($settings['post_types']);
                        $unordered_types = array();
                        // Every label that gets a text input below. The hidden carry at the
                        // end of this form uses it to re-submit the custom names belonging to
                        // post types that are not currently registered, which is what a site
                        // has while the plugin providing a post type is deactivated.
                        $llms_named_labels = array();
                        foreach ($post_types as $post_type) {
                            if (in_array($post_type->name, array('attachment', 'llms_txt'))) continue;
                            if (!isset($ordered_types[$post_type->name])) {
                                $unordered_types[] = $post_type;
                            }
                        }
                        foreach ($settings['post_types'] as $type_name) {
                            if (isset($post_types[$type_name])) {
                                $post_type = $post_types[$type_name];
                                $all_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type->name));
                                $indexed_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE type = %s", $post_type->name));
                                $llms_named_labels[] = $post_type->labels->name;
                                ?>
                                <div class="sortable-item active" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                    <label>
                                        <input type="checkbox" name="llms_generator_settings[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" checked>
                                        <input type="text" name="llms_generator_settings[post_name][<?php echo esc_attr($post_type->labels->name); ?>]" value="<?php echo esc_attr( $settings['post_name'][$post_type->labels->name] ?? '' ); ?>"/>
                                        <span class="dashicons dashicons-menu"></span>
                                        <?php echo esc_html($post_type->labels->name); ?>
                                        <small style="opacity: 0.7;">(<?php echo intval($indexed_count) . ' indexed of ' . intval($all_count); ?>)</small>
                                    </label>
                                </div>
                                <?php
                            }
                        }
                        foreach ($unordered_types as $post_type) {
                            $all_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type->name));
                            $indexed_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE type = %s", $post_type->name));
                            $llms_named_labels[] = $post_type->labels->name;
                            ?>
                            <div class="sortable-item" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                <label>
                                    <input type="checkbox" name="llms_generator_settings[post_types][]" value="<?php echo esc_attr($post_type->name); ?>"/>
                                    <input type="text" name="llms_generator_settings[post_name][<?php echo esc_attr($post_type->labels->name); ?>]" value="<?php echo esc_attr( $settings['post_name'][$post_type->labels->name] ?? '' ); ?>"/>
                                    <span class="dashicons dashicons-menu"></span>
                                    <?php echo esc_html($post_type->labels->name); ?>
                                    <small style="opacity: 0.7;">(<?php echo intval($indexed_count) . ' indexed of ' . intval($all_count); ?>)</small>
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    <p><label><?php esc_html_e('Maximum posts per type:', 'website-llms-txt'); ?> <input type="number" name="llms_generator_settings[max_posts]" value="<?php echo esc_attr($settings['max_posts']); ?>" min="1" max="100000"></label></p>
                    <p><label><?php esc_html_e('Maximum words:', 'website-llms-txt'); ?> <input type="number" name="llms_generator_settings[max_words]" value="<?php echo esc_attr($settings['max_words'] ?? 250); ?>" min="1" max="100000"></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[include_meta]" value="1" <?php checked(!empty($settings['include_meta'])); ?>> <?php esc_html_e('Include meta information (publish date, author, etc.)', 'website-llms-txt'); ?></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[include_excerpts]" value="1" <?php checked(!empty($settings['include_excerpts'])); ?>> <?php esc_html_e('Include post excerpts / meta descriptions', 'website-llms-txt'); ?></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[detailed_content]" value="1" <?php checked(!empty($settings['detailed_content'])); ?>> <?php esc_html_e('Include detailed content', 'website-llms-txt'); ?></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[include_taxonomies]" value="1" <?php checked(!empty($settings['include_taxonomies'])); ?>> <?php esc_html_e('Include taxonomies (categories, tags, etc.)', 'website-llms-txt'); ?></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[gform_include]" value="1" <?php checked(!empty($settings['gform_include'])); ?>> <?php esc_html_e('Include Gravity Forms form fields in llms.txt', 'website-llms-txt'); ?></label></p>
                    <?php
                    // post_name is edited by the text inputs above, so it is skipped here.
                    // Carrying the whole of it as well would submit the same setting twice and
                    // merge the hidden copy into the edited one. What is carried instead is the
                    // part of it the inputs above did not cover.
                    llms_render_settings_carry($settings, array(
                        'post_types', 'post_name', 'max_posts', 'max_words', 'include_meta',
                        'include_excerpts', 'detailed_content', 'include_taxonomies', 'gform_include',
                    ));
                    if (!empty($settings['post_name']) && is_array($settings['post_name'])) {
                        $llms_unnamed = array_diff_key($settings['post_name'], array_flip($llms_named_labels));
                        llms_render_settings_carry($llms_unnamed, array(), 'llms_generator_settings[post_name]');
                    }
                    ?>
                    <?php submit_button(esc_html__('Save settings', 'website-llms-txt')); ?>
                </form>
            </div>

            <!-- Custom LLMs.txt Content -->
            <div class="card">
                <h2><?php esc_html_e('Custom LLMS.txt Content', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-custom-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <p>
                        <label><b><?php esc_html_e('LLMS.txt Title', 'website-llms-txt'); ?></b></label><br/>
                        <textarea name="llms_generator_settings[llms_txt_title]" style="width: 100%;height: 40px;"><?php echo esc_textarea( isset($settings['llms_txt_title']) ? $settings['llms_txt_title'] : '' ); ?></textarea>
                        <i><?php esc_html_e('Set a custom title for your LLMs.txt file. This will appear at the top of the generated file before any listed URLs.', 'website-llms-txt'); ?></i>
                    </p>
                    <p>
                        <label><b><?php esc_html_e('LLMS.txt Description', 'website-llms-txt'); ?></b></label><br/>
                        <textarea name="llms_generator_settings[llms_txt_description]" style="width: 100%;height: 80px;"><?php echo esc_textarea( isset($settings['llms_txt_description']) ? $settings['llms_txt_description'] : '' ); ?></textarea>
                        <i><?php esc_html_e('Optional introduction text added before the list of URLs.', 'website-llms-txt'); ?></i>
                    </p>
                    <p>
                        <label><b><?php esc_html_e('LLMS.txt After Description', 'website-llms-txt'); ?></b></label><br/>
                        <textarea name="llms_generator_settings[llms_after_txt_description]" style="width: 100%;height: 80px;"><?php echo esc_textarea( isset($settings['llms_after_txt_description']) ? $settings['llms_after_txt_description'] : '' ); ?></textarea>
                        <i><?php esc_html_e('Optional text inserted right before the list of links or content entries.', 'website-llms-txt'); ?></i>
                    </p>
                    <p>
                        <label><b><?php esc_html_e('LLMS.txt End File Description', 'website-llms-txt'); ?></b></label><br/>
                        <textarea name="llms_generator_settings[llms_end_file_description]" style="width: 100%;height: 80px;"><?php echo esc_textarea( isset($settings['llms_end_file_description']) ? $settings['llms_end_file_description'] : '' ); ?></textarea>
                        <i><?php esc_html_e('Final text appended at the bottom of the LLMs.txt file (e.g. footer, contact, or disclaimer information).', 'website-llms-txt'); ?></i>
                    </p>
                    <?php
                    llms_render_settings_carry($settings, array(
                        'llms_txt_title', 'llms_txt_description',
                        'llms_after_txt_description', 'llms_end_file_description',
                    ));
                    ?>
                    <?php submit_button(esc_html__('Save settings', 'website-llms-txt')); ?>
                </form>
            </div>

            <!-- Advanced Settings -->
            <div class="card">
                <h2><?php esc_html_e('Advanced Settings', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-advanced-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <p><label><input type="checkbox" name="llms_generator_settings[include_md_file]" value="1" <?php checked(!empty($settings['include_md_file'])); ?> /> <?php esc_html_e('Turn on options at the page level admin with .md support and ability to not include individual pages', 'website-llms-txt'); ?></label></p>
                    <p><label><input type="checkbox" name="llms_generator_settings[noindex_header]" value="1" <?php checked(!empty($settings['noindex_header'])); ?> /> <?php esc_html_e('Disable "noindex" header for llms.txt', 'website-llms-txt'); ?></label></p>
                    <p>
                        <label>
                            <?php
                            /* translators: 1: opening <strong>, 2: closing </strong>, 3: opening <code>, 4: closing </code> */
                            printf(esc_html__('%1$sWarning:%2$s Including %3$sllms.txt%4$s in your sitemap may lead to it being crawled and indexed by search engines. If your file contains full post content, this could trigger duplicate content issues. Use only if you understand the SEO impact.', 'website-llms-txt'),'<strong>','</strong>','<code>','</code>');
                            ?><br/><br/>
                            <input type="checkbox" name="llms_generator_settings[llms_allow_indexing]" value="1" <?php checked(!empty($settings['llms_allow_indexing'])); ?> />
                            <?php
                            /* translators: 1: opening <strong>, 2: closing </strong>, 3: opening <code>, 4: closing </code> */
                            printf(esc_html__('%1$sI understand the SEO risks%2$s and want to include %3$sllms.txt%4$s in the sitemap', 'website-llms-txt'),'<strong>','</strong>','<code>','</code>');
                            ?>
                        </label>
                    </p>
                    <h3><?php esc_html_e('Update Frequency', 'website-llms-txt'); ?></h3>
                    <p>
                        <label>
                            <select name="llms_generator_settings[update_frequency]">
                                <option value="immediate" <?php selected($settings['update_frequency'], 'immediate'); ?>><?php esc_html_e('Immediate', 'website-llms-txt'); ?></option>
                                <option value="daily" <?php selected($settings['update_frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'website-llms-txt'); ?></option>
                                <option value="weekly" <?php selected($settings['update_frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'website-llms-txt'); ?></option>
                            </select>
                        </label>
                    </p>
                    <?php
                    llms_render_settings_carry($settings, array(
                        'include_md_file', 'noindex_header', 'llms_allow_indexing', 'update_frequency',
                    ));
                    ?>
                    <?php submit_button(esc_html__('Save settings', 'website-llms-txt')); ?>
                </form>
            </div>

            <!-- Cache Management -->
            <div class="card">
                <h2><?php esc_html_e('Cache Management', 'website-llms-txt'); ?></h2>
                <p><?php esc_html_e('This tool helps ensure your LLMS.txt file is properly reflected in your sitemap by:', 'website-llms-txt'); ?></p>
                <ul class="llms-bullet-list">
                    <li><?php esc_html_e('Clearing sitemap caches', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Resetting WordPress rewrite rules', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Forcing sitemap regeneration', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Triggering full site reindexing', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Generating LLMS.txt file', 'website-llms-txt'); ?></li>
                </ul>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="clear_caches">
                    <?php wp_nonce_field('clear_caches', 'clear_caches_nonce'); ?>
                    <p class="submit"><?php submit_button(esc_html__('Clear caches', 'website-llms-txt'), 'primary', 'submit', false); ?></p>
                </form>
            </div>

            <!-- LLMs.txt Reset -->
            <div class="card">
                <h2><?php esc_html_e('LLMs.txt Reset', 'website-llms-txt'); ?></h2>
                <p><?php esc_html_e('If your llms.txt file contains duplicate or outdated data, you can delete it and let the system generate a new one automatically.', 'website-llms-txt'); ?></p>
                <p><?php esc_html_e('When you click the button, the plugin will:', 'website-llms-txt'); ?></p>
                <ol class="llms-bullet-list">
                    <li><?php esc_html_e('Delete the current llms.txt file (if it exists).', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Clear any related transient cache entries.', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Rebuild a fresh version of llms.txt based on current settings and published content.', 'website-llms-txt'); ?></li>
                </ol>
                <?php $generate_url = wp_nonce_url(admin_url('admin-post.php?action=run_llms_txt_reset_file'), 'generate_llms_txt_nonce'); ?>
                <a href="<?php echo esc_url($generate_url); ?>" class="button button-primary" id="llms-delete-and-recreate"><?php esc_html_e('Delete and recreate', 'website-llms-txt'); ?></a>
                <div id="llms-reset-progress" style="display:none;margin-top:12px;max-width:560px">
                    <div style="height:12px;background:#eef2f7;border-radius:8px;overflow:hidden">
                        <div id="llms-reset-progress-bar" style="height:12px;width:0;background:#0ea5e9"></div>
                    </div>
                    <div id="llms-reset-progress-text" style="margin-top:8px;font-weight:600">0%</div>
                </div>
            </div>

            <?php if ($vk_connected): ?>
            <!-- Visibility Kit Connection -->
            <div class="card vk-tracking-settings-card">
                <h2><?php esc_html_e('Connection', 'website-llms-txt'); ?></h2>
                <div class="vk-disconnect-section">
                    <h3><?php esc_html_e('Disconnect from Visibility Kit', 'website-llms-txt'); ?></h3>
                    <p>
                        <?php esc_html_e('This will stop AI referral tracking and remove the tracking script from your site. Your dashboard data will still be available at visibilitykit.ai.', 'website-llms-txt'); ?>
                    </p>
                    <div class="vk-disconnect-btn-wrap">
                        <button type="button" id="vk-disconnect-btn" class="button button-secondary"><?php esc_html_e('Disconnect', 'website-llms-txt'); ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /left column -->

        <!-- ========== RIGHT COLUMN ========== -->
        <div class="card-column">

            <?php if (!$vk_connected): ?>
            <!-- ===== NOT CONNECTED ===== -->
            <div class="vk-gradient-border vk-pitch-card-border">
            <div class="card vk-pitch-card">
                <h2><?php esc_html_e('See which AI engines visit your site', 'website-llms-txt'); ?></h2>

                <p class="vk-status-not-active">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M8 1.5L1 14h14L8 1.5z" stroke="#d97706" stroke-width="1.5" fill="none"/><path d="M8 6v3.5" stroke="#d97706" stroke-width="1.5" stroke-linecap="round"/><circle cx="8" cy="11.5" r="0.75" fill="#d97706"/></svg>
                    <?php esc_html_e('AI referral tracking is not active', 'website-llms-txt'); ?>
                </p>

                <p style="color: #475569; font-size: 14px; line-height: 1.6;">
                    <?php esc_html_e('See referral traffic from AI search engines.', 'website-llms-txt'); ?>
                </p>

                <ul class="vk-feature-list">
                    <li><?php esc_html_e('See which AI platforms send you visitors', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Coverage across ChatGPT, Claude, Perplexity, Gemini', 'website-llms-txt'); ?></li>
                    <li><?php esc_html_e('Powered by Visibility Kit', 'website-llms-txt'); ?></li>
                </ul>

                <div class="vk-connect-inline">
                    <input type="email" id="vk-connect-email" placeholder="your@email.com" />
                    <button type="button" id="vk-connect-btn" class="button vk-btn-primary vk-connect-inline-btn"><?php esc_html_e('Start tracking', 'website-llms-txt'); ?></button>
                </div>
                <div id="vk-connect-status" style="display:none;"></div>
            </div>
            </div>

            <?php else: ?>
            <!-- ===== CONNECTED ===== -->
            <div class="card">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                    <h2 style="margin-bottom: 0;"><?php esc_html_e('AI Search Traffic', 'website-llms-txt'); ?></h2>
                    <span class="vk-connected-badge">&#10003; <?php esc_html_e('Connected', 'website-llms-txt'); ?></span>
                </div>

                <?php
                $big_four = [
                    ['key' => 'chatgpt',    'label' => __('ChatGPT', 'website-llms-txt')],
                    ['key' => 'claude',     'label' => __('Claude', 'website-llms-txt')],
                    ['key' => 'gemini',     'label' => __('Gemini', 'website-llms-txt')],
                    ['key' => 'perplexity', 'label' => __('Perplexity', 'website-llms-txt')],
                ];
                $counts_by_key = [];
                if (!empty($vk_summary['bySource']) && is_array($vk_summary['bySource'])) {
                    foreach ($vk_summary['bySource'] as $row) {
                        if (!empty($row['key'])) {
                            $counts_by_key[$row['key']] = (int) ($row['sessions'] ?? 0);
                        }
                    }
                }
                $total_ai_sessions = isset($vk_summary['aiReferralSessions']) ? (int) $vk_summary['aiReferralSessions'] : 0;
                $period_label = !empty($vk_summary['aiReferralSessionsLabel']) ? $vk_summary['aiReferralSessionsLabel'] : __('this week', 'website-llms-txt');
                ?>

                <div class="vk-stats-card">
                    <div class="vk-stats-number"><?php echo esc_html($total_ai_sessions); ?></div>
                    <div class="vk-stats-label"><?php echo esc_html__('AI referral sessions ', 'website-llms-txt') . esc_html($period_label); ?></div>
                </div>

                <ul class="vk-source-list">
                    <?php foreach ($big_four as $b): ?>
                        <li class="vk-source-row">
                            <span class="vk-source-name"><?php echo esc_html($b['label']); ?></span>
                            <span class="vk-source-count"><?php echo esc_html((int) ($counts_by_key[$b['key']] ?? 0)); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($total_ai_sessions === 0): ?>
                <p class="vk-no-data">
                    <?php esc_html_e('No AI referral sessions detected yet. Data collection starts from when you connected.', 'website-llms-txt'); ?>
                </p>
                <?php endif; ?>

                <p style="margin: 16px 0 12px;">
                    <a href="https://visibilitykit.ai" target="_blank" class="button vk-btn-primary vk-cta-btn">
                        <?php esc_html_e('How it works', 'website-llms-txt'); ?> &rarr;
                    </a>
                </p>

                <p style="font-size: 12px; color: #94a3b8; margin-bottom: 0;">
                    <?php echo esc_html__('Connected as: ', 'website-llms-txt') . esc_html($vk_email); ?>
                </p>
            </div>
            <?php endif; ?>

        </div><!-- /right column -->

    </div><!-- /card-wrap -->
</div><!-- /wrap -->
