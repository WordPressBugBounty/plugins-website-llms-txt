<?php
if (!defined('ABSPATH')) {
    exit;
}

$latest_post = apply_filters('get_llms_content', '');
$settings = apply_filters('get_llms_generator_settings', []);

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
?>

<div class="wrap">
    <h1><?php esc_html_e('Website llms.txt', 'website-llms-txt'); ?></h1>
    <div class="card-wrap">
        <div class="card-column">
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
                    <p style="color: red;">✗ <?php esc_html_e('No LLMS.txt file found in root directory', 'website-llms-txt'); ?></p>
                <?php endif; ?>
            </div>

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
                        $ordered_types = array_flip($settings['post_types']); // Create lookup array
                        $unordered_types = array(); // For types not in the current order

                        // Separate ordered and unordered post types
                        foreach ($post_types as $post_type) {
                            if (in_array($post_type->name, array('attachment', 'llms_txt'))) {
                                continue;
                            }

                            if (!isset($ordered_types[$post_type->name])) {
                                $unordered_types[] = $post_type;
                            }
                        }

                        // Output ordered items first
                        foreach ($settings['post_types'] as $type_name) {
                            if (isset($post_types[$type_name])) {
                                $post_type = $post_types[$type_name];
                                ?>
                                <div class="sortable-item active" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                    <label>
                                        <input type="checkbox"
                                               name="llms_generator_settings[post_types][]"
                                               value="<?php echo esc_attr($post_type->name); ?>"
                                               checked>
                                        <span class="dashicons dashicons-menu"></span>
                                        <?php echo esc_html($post_type->labels->name); ?>
                                    </label>
                                </div>
                                <?php
                            }
                        }

                        // Output unordered items
                        foreach ($unordered_types as $post_type) {
                            ?>
                            <div class="sortable-item" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                <label>
                                    <input type="checkbox"
                                           name="llms_generator_settings[post_types][]"
                                           value="<?php echo esc_attr($post_type->name); ?>">
                                    <span class="dashicons dashicons-menu"></span>
                                    <?php echo esc_html($post_type->labels->name); ?>
                                </label>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    <p>
                        <label>
                            <?php esc_html_e('Maximum posts per type:', 'website-llms-txt'); ?>
                            <input type="number"
                                   name="llms_generator_settings[max_posts]"
                                   value="<?php echo esc_attr($settings['max_posts']); ?>"
                                   min="1"
                                   max="100000">
                        </label>
                    </p>
                    <p>
                        <label>
                            <?php esc_html_e('Maximum words:', 'website-llms-txt'); ?>
                            <input type="number"
                                   name="llms_generator_settings[max_words]"
                                   value="<?php echo esc_attr($settings['max_words'] ?? 250); ?>"
                                   min="1"
                                   max="100000">
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   name="llms_generator_settings[include_meta]"
                                   value="1"
                                <?php checked(!empty($settings['include_meta'])); ?>>
                            <?php esc_html_e('Include meta information (publish date, author, etc.)', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   name="llms_generator_settings[include_excerpts]"
                                   value="1"
                                <?php checked(!empty($settings['include_excerpts'])); ?>>
                            <?php esc_html_e('Include post excerpts / meta descriptions', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   name="llms_generator_settings[detailed_content]"
                                   value="1"
                                <?php checked(!empty($settings['detailed_content'])); ?>>
                            <?php esc_html_e('Include detailed content', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox"
                                   name="llms_generator_settings[include_taxonomies]"
                                   value="1"
                                <?php checked(!empty($settings['include_taxonomies'])); ?>>
                            <?php esc_html_e('Include taxonomies (categories, tags, etc.)', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['post_types', 'max_posts', 'max_words', 'include_meta', 'include_excerpts', 'detailed_content', 'include_taxonomies'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?= $key ?>][]" value="<?= $second_value ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?= $key ?>]" value="<?= $value ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
                    <?php submit_button(esc_html__('Save Settings', 'website-llms-txt')); ?>
                </form>
            </div>
            <div class="card">
                <h2><?php esc_html_e('Advanced Settings', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-advanced-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="llms_generator_settings[include_md_file]" value="1" <?php checked( !empty($settings['include_md_file']) ); ?> />
                            <?php esc_html_e('Turn on options at the page level admin with .md support and ability to no include individual pages', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="llms_generator_settings[noindex_header]" value="1" <?php checked( !empty($settings['noindex_header']) ); ?> />
                            <?php esc_html_e('Disable “noindex” header for llms.txt', 'website-llms-txt'); ?>
                        </label>
                    </p>
                    <p>
                        <label>
                            <?php printf(esc_html__('%1$s⚠️ Warning:%2$s Including %3$sllms.txt%4$s in your sitemap may lead to it being crawled and indexed by search engines like Google. If your file contains full post content, this could trigger duplicate content issues or filtering in search results. Use only if you understand the SEO impact.', 'website-llms-txt'),'<strong>','</strong>','<code>','</code>'); ?><br/><br/>
                                <input
                                    type="checkbox"
                                    name="llms_generator_settings[llms_allow_indexing]"
                                    value="1"
                                    <?php checked(!empty($settings['llms_allow_indexing'])); ?>
                                />
                            <?php printf(esc_html__('%1$sI understand the SEO risks%2$s and want to include %3$sllms.txt%4$s in the sitemap', 'website-llms-txt'),'<strong>','</strong>','<code>','</code>'); ?>
                        </label>
                    </p>
                    <h3><?php esc_html_e('Update Frequency', 'website-llms-txt'); ?></h3>
                    <p>
                        <label>
                            <select name="llms_generator_settings[update_frequency]">
                                <option value="immediate" <?php selected($settings['update_frequency'], 'immediate'); ?>>
                                    <?php esc_html_e('Immediate', 'website-llms-txt'); ?>
                                </option>
                                <option value="daily" <?php selected($settings['update_frequency'], 'daily'); ?>>
                                    <?php esc_html_e('Daily', 'website-llms-txt'); ?>
                                </option>
                                <option value="weekly" <?php selected($settings['update_frequency'], 'weekly'); ?>>
                                    <?php esc_html_e('Weekly', 'website-llms-txt'); ?>
                                </option>
                            </select>
                        </label>
                    </p>
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['include_md_file', 'noindex_header', 'llms_allow_indexing', 'update_frequency'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?= $key ?>][]" value="<?= $second_value ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?= $key ?>]" value="<?= $value ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
                    <?php submit_button(esc_html__('Save Settings', 'website-llms-txt')); ?>
                </form>
            </div>
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
                    <p class="submit">
                        <?php submit_button(esc_html__('Clear Caches', 'website-llms-txt'), 'primary', 'submit', false); ?>
                    </p>
                </form>
            </div>
        </div>
        <div class="card-column">
            <div class="card">
                <h2><?php esc_html_e('Custom LLMS.txt Content', 'website-llms-txt'); ?></h2>
                <form method="post" action="options.php" id="llms-settings-custom-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <p>
                        <label>
                            <?php esc_html_e('LLMS.txt Title', 'website-llms-txt'); ?>
                        </label><br/>
                        <textarea name="llms_generator_settings[llms_txt_title]" style="width: 100%;height: 40px;"><?php echo (isset($settings['llms_txt_title']) ? $settings['llms_txt_title'] : '') ?></textarea>
                    </p>
                    <p>
                        <label>
                            <?php esc_html_e('LLMS.txt Description', 'website-llms-txt'); ?>
                        </label><br/>
                        <textarea name="llms_generator_settings[llms_txt_description]" style="width: 100%;height: 80px;"><?php echo (isset($settings['llms_txt_description']) ? $settings['llms_txt_description'] : '') ?></textarea>
                    </p>
                    <p>
                        <label>
                            <?php esc_html_e('LLMS.txt After Description', 'website-llms-txt'); ?>
                        </label><br/>
                        <textarea name="llms_generator_settings[llms_after_txt_description]" style="width: 100%;height: 80px;"><?php echo (isset($settings['llms_after_txt_description']) ? $settings['llms_after_txt_description'] : '') ?></textarea>
                    </p>
                    <p>
                        <label>
                            <?php esc_html_e('LLMS.txt End File Description', 'website-llms-txt'); ?>
                        </label><br/>
                        <textarea name="llms_generator_settings[llms_end_file_description]" style="width: 100%;height: 80px;"><?php echo (isset($settings['llms_end_file_description']) ? $settings['llms_end_file_description'] : '') ?></textarea>
                    </p>
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['llms_txt_title', 'llms_txt_description', 'llms_after_txt_description', 'llms_end_file_description'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?= $key ?>][]" value="<?= $second_value ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?= $key ?>]" value="<?= $value ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
                    <?php submit_button(esc_html__('Save Settings', 'website-llms-txt')); ?>
                </form>
            </div>
        </div>
        <?php
            $tab = filter_input(INPUT_GET,'tab');
        ?>
        <div class="card-column">
            <div class="card <?php echo $tab; ?>">
                <form method="post" action="options.php" id="llms-settings-crawler-form">
                    <?php settings_fields('llms_generator_settings'); ?>
                    <h2><?php esc_html_e('AI Crawler Detection','website-llms-txt') ?></h2>
                    <p><?php printf(esc_html__('Be the first to know if AI bots are reading your %1$sllms.txt%2$s file. Join the global experiment to track major AI crawlers (like GPTBot, ClaudeBot, and PerplexityBot) accessing %1$sllms.txt%2$s files across the web.','website-llms-txt'),'<code>','</code>') ?></p>
                    <p>
                        <label>
                            <input
                                type="checkbox"
                                name="llms_generator_settings[llms_local_log_enabled]"
                                value="1" <?php checked(!empty($settings['llms_local_log_enabled'])); ?>>
                            <?php esc_html_e('Log AI bot visits and contribute to the global experiment','website-llms-txt') ?>
                        </label>
                    </p>
                    <p style="font-size: 90%; max-width: 600px;">
                        <?php esc_html_e('All data is encrypted and anonymous. The data shared includes the bot name, timestamp, and a hashed version of your domain to track LLM crawler behavior across thousands of sites. No content or personal information is collected or stored.','website-llms-txt') ?>
                    </p>
                    <p>
                        <a href="https://www.ryanhoward.dev/p/are-ai-search-bots-actually-looking-at-llms-txt-files" target="_blank"><?php _e('Experiment details','website-llms-txt') ?></a> |
                        <a href="https://llmstxt.ryanhoward.dev" target="_blank"><?php esc_html_e('All websites data counter','website-llms-txt') ?></a>
                    </p>
                    <?php if(!empty($settings)): ?>
                        <?php foreach($settings as $key => $value): ?>
                            <?php if(in_array($key, ['llms_local_log_enabled'])) continue ?>
                            <?php if(is_array($value)): ?>
                                <?php foreach($value as $second_key => $second_value): ?>
                                    <input type="hidden" name="llms_generator_settings[<?= $key ?>][]" value="<?= $second_value ?>"/>
                                <?php endforeach ?>
                            <?php else: ?>
                                <input type="hidden" name="llms_generator_settings[<?= $key ?>]" value="<?= $value ?>"/>
                            <?php endif ?>
                        <?php endforeach ?>
                    <?php endif ?>
                    <?php submit_button(esc_html__('Save Settings', 'website-llms-txt')); ?>
                </form>
            </div>
            <?php if(isset($settings['llms_local_log_enabled']) && $settings['llms_local_log_enabled']): ?>
                <?php $entries = get_option('llms_local_log', []); ?>
                <div class="card">
                    <h3><?php esc_html_e('Recent AI Crawler Activity', 'website-llms-txt'); ?></h3>
                    <?php if ($entries) : ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Crawler','website-llms-txt') ?></th>
                                    <th><?php esc_html_e('Last Seen','website-llms-txt') ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($entries as $row) : ?>
                                <tr>
                                    <td><?php echo esc_html($row['bot']); ?></td>
                                    <td><?php echo esc_html($row['seen']) ?></td>
                                </tr>
                            <?php endforeach ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <p><?php esc_html_e('No bot visits logged yet.','website-llms-txt') ?></p>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>
    </div>
</div>