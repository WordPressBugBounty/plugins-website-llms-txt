<?php
if (!defined('ABSPATH')) {
    exit;
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
        echo '<div class="notice notice-success"><p>' . esc_html__('Caches cleared successfully!', 'website-llms-txt') . '</p></div>';
    }
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
?>

<div class="wrap">
    <h1><?php esc_html_e('Website llms.txt', 'website-llms-txt'); ?></h1>
    <div class="card-wrap">

        <!-- ========== LEFT COLUMN ========== -->
        <div class="card-column">

            <!-- File Status -->
            <div class="card">
                <h2><?php esc_html_e('File Status', 'website-llms-txt'); ?></h2>
                <?php if ($latest_post): ?>
                    <p><?php esc_html_e('File is being auto-generated based on your settings.', 'website-llms-txt'); ?></p>
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
                                ?>
                                <div class="sortable-item active" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                    <label>
                                        <input type="checkbox" name="llms_generator_settings[post_types][]" value="<?php echo esc_attr($post_type->name); ?>" checked>
                                        <input type="text" name="llms_generator_settings[post_name][<?php echo esc_attr($post_type->labels->name); ?>]" value="<?php echo esc_attr( $settings['post_name'][esc_html($post_type->labels->name)] ?? '' ); ?>"/>
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
                            ?>
                            <div class="sortable-item" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                <label>
                                    <input type="checkbox" name="llms_generator_settings[post_types][]" value="<?php echo esc_attr($post_type->name); ?>"/>
                                    <input type="text" name="llms_generator_settings[post_name][<?php echo esc_attr($post_type->labels->name); ?>]" value="<?php echo esc_attr( $settings['post_name'][esc_html($post_type->labels->name)] ?? '' ); ?>"/>
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
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['post_types', 'max_posts', 'max_words', 'include_meta', 'include_excerpts', 'detailed_content', 'include_taxonomies', 'gform_include'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $second_value ); ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
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
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['llms_txt_title', 'llms_txt_description', 'llms_after_txt_description', 'llms_end_file_description'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $second_value ); ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
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
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['include_md_file', 'noindex_header', 'llms_allow_indexing', 'update_frequency'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $second_value ); ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
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

            <!-- Tracking Settings -->
            <div class="card vk-tracking-settings-card">
                <h2><?php esc_html_e('Tracking Settings', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-tracking-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <div class="vk-checkbox-row">
                        <label>
                            <input type="checkbox" name="llms_generator_settings[llms_local_log_enabled]" value="1" <?php checked(!empty($settings['llms_local_log_enabled'])); ?>>
                            <?php esc_html_e('AI bot crawl detection', 'website-llms-txt'); ?>
                        </label>
                        <p class="vk-checkbox-desc">
                            <?php esc_html_e('Server-side detection of AI bots crawling your site. Data is sent to Visibility Kit.', 'website-llms-txt'); ?>
                        </p>
                    </div>
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['llms_local_log_enabled'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $second_value ); ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
                    <?php submit_button(esc_html__('Save settings', 'website-llms-txt'), 'primary', 'submit', true); ?>
                </form>

                <?php if ($vk_connected): ?>
                <div class="vk-disconnect-section">
                    <h3><?php esc_html_e('Disconnect from Visibility Kit', 'website-llms-txt'); ?></h3>
                    <p>
                        <?php esc_html_e('This will stop AI referral tracking and remove the tracking script from your site. Your dashboard data will still be available at visibilitykit.ai.', 'website-llms-txt'); ?>
                    </p>
                    <div class="vk-disconnect-btn-wrap">
                        <button type="button" id="vk-disconnect-btn" class="button button-secondary"><?php esc_html_e('Disconnect', 'website-llms-txt'); ?></button>
                    </div>
                </div>
                <?php endif; ?>

                <p class="vk-caching-note">
                    <?php esc_html_e('Note: If your site uses full-page caching (WP Engine, Cloudflare, etc.), some bot visits may not be detected server-side.', 'website-llms-txt'); ?>
                </p>
            </div>

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
