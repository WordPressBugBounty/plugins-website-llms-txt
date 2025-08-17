=== Website LLMs.txt ===
Contributors: ryhowa, samsonovteamwork
Tags: llm, ai, seo, rankmath, yoast
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 8.1.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and manage LLMS.txt files for LLM/AI content understanding, with full Yoast SEO and RankMath integration.

== Description ==

This plugin automatically generates an LLMs.txt file — a simple, structured list of important public URLs from your site — designed specifically for Large Language Models (LLMs) like ChatGPT, Perplexity, Claude, and other AI systems.
It works much like a traditional XML sitemap, but is optimized for the way AI agents read and learn from the web.

The plugin integrates seamlessly with popular SEO tools like Yoast SEO, Rank Math, and now AIOSEO, automatically excluding content marked as noindex or nofollow.

✅ Future-proof your site for AI discovery
✅ Lightweight, automatic, and customizable
✅ No need for manual configuration

New: llms.txt AI crawler detection
We now track whether major AI bots like GPTBot, ClaudeBot, and PerplexityBot are reading `llms.txt` files.

<a href="https://www.ryanhoward.dev/p/are-ai-search-bots-actually-looking-at-llms-txt-files" target="_blank">About crawler detection</a>
<a href="https://www.ryanhoward.dev/p/everything-we-know-about-llms-txt-573e">Everything we know about llms.txt</a>

Features:

* Automatic LLMS.txt generation
* Custom post type selection and ordering
* SEO plugin integration (Yoast SEO, RankMath)
* Sitemap integration
* Cache management
* Configurable update frequency

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/website-llms-txt`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->LLMS.txt screen to configure the plugin

== Frequently Asked Questions ==

= What is LLMS.txt? =

LLMS.txt is a standardized file that helps AI language models better understand your website content structure and hierarchy.

= Does this work with Yoast SEO and RankMath? =

Yes, the plugin integrates with both Yoast SEO and RankMath for sitemap generation and cache management.

= How often is the LLMS.txt file updated? =

You can choose between immediate, daily, or weekly updates in the plugin settings.

== Screenshots ==

1. Main settings page
2. Content configuration
3. Cache management
4. Manual file upload interface

== Changelog ==

= 8.1.4 =

✨ New: ACF Template-Based Post Indexing
• Posts using ACF-based templates (with custom fields and layouts) are now fully supported in the llms.txt generation process.
• Ensures that even dynamically rendered content is included in the index file.

🔍 Improvement: Post Type Indexing Summary
• The admin interface now displays the total number of posts per type alongside how many have been indexed (e.g. “Posts (123 indexed of 1829)”).
• Makes it easier to monitor indexing coverage and debug missing entries.

= 8.1.3 =

✨ New: Manual Generation Trigger for llms.txt
    • Added a "Generate Now" option in the admin to manually trigger llms.txt file generation without waiting for scheduled cron jobs.
    • Allows immediate regeneration for testing or urgent updates.

🐛 Fix: WP Engine Root File Creation Issue
    • Resolved an issue where llms.txt was generated in the uploads directory but not copied to the WordPress root on WP Engine-hosted sites.
    • Improved file system handling to ensure compatibility with WP Engine’s direct FS method and restrictive environments.
    • Includes fallback logic for reliable file movement and permission setting.

= 8.1.2 =

🐛 Fix: Trailing Slash Redirect Issue on llms.txt and llms-full.txt
	•	Resolved an issue where WordPress would incorrectly redirect requests for /llms.txt and /llms-full.txt due to trailing slash conflicts.
	•	Implemented a filter-based override to prevent canonical redirection behavior for these endpoints.
	•	Ensures proper file access and visibility across all permalink structures.
	•	Inspired by and aligned with community solutions provided for similar plugin issues.

= 8.1.1 =

🔧 Compatibility Fix: WordPress VIP Filesystem Support
	•	Resolved an issue where the plugin could not write the llms.txt file on WordPress VIP environments due to the lack of stream_lock support.
	•	Implemented fallback logic using WP_Filesystem:
	•	If the direct method is available, the plugin now writes using native PHP file handles (fopen in append mode) for better performance and memory efficiency on large files.
	•	Ensures compatibility with WordPress VIP’s restricted filesystem wrapper.
	•	Improved error handling and logging when file writing is not possible due to server restrictions.

= 8.1.0 =

🛠 Fix: 404 Error on llms-sitemap.xml with Yoast SEO

• Resolved an issue where the llms-sitemap.xml endpoint returned a 404 error when Yoast SEO was active.
• The sitemap rewrite rule is now properly registered and recognized, ensuring the sitemap is accessible alongside Yoast’s sitemaps.

= 8.0.9 =

🌐 WPML URL Generation Fix

• Fixed an issue where llms.txt was generating duplicate URLs with the same language code for all translations.
• Each URL is now generated correctly according to its respective language version in multilingual setups using WPML.

= 8.0.8 =

🛠️ SEO Compatibility Fixes

• Fixed an issue where Rank Math dynamic tags (e.g. %title%, %customterm(something)%) were not being rendered in llms.txt titles and descriptions.
• Dynamic SEO meta data now resolves correctly for all post types when using templates from Rank Math.

= 8.0.7 =

🌐 I18N Improvements

• Fixed localization issue in class-llms-md.php: the “Delete file” button label is now correctly translatable using esc_html_e() with the proper text domain.
• Ensured all static strings in UI components follow internationalization best practices.

= 8.0.6 =

🐞 Bug Fixes

• Fixed PHP warnings about undefined array key detailed_content in class-llms-generator.php when running cron from WP CLI.
• Added additional checks and defaults to prevent warnings in environments where detailed_content is not set.

= 8.0.5 =

🚀 New Feature & Bug Fixes

• Added support for deleting the uploaded .md file directly from the meta box.
• Fixed the behavior of the “Do not include this page in llms.txt” checkbox — now, when activated, the page is correctly excluded from the generated llms.txt file.

= 8.0.4 =

🐞 Bug Fixes & i18n Improvements

• Fixed internationalization (i18n) issue in the meta box: wrapped the meta box title in __() for proper translation support (thanks to Alex Lion for the report).
• Fixed PHP warnings about undefined array keys (llms_txt_title, llms_txt_description, llms_after_txt_description, llms_end_file_description, include_md_file, detailed_content) by adding proper defaults and safe checks when saving settings.
• Minor code cleanup to improve stability and compatibility.

= 8.0.3 =

🐞 Minor Fix: Meta Box Title

• Renamed the page/post meta box title from “Markdown (.md) file” to “Llms.txt” for better clarity and consistency with the feature’s purpose.

= 8.0.2 =

✨ UI & Page-Level Control: Sidebar Meta Box & Exclusion Option

• Moved the Markdown (.md) file meta box to the sidebar of the page/post edit screen for a cleaner and more consistent experience.
• Added a “Do not include this page in llms.txt” checkbox at the page level to allow excluding individual pages/posts from llms.txt output.
• Updated the meta box to include: llms.txt heading, .md upload field, and the new exclusion checkbox — all neatly organized.
• Ensured the exclusion setting and uploaded .md file are saved correctly and reflected in llms.txt.
• Minor UI polishing and accessibility improvements to align with WordPress admin styles.

= 8.0.1 =

✨ Enhancements & Options: More Flexible LLMS.txt Content Control

• Changed default behavior: options Include meta information (publish date, author, etc.), Include post excerpts, and Include taxonomies (categories, tags, etc.) are now unchecked by default for cleaner output.
• Added a new option: Include detailed content — allowing fine-grained control over whether to include detailed page/post content in the llms.txt file.
• Improved settings clarity and fallback behavior when all optional content is disabled.

= 8.0.0 =

✨ New Features & Improvements: Admin UI, Content Options, Markdown

• Rearranged admin dashboard: moved warning section and update frequency settings into an “Advanced Settings” card for better clarity.
• Improved content settings: added checkboxes to control inclusion of post excerpts and meta descriptions in output, with cleaner fallback to just URL + Title when unchecked.
• Added a dedicated “Custom LLMS.txt Content” panel in settings for defining a custom Title, Description, After Description, and End File Description.
• Added custom description field and an additional manual entry field per page/post, both included in llms.txt.
• Added support for attaching `.md` (Markdown) files per page/post — link to the file appears in llms.txt if enabled.
• `.md` files are stored in a dedicated `/llms_md/` folder and linked in llms.txt for reference.

= 7.1.6 =

🐞 Bug Fixes & Enhancements: Stability, Indexing, and Compatibility

• Fixed PHP warning for undefined llms_allow_indexing key in yoast.php, added proper default handling.
• Improved compatibility with Yoast SEO & RankMath by checking settings arrays before use.
• Enhanced fallback handling for missing meta descriptions and cleaned up fallback output in generated files.
• Minor code refactoring for better PHP 8.2+ compatibility and reduced log noise.

= 7.1.5 =

🐞 Bug Fixes & Improvements: WooCommerce, WP-Rocket, PHP Notices, and I18N

• Fixed a fatal error when editing WooCommerce products (has_weight() on null) caused by the plugin calling do_shortcode() on product content — now properly checks context and avoids passing invalid post data to WooCommerce templates.
• Adjusted WP-Rocket cache clearing behavior.
• Resolved PHP Notice in admin menu creation (add_submenu_page) by ensuring the 7th parameter is numeric (position), no longer passing invalid icon string.
• Improved I18N (Internationalization) strings in admin-page.php for proper localization and improved translations.
• Added minor UI fixes and cleaned up wording in the admin area.

✅ Recommended upgrade if you use WooCommerce, Divi theme, or WP-Rocket, and/or run with WP_DEBUG enabled.
🎯 Thanks to all users who reported and helped debug these issues!

= 7.1.4 =

🐞 Bug Fixes: Generator Stability and PHP 8.x Compatibility

• Fixed PHP warnings about undefined `$output` variable in `class-llms-generator.php` when generating LLMS data
• Fixed deprecated usage of `mb_convert_encoding()` with null input on line 428
• Ensures `$output` is always initialized before being used and passed to `mb_convert_encoding()`
• Improved error handling when no content is available to write during generation
• Verified compatibility with PHP 8.1 and 8.2 to prevent log noise and execution failures

= 7.1.1 =

🐞 Bug Fix: LLMS Crawler Activation

• Fixed an issue where the LLMS Crawler feature was not activating correctly after plugin installation or settings update
• Ensures that the crawler logging toggle properly saves and reflects the current state in the admin UI
• Improved reliability of the global experiment opt-in status

= 7.1.0 =

🐞 Bug Fix: Admin Menu Compatibility

• Fixed a PHP notice when WP_DEBUG is enabled, caused by incorrect usage of `add_submenu_page()`
• The submenu page no longer passes an icon name (`dashicons-media-text`) as the 7th parameter — now uses a proper numeric menu position
• Improves compatibility with WordPress >= 5.3 and prevents unnecessary log noise

= 7.0.9 =

🧠 New Feature: AI Crawler Detection

• Added new admin section with detailed insights into AI bot activity on your llms.txt file
• Introduced logging for AI crawlers like GPTBot, ClaudeBot, and PerplexityBot — including bot name and last seen timestamp
• Added dashboard table to view recent bot visits (max 100 entries, rolling log)
• New setting: opt in to the global AI crawler detection experiment — anonymously share bot access data (hashed domain + bot name)
• All telemetry is privacy-first: no content or personal data is collected or stored
• Integrated backend support for real-time participation tracking across thousands of sites
• Added admin banner linking to “How it works” with full experiment explanation

= 7.0.8 =

🛠 Improvements & Fixes
- File Status section now conditionally displays links (e.g. sitemap) only when relevant settings are enabled
- Prevents broken links when sitemap inclusion is not selected
- Minor UI consistency improvements

= 7.0.4 =

🛠️ Bug Fixes & Enhancements

• Added X-Robots-Tag: noindex header for llms.txt by default to discourage indexing by search engines.
• Introduced a checkbox setting to optionally disable the noindex header (not recommended).
• Cleaned up plugin description for clarity and removed outdated marketing language.
• Minor internal code improvements for consistency and maintainability.

= 7.0.3 =

🛠️ Bug Fixes & Improvements

• Added support for excluding llms.txt from sitemaps by default to prevent unintended indexing by search engines.
• Introduced an optional checkbox in settings to allow manual inclusion of llms.txt in the sitemap, with a clear SEO warning.
• On plugin deactivation, scheduled tasks related to llms.txt are now properly cleared and the file is removed from the site root to avoid stale exposure.

= 7.0.2 =

🛠️ Bug Fixes & Improvements

• Fixed an issue with detecting `nofollow` and `noindex` pages when using the Rank Math SEO plugin.
• The "Clear Caches" button in the Cache Management block now also clears the LLMS index table to ensure full site reindexing.

= 7.0.1 =

🛠️ Bug Fixes: JSON API Compatibility

• Resolved a critical issue that caused "Update failed. The response is not a valid JSON response." when editing or publishing posts.
• The plugin now correctly avoids interfering with the WordPress REST API response during post save/update actions.
• Confirmed compatibility with block editor and custom post types — post creation and updates now work reliably.

= 7.0.0 =

🚀 Major Overhaul: LLMS.txt Generation & Performance

• Rebuilt the LLMS.txt generation system from the ground up.
• Introduced a dedicated `llms_txt_cache` database table to index and store structured data efficiently.
• Greatly reduced server load by avoiding direct filesystem writes and enabling smarter caching.
• File generation is now handled **asynchronously via scheduled cron jobs** to avoid UI slowdowns and improve scalability.
• Minimized the number of filesystem write operations during LLMS.txt generation, improving reliability and performance.
• Optimized for large-scale databases — smoother performance on sites with thousands of posts.

= 6.1.2 =

🔧 Improved: Internationalization (i18n) and Display Logic
• Resolved several i18n issues by improving translation coverage and context handling.
• Prevented empty post_content pages from being shown in detailed content view.
• Fixed incorrect tagline display by properly falling back to site description settings.

These updates improve localization accuracy, content visibility logic, and metadata consistency.

= 6.1.1 =

🧹 Removed: Global Cache Flush
• Eliminated `wp_cache_flush()` calls from content processing loop.
• Prevented unintended flushing of global object cache affecting other plugins.
• Reading operations no longer interfere with cache integrity.

= 6.1.0 =

✅ Fixed: Yoast SEO Variable Parsing
• Resolved issue where dynamic SEO content using Yoast variables (e.g., %%title%%, %%excerpt%%) wasn’t correctly replaced during content generation.
• Content processed through wpseo_replace_vars() to ensure accurate output.
• Improved compatibility with Yoast SEO templates, even when used outside the standard loop or template hierarchy.

= 6.0.8 =

✅ Fixed: Emoji and Code Cleanup in llms.txt
• Emojis and unnecessary symbols are now automatically removed from `llms.txt`.
• Code snippets are correctly sanitized for plain-text output.
• Improved table formatting: table data is now correctly aligned and rendered when exported.

= 6.0.7 =

🗑️ Removed ai.txt File Generation
• The automatic creation of the ai.txt file has been removed.
• This change reduces unnecessary file writes and simplifies plugin behavior.
• If needed, you can still manually create and manage ai.txt in your site’s root.

= 6.0.6 =

✅ Persistent Dismiss for Admin Notices
• Admin notices now store dismissal state using user meta — ensuring they remain hidden once closed.
• No more repeated reminders across dashboard pages — smoother and less intrusive user experience.

🛠 Minor Code Cleanup
• Removed outdated notice render logic.
• Improved JS handling for notice dismissals across multi-user environments.

= 6.0.5 =
⚡ Enhanced Performance & Clean Output
• Database query logic fully refactored for high-speed data selection, reducing generation time by up to 70% on large sites.
• Replaced WP_Query with direct SQL access — now works faster and avoids unnecessary overhead.
• Significantly improved scalability and lower memory usage during .txt file generation.

🧹 Special Character Cleanup
• Removed invisible and problematic characters (NBSP, BOM, ZWSP, etc.) from post content to ensure clean and readable output.
• Prevents display issues and improves downstream AI parsing of .txt files.

📈 Faster Regeneration
• Full .txt regeneration after content updates is now noticeably faster, especially on content-heavy websites.
• Better memory handling and reduced write cycles during generation.

= 6.0.4 =

🌐 Multisite Link Format Change
• For multisite installations, .txt files are now accessible via trailing slash URLs:
example.com/llms.txt/ and example.com/ai.txt/.
• This ensures compatibility across various server environments and mapped domain setups.
• For single-site setups, physical .txt files are still generated and stored in the root directory.

🔧 Yoast SEO Exclusion Fix
• Fixed an issue where pages marked with noindex or nofollow in Yoast SEO were not properly excluded from the .txt output.
• Now both _yoast_wpseo_meta-robots-noindex and _yoast_wpseo_meta-robots-nofollow are fully respected.

= 6.0.3 =

🐛 Fix: 404 Not Found on NGINX Servers
• Resolved an issue where .txt files (llms.txt, ai.txt) returned a 404 error on NGINX-based hosting environments.
• Rewrite rules are now properly flushed and executed without needing manual permalink updates.

💰 Product Price Output
• Product prices are now displayed as plain text values (e.g., 56.00 USD) instead of HTML when WooCommerce support is enabled.
• Ensures clean and readable output for price values in llms.txt.

🔄 Important: Clear Cache After Update
• After updating to this version, please clear your site’s cache (including server-side and CDN cache) to ensure .txt file endpoints load correctly.

= 6.0.2 =

🌐 Multisite Support (Beta)
• The plugin now supports WordPress Multisite environments.
• Each site now stores and serves its own `llms.txt` and `ai.txt` content independently.
• Scheduled cron tasks are isolated per site to ensure accurate and isolated updates.
• Multisite-aware hooks implemented in `template_redirect` to correctly output `.txt` files on mapped domains.

📢 Admin Notice for Feature Suggestions
• Added a dismissible admin notice on new plugin installs to gather feedback and feature suggestions from users.
• Links included to Twitter and WP.org support forum for easy community engagement.
• Let’s coordinate on Slack for the next release to align on roadmap input strategy.

= 6.0.1 =

🛠️ Breakdance Compatibility Fix
• Fixed an issue where enabling “instant” updates for the llms.txt file on post save caused a 500 error when using the latest version of Breakdance Builder.
• Now, immediate updates are handled safely without interrupting the save process.

⏱️ Improved Cron Handling
• Switched to using a single scheduled event (wp_schedule_single_event) instead of triggering file updates directly during shutdown.
• This ensures better compatibility and stability, especially on heavy or slower servers.

➕ WooCommerce SKU Support
• Added SKU output if the post type is a WooCommerce product.
• The llms.txt file now includes a line like - SKU: [Product SKU] when available.


= 6.0.0 =

🛠️ Page Creation Respecting Settings
• Fixed a logic inconsistency where the AI Sitemap page could still exist even if the related setting was disabled.
• The plugin now ensures that page creation behavior strictly follows the user’s configuration, both during normal operation and after plugin updates.


= 5.0.8 =

🛠️ Page Creation Respecting Settings
• Fixed a logic inconsistency where the AI Sitemap page could still exist even if the related setting was disabled.
• The plugin now ensures that page creation behavior strictly follows the user’s configuration, both during normal operation and after plugin updates.

= 5.0.7 =

✅ New: Optional AI Sitemap Page
• Added a new setting to disable automatic creation of the AI Sitemap page (ai-sitemap).
• Users can now manage whether this page is created on init via the plugin settings panel.

🧠 Performance & Memory Usage
• Improved memory handling during content generation, especially for large post meta datasets.
• Reduced risk of memory leaks when working with heavy content by loading posts via IDs and flushing cache dynamically.

📄 Content Generation Enhancements
• Fixed issues related to long post content generation in llms.txt.
• Added a new option to control the number of words included per post in the generated file (default: 250).
• Better content trimming and cleaning logic for consistent output.

🔧 Stability & Cleanup
• Optimized handling of unset variables and object cleanup to avoid bloating memory usage during cron or manual execution.

= 5.0.7 =

✅ Settings Consistency Improvements
• The plugin now respects the “Include AI Sitemap page” setting more reliably across updates.
• Internal checks ensure that unnecessary pages are not created or kept when the option is disabled.

🧠 Update-Aware Logic
• Introduced version-aware behavior to trigger settings-related adjustments only once after plugin updates.
• Ensures cleaner and more consistent state without manual intervention.

= 5.0.6 =

✅ New: Optional AI Sitemap Page
• Added a new setting to disable automatic creation of the AI Sitemap page (ai-sitemap).
• Users can now manage whether this page is created on init via the plugin settings panel.

🧠 Performance & Memory Usage
• Improved memory handling during content generation, especially for large post meta datasets.
• Reduced risk of memory leaks when working with heavy content by loading posts via IDs and flushing cache dynamically.

📄 Content Generation Enhancements
• Fixed issues related to long post content generation in llms.txt.
• Added a new option to control the number of words included per post in the generated file (default: 250).
• Better content trimming and cleaning logic for consistent output.

🔧 Stability & Cleanup
• Optimized handling of unset variables and object cleanup to avoid bloating memory usage during cron or manual execution.

🧪 Tested With
• ✅ WordPress 6.5
• ✅ Yoast SEO 22.x
• ✅ Rank Math & AIOSEO compatibility verified

= 5.0.5 =

✅ Fixed 404 Error for Sitemap XML
• Resolved an issue where the llms-sitemap.xml endpoint could return a 404 error despite being properly registered.
• Now correctly sets the HTTP 200 status header for valid sitemap requests using status_header(200), ensuring compatibility with WordPress routing and sitemap indexing.
• Improved query var handling and rewrite rule registration to guarantee sitemap accessibility.

🧠 Other Improvements
• Refactored request handling logic to ensure clean output with proper MIME type headers (application/xml).
• Further stability improvements for Yoast integration and dynamic sitemap indexing.

🧪 Tested with WordPress 6.5 and Yoast SEO 22.x

= 5.0.4 =

🛠 Improvements & Fixes

✅ Automatic AI Sitemap page generation
    • The plugin now auto-creates a public /ai-sitemap page explaining what LLMs.txt is and how it improves AI visibility.
    • The page is only created if it doesn’t already exist, and includes a dynamic link to your actual LLMs sitemap file.
    • Content is filterable for advanced customization.

✅ Added support for ai.txt as an alternate LLM sitemap path
    • The plugin now generates both /llms.txt and /ai.txt to maximize compatibility with future AI indexing standards.
    • Both files are kept in sync and contain the same URL list.
    • This improves discoverability by AI crawlers that look for ai.txt by default.

✅ Enhanced onboarding & reliability
    • Improved logic to prevent duplicate pages.
    • Cleaned up sitemap text formatting for better readability.
    • Hook-friendly architecture for developers.

🚀 This update makes your site even more AI-ready by exposing your content through both standard and emerging LLM indexing formats — paving the way for visibility in tools like ChatGPT, Perplexity, and beyond.

= 5.0.3 =

🛠 Improvements & Fixes

✅ Added support for AIOSEO plugin
    • Integrated detection of aioseo_posts table to improve filtering accuracy.
    • Posts marked with robots_noindex or robots_nofollow in AIOSEO are now correctly excluded from output.
    • Fallback-safe: the logic only applies if the AIOSEO table exists in the database.

✅ Enhanced compatibility with multiple SEO plugins
    • Filtering logic now handles both Rank Math and AIOSEO data sources.
    • Posts without SEO meta data are still properly included unless explicitly marked as noindex.

🚀 This update expands SEO plugin compatibility, ensuring more accurate output when working with AIOSEO-powered sites, and avoids accidental indexing of excluded content.


= 5.0.2 =
✅ Fixed: Removed invalid contributor username from readme.txt (only WordPress.org profiles are allowed)

= 5.0.1 =

🛠 Improvements & Fixes

✅ Fixed issue with empty LLMS-generated files
	•	Resolved a bug where LLMS-generated files could appear empty if the rank_math_robots meta key was missing from posts.
	•	The plugin now correctly includes posts even if the Rank Math plugin is not installed or the meta field is not present.
	•	Prevented false negatives by ensuring the query accounts for both existing and non-existent rank_math_robots fields.

✅ Improved meta query logic for noindex handling
	•	Extended the meta_query to handle posts without the rank_math_robots key gracefully.
	•	Ensured that only posts explicitly marked as noindex are excluded, while all others (including those with no SEO plugin data) are properly included.

✅ Improved file generation accuracy
	•	Ensured that LLMS-related output files contain valid, expected content — reducing cases where generated files were blank due to strict filtering.
	•	Improved fallback logic for posts without SEO meta data.

🚀 This update ensures that LLMS-generated files remain accurate and complete, even on sites that don’t use Rank Math, and improves overall reliability when filtering content by SEO metadata.

= 5.0.0 =

🛠 Improvements & Fixes

✅ Added support for excluding noindex pages from Rank Math SEO

- The plugin now properly detects and excludes pages that have the `noindex` directive set in Rank Math SEO.
- Ensured that pages with `rank_math_robots` meta key containing `noindex` will not be included in the LLMS-generated files.
- This enhancement improves search engine indexing by preventing noindex-marked pages from being processed.

✅ Extended support for Yoast SEO & Rank Math

- Now supports both Yoast SEO and Rank Math SEO for detecting `noindex` pages.
- Ensured that `meta-robots-noindex` in Yoast and `rank_math_robots` in Rank Math are respected.
- Improved meta query logic to exclude noindex-marked pages efficiently.

✅ Better performance & stability

- Optimized post query handling to reduce unnecessary database queries when filtering indexed content.
- Improved support for large-scale websites by ensuring efficient exclusion of noindex pages.

🚀 This update ensures full compatibility with both Yoast SEO and Rank Math SEO, improving site indexing and preventing unwanted pages from being processed.


= 4.0.9 =

🛠 Improvements & Fixes
✅ Fixed compatibility issue with Yoast SEO sitemap generation

Resolved a problem where the llms-sitemap.xml file was not properly integrated with Yoast SEO’s sitemap indexing.
Ensured that the custom llms-sitemap.xml is correctly registered and included in Yoast’s sitemap structure.
✅ Enhanced XML sitemap handling

Added support for llms-sitemap.xml in the Yoast SEO wpseo_sitemaps_index filter.
Improved automatic detection and registration of the custom sitemap to avoid conflicts.
✅ Better performance & stability

Optimized the sitemap generation process to ensure compatibility with WordPress rewrite rules.
Fixed potential issues where the custom sitemap URL might not be accessible due to incorrect rewrite rules.
🚀 This update ensures full compatibility between the LLMS sitemap and Yoast SEO, improving site indexing and search engine visibility.

= 4.0.8 =

🛠 Improvements & Fixes
✅ Fixed an issue where post revisions triggered the post deletion handler

The handle_post_deletion() function now ignores post revisions by checking the post type (post_type !== 'revision').
This prevents unnecessary updates when WordPress auto-saves revisions or when users delete revisions manually.
✅ Enhanced stability of the content update process

Ensured that the handle_post_deletion() function only executes when an actual post is deleted, reducing unnecessary file rewrites.
✅ General code improvements

Added additional validation to prevent errors when handling deleted posts.
Optimized database queries for better performance.
🚀 This update improves the plugin's efficiency by reducing unnecessary processing and ensuring more stable content updates.

= 4.0.7 =

🛠 Improvements & Fixes
✅ Fixed rewrite rule conflicts:

Resolved an issue where the add_rewrite_rule() function was overriding WordPress post editing URLs.
Implemented a check to ensure the llms.txt rule does not overwrite existing permalink structures.
Used wp_rewrite_rules() to verify if the rule already exists before adding it.
✅ Enhanced rewrite rule handling:

Prevented duplicate rules from being registered.
Improved compatibility with custom post types and WordPress core URLs.
✅ Code Optimization & Performance:

Added additional security checks when handling requests.
Improved overall plugin stability and reliability.
🚀 This update ensures smoother permalink handling, better compatibility with WordPress core features, and improved stability for future updates.

= 4.0.6 =
* Updated Descriptions

= 4.0.5 =
* Adding an option to limit the maximum description length for post types when generating the llms.txt file – the default is 250 words.

= 4.0.4 =
* Considered the specifics for hosting providers wpengine.com and getflywheel.com.

= 4.0.3 =
* Resolved the issue with generation for websites with a large amount of content, as well as those with low memory capacity – tested with 128 MB.

= 4.0.2 =
* The data-saving logic in llms.txt has been reworked to reduce CPU and database load.

= 4.0.1 =
* The issue with displaying links to working files in llms.txt has been fixed.

= 4.0.0 =
* Fixed issue with cron and loading server’s CPU to 100%.

= 3.0.0 =
* Fixed character encoding issue in llms.txt on the Korean site.
* Resolved support-reported issues with llms-sitemap.xml.
* Updated the class cleaner file to the latest version.
* The newest version is now available on AgentVoice.com and is compatible with other shortcodes.

= 2.0.0 =
* Added support for custom post type ordering
* Improved cache management
* Enhanced SEO plugin integration
* Added manual file upload option

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
* Major update with new features and improvements. Adds custom post type ordering and enhanced cache management.

= 3.0.0 =
* Fixed character encoding issue in llms.txt on the Korean site.
* Resolved support-reported issues with llms-sitemap.xml.
* Updated the class cleaner file to the latest version.
* The newest version is now available on AgentVoice.com and is compatible with other shortcodes.

= 4.0.0 =
* Fixed issue with cron and loading server’s CPU to 100%.

= 4.0.1 =
* The issue with displaying links to working files in llms.txt has been fixed.

= 4.0.2 =
* The data-saving logic in llms.txt has been reworked to reduce CPU and database load.

= 4.0.3 =
* Resolved the issue with generation for websites with a large amount of content, as well as those with low memory capacity – tested with 128 MB.

= 4.0.4 =
* Considered the specifics for hosting providers wpengine.com and getflywheel.com.

= 4.0.5 =
* Adding an option to limit the maximum description length for post types when generating the llms.txt file – the default is 250 words.

= 4.0.6 =
* Updated Descriptions

= 4.0.7 =

🛠 Improvements & Fixes
✅ Fixed rewrite rule conflicts:

Resolved an issue where the add_rewrite_rule() function was overriding WordPress post editing URLs.
Implemented a check to ensure the llms.txt rule does not overwrite existing permalink structures.
Used wp_rewrite_rules() to verify if the rule already exists before adding it.
✅ Enhanced rewrite rule handling:

Prevented duplicate rules from being registered.
Improved compatibility with custom post types and WordPress core URLs.
✅ Code Optimization & Performance:

Added additional security checks when handling requests.
Improved overall plugin stability and reliability.
🚀 This update ensures smoother permalink handling, better compatibility with WordPress core features, and improved stability for future updates.

= 4.0.8 =

🛠 Improvements & Fixes
✅ Fixed an issue where post revisions triggered the post deletion handler

The handle_post_deletion() function now ignores post revisions by checking the post type (post_type !== 'revision').
This prevents unnecessary updates when WordPress auto-saves revisions or when users delete revisions manually.
✅ Enhanced stability of the content update process

Ensured that the handle_post_deletion() function only executes when an actual post is deleted, reducing unnecessary file rewrites.
✅ General code improvements

Added additional validation to prevent errors when handling deleted posts.
Optimized database queries for better performance.
🚀 This update improves the plugin's efficiency by reducing unnecessary processing and ensuring more stable content updates.

= 4.0.9 =

🛠 Improvements & Fixes
✅ Fixed compatibility issue with Yoast SEO sitemap generation

Resolved a problem where the llms-sitemap.xml file was not properly integrated with Yoast SEO’s sitemap indexing.
Ensured that the custom llms-sitemap.xml is correctly registered and included in Yoast’s sitemap structure.
✅ Enhanced XML sitemap handling

Added support for llms-sitemap.xml in the Yoast SEO wpseo_sitemaps_index filter.
Improved automatic detection and registration of the custom sitemap to avoid conflicts.
✅ Better performance & stability

Optimized the sitemap generation process to ensure compatibility with WordPress rewrite rules.
Fixed potential issues where the custom sitemap URL might not be accessible due to incorrect rewrite rules.
🚀 This update ensures full compatibility between the LLMS sitemap and Yoast SEO, improving site indexing and search engine visibility.

= 5.0.0 =

🛠 Improvements & Fixes

✅ Added support for excluding noindex pages from Rank Math SEO

- The plugin now properly detects and excludes pages that have the `noindex` directive set in Rank Math SEO.
- Ensured that pages with `rank_math_robots` meta key containing `noindex` will not be included in the LLMS-generated files.
- This enhancement improves search engine indexing by preventing noindex-marked pages from being processed.

✅ Extended support for Yoast SEO & Rank Math

- Now supports both Yoast SEO and Rank Math SEO for detecting `noindex` pages.
- Ensured that `meta-robots-noindex` in Yoast and `rank_math_robots` in Rank Math are respected.
- Improved meta query logic to exclude noindex-marked pages efficiently.

✅ Better performance & stability

- Optimized post query handling to reduce unnecessary database queries when filtering indexed content.
- Improved support for large-scale websites by ensuring efficient exclusion of noindex pages.

🚀 This update ensures full compatibility with both Yoast SEO and Rank Math SEO, improving site indexing and preventing unwanted pages from being processed.

= 5.0.1 =

🛠 Improvements & Fixes

✅ Fixed issue with empty LLMS-generated files
	•	Resolved a bug where LLMS-generated files could appear empty if the rank_math_robots meta key was missing from posts.
	•	The plugin now correctly includes posts even if the Rank Math plugin is not installed or the meta field is not present.
	•	Prevented false negatives by ensuring the query accounts for both existing and non-existent rank_math_robots fields.

✅ Improved meta query logic for noindex handling
	•	Extended the meta_query to handle posts without the rank_math_robots key gracefully.
	•	Ensured that only posts explicitly marked as noindex are excluded, while all others (including those with no SEO plugin data) are properly included.

✅ Improved file generation accuracy
	•	Ensured that LLMS-related output files contain valid, expected content — reducing cases where generated files were blank due to strict filtering.
	•	Improved fallback logic for posts without SEO meta data.

🚀 This update ensures that LLMS-generated files remain accurate and complete, even on sites that don’t use Rank Math, and improves overall reliability when filtering content by SEO metadata.

= 5.0.2 =
✅ Fixed: Removed invalid contributor username from readme.txt (only WordPress.org profiles are allowed)

= 5.0.3 =

🛠 Improvements & Fixes

✅ Added support for AIOSEO plugin
    • Integrated detection of aioseo_posts table to improve filtering accuracy.
    • Posts marked with robots_noindex or robots_nofollow in AIOSEO are now correctly excluded from output.
    • Fallback-safe: the logic only applies if the AIOSEO table exists in the database.

✅ Enhanced compatibility with multiple SEO plugins
    • Filtering logic now handles both Rank Math and AIOSEO data sources.
    • Posts without SEO meta data are still properly included unless explicitly marked as noindex.

🚀 This update expands SEO plugin compatibility, ensuring more accurate output when working with AIOSEO-powered sites, and avoids accidental indexing of excluded content.

= 5.0.4 =

🛠 Improvements & Fixes

✅ Automatic AI Sitemap page generation
    • The plugin now auto-creates a public /ai-sitemap page explaining what LLMs.txt is and how it improves AI visibility.
    • The page is only created if it doesn’t already exist, and includes a dynamic link to your actual LLMs sitemap file.
    • Content is filterable for advanced customization.

✅ Added support for ai.txt as an alternate LLM sitemap path
    • The plugin now generates both /llms.txt and /ai.txt to maximize compatibility with future AI indexing standards.
    • Both files are kept in sync and contain the same URL list.
    • This improves discoverability by AI crawlers that look for ai.txt by default.

✅ Enhanced onboarding & reliability
    • Improved logic to prevent duplicate pages.
    • Cleaned up sitemap text formatting for better readability.
    • Hook-friendly architecture for developers.

🚀 This update makes your site even more AI-ready by exposing your content through both standard and emerging LLM indexing formats — paving the way for visibility in tools like ChatGPT, Perplexity, and beyond.

= 5.0.5 =

✅ Fixed 404 Error for Sitemap XML
• Resolved an issue where the llms-sitemap.xml endpoint could return a 404 error despite being properly registered.
• Now correctly sets the HTTP 200 status header for valid sitemap requests using status_header(200), ensuring compatibility with WordPress routing and sitemap indexing.
• Improved query var handling and rewrite rule registration to guarantee sitemap accessibility.

🧠 Other Improvements
• Refactored request handling logic to ensure clean output with proper MIME type headers (application/xml).
• Further stability improvements for Yoast integration and dynamic sitemap indexing.

🧪 Tested with WordPress 6.5 and Yoast SEO 22.x

= 5.0.6 =

✅ New: Optional AI Sitemap Page
• Added a new setting to disable automatic creation of the AI Sitemap page (ai-sitemap).
• Users can now manage whether this page is created on init via the plugin settings panel.

🧠 Performance & Memory Usage
• Improved memory handling during content generation, especially for large post meta datasets.
• Reduced risk of memory leaks when working with heavy content by loading posts via IDs and flushing cache dynamically.

📄 Content Generation Enhancements
• Fixed issues related to long post content generation in llms.txt.
• Added a new option to control the number of words included per post in the generated file (default: 250).
• Better content trimming and cleaning logic for consistent output.

🔧 Stability & Cleanup
• Optimized handling of unset variables and object cleanup to avoid bloating memory usage during cron or manual execution.

🧪 Tested With
• ✅ WordPress 6.5
• ✅ Yoast SEO 22.x
• ✅ Rank Math & AIOSEO compatibility verified

= 5.0.7 =

✅ Settings Consistency Improvements
• The plugin now respects the “Include AI Sitemap page” setting more reliably across updates.
• Internal checks ensure that unnecessary pages are not created or kept when the option is disabled.

🧠 Update-Aware Logic
• Introduced version-aware behavior to trigger settings-related adjustments only once after plugin updates.
• Ensures cleaner and more consistent state without manual intervention.

= 5.0.8 =

🛠️ Page Creation Respecting Settings
• Fixed a logic inconsistency where the AI Sitemap page could still exist even if the related setting was disabled.
• The plugin now ensures that page creation behavior strictly follows the user’s configuration, both during normal operation and after plugin updates.

= 6.0.0 =

🛠️ Page Creation Respecting Settings
• Fixed a logic inconsistency where the AI Sitemap page could still exist even if the related setting was disabled.
• The plugin now ensures that page creation behavior strictly follows the user’s configuration, both during normal operation and after plugin updates.

= 6.0.1 =

🛠️ Breakdance Compatibility Fix
• Fixed an issue where enabling “instant” updates for the llms.txt file on post save caused a 500 error when using the latest version of Breakdance Builder.
• Now, immediate updates are handled safely without interrupting the save process.

⏱️ Improved Cron Handling
• Switched to using a single scheduled event (wp_schedule_single_event) instead of triggering file updates directly during shutdown.
• This ensures better compatibility and stability, especially on heavy or slower servers.

➕ WooCommerce SKU Support
• Added SKU output if the post type is a WooCommerce product.
• The llms.txt file now includes a line like - SKU: [Product SKU] when available.

= 6.0.2 =

🌐 Multisite Support (Beta)
• The plugin now supports WordPress Multisite environments.
• Each site now stores and serves its own `llms.txt` and `ai.txt` content independently.
• Scheduled cron tasks are isolated per site to ensure accurate and isolated updates.
• Multisite-aware hooks implemented in `template_redirect` to correctly output `.txt` files on mapped domains.

📢 Admin Notice for Feature Suggestions
• Added a dismissible admin notice on new plugin installs to gather feedback and feature suggestions from users.
• Links included to Twitter and WP.org support forum for easy community engagement.
• Let’s coordinate on Slack for the next release to align on roadmap input strategy.

= 6.0.3 =

🐛 Fix: 404 Not Found on NGINX Servers
• Resolved an issue where .txt files (llms.txt, ai.txt) returned a 404 error on NGINX-based hosting environments.
• Rewrite rules are now properly flushed and executed without needing manual permalink updates.

💰 Product Price Output
• Product prices are now displayed as plain text values (e.g., 56.00 USD) instead of HTML when WooCommerce support is enabled.
• Ensures clean and readable output for price values in llms.txt.

🔄 Important: Clear Cache After Update
• After updating to this version, please clear your site’s cache (including server-side and CDN cache) to ensure .txt file endpoints load correctly.

= 6.0.4 =

🌐 Multisite Link Format Change
• For multisite installations, .txt files are now accessible via trailing slash URLs:
example.com/llms.txt/ and example.com/ai.txt/.
• This ensures compatibility across various server environments and mapped domain setups.
• For single-site setups, physical .txt files are still generated and stored in the root directory.

🔧 Yoast SEO Exclusion Fix
• Fixed an issue where pages marked with noindex or nofollow in Yoast SEO were not properly excluded from the .txt output.
• Now both _yoast_wpseo_meta-robots-noindex and _yoast_wpseo_meta-robots-nofollow are fully respected.

= 6.0.5 =
⚡ Enhanced Performance & Clean Output
• Database query logic fully refactored for high-speed data selection, reducing generation time by up to 70% on large sites.
• Replaced WP_Query with direct SQL access — now works faster and avoids unnecessary overhead.
• Significantly improved scalability and lower memory usage during .txt file generation.

🧹 Special Character Cleanup
• Removed invisible and problematic characters (NBSP, BOM, ZWSP, etc.) from post content to ensure clean and readable output.
• Prevents display issues and improves downstream AI parsing of .txt files.

📈 Faster Regeneration
• Full .txt regeneration after content updates is now noticeably faster, especially on content-heavy websites.
• Better memory handling and reduced write cycles during generation.

= 6.0.6 =

✅ Persistent Dismiss for Admin Notices
• Admin notices now store dismissal state using user meta — ensuring they remain hidden once closed.
• No more repeated reminders across dashboard pages — smoother and less intrusive user experience.

🛠 Minor Code Cleanup
• Removed outdated notice render logic.
• Improved JS handling for notice dismissals across multi-user environments.

= 6.0.7 =

🗑️ Removed ai.txt File Generation
• The automatic creation of the ai.txt file has been removed.
• This change reduces unnecessary file writes and simplifies plugin behavior.
• If needed, you can still manually create and manage ai.txt in your site’s root.

= 6.0.8 =

✅ Fixed: Emoji and Code Cleanup in llms.txt
• Emojis and unnecessary symbols are now automatically removed from `llms.txt`.
• Code snippets are correctly sanitized for plain-text output.
• Improved table formatting: table data is now correctly aligned and rendered when exported.

= 6.0.9 =

✅ Fixed: Yoast SEO Variable Parsing
• Resolved issue where dynamic SEO content using Yoast variables (e.g., %%title%%, %%excerpt%%) wasn’t correctly replaced during content generation.
• Content processed through wpseo_replace_vars() to ensure accurate output.
• Improved compatibility with Yoast SEO templates, even when used outside the standard loop or template hierarchy.

= 6.1.0 =

✅ Improved: Fallback Description Handling & Text Cleanup
• Fixed display issues caused by invisible &nbsp; characters — these are now properly removed from the output.
• If no SEO plugin is active, the meta description is now automatically pulled from the front page content or excerpt as a fallback.
• Ensures cleaner, more reliable plain-text output for non-SEO-configured sites.

= 6.1.1 =

🧹 Removed: Global Cache Flush
• Eliminated `wp_cache_flush()` calls from content processing loop.
• Prevented unintended flushing of global object cache affecting other plugins.
• Reading operations no longer interfere with cache integrity.

= 6.1.2 =

🔧 Improved: Internationalization (i18n) and Display Logic
• Resolved several i18n issues by improving translation coverage and context handling.
• Prevented empty post_content pages from being shown in detailed content view.
• Fixed incorrect tagline display by properly falling back to site description settings.

These updates improve localization accuracy, content visibility logic, and metadata consistency.

= 7.0.0 =

🚀 Major Overhaul: LLMS.txt Generation & Performance

• Rebuilt the LLMS.txt generation system from the ground up.
• Introduced a dedicated `llms_txt_cache` database table to index and store structured data efficiently.
• Greatly reduced server load by avoiding direct filesystem writes and enabling smarter caching.
• File generation is now handled **asynchronously via scheduled cron jobs** to avoid UI slowdowns and improve scalability.
• Minimized the number of filesystem write operations during LLMS.txt generation, improving reliability and performance.
• Optimized for large-scale databases — smoother performance on sites with thousands of posts.

= 7.0.1 =

🛠️ Bug Fixes: JSON API Compatibility

• Resolved a critical issue that caused "Update failed. The response is not a valid JSON response." when editing or publishing posts.
• The plugin now correctly avoids interfering with the WordPress REST API response during post save/update actions.
• Confirmed compatibility with block editor and custom post types — post creation and updates now work reliably.

= 7.0.2 =

🛠️ Bug Fixes & Improvements

• Fixed an issue with detecting `nofollow` and `noindex` pages when using the Rank Math SEO plugin.
• The "Clear Caches" button in the Cache Management block now also clears the LLMS index table to ensure full site reindexing.

= 7.0.3 =

🛠️ Bug Fixes & Improvements

• Added support for excluding llms.txt from sitemaps by default to prevent unintended indexing by search engines.
• Introduced an optional checkbox in settings to allow manual inclusion of llms.txt in the sitemap, with a clear SEO warning.
• On plugin deactivation, scheduled tasks related to llms.txt are now properly cleared and the file is removed from the site root to avoid stale exposure.

= 7.0.4 =

🛠️ Bug Fixes & Enhancements

• Added X-Robots-Tag: noindex header for llms.txt by default to discourage indexing by search engines.
• Introduced a checkbox setting to optionally disable the noindex header (not recommended).
• Cleaned up plugin description for clarity and removed outdated marketing language.
• Minor internal code improvements for consistency and maintainability.

= 7.0.8 =

🛠 Improvements & Fixes
- File Status section now conditionally displays links (e.g. sitemap) only when relevant settings are enabled
- Prevents broken links when sitemap inclusion is not selected
- Minor UI consistency improvements

= 7.0.9 =

🧠 New Feature: AI Crawler Detection

• Added new admin section with detailed insights into AI bot activity on your llms.txt file
• Introduced logging for AI crawlers like GPTBot, ClaudeBot, and PerplexityBot — including bot name and last seen timestamp
• Added dashboard table to view recent bot visits (max 100 entries, rolling log)
• New setting: opt in to the global AI crawler detection experiment — anonymously share bot access data (hashed domain + bot name)
• All telemetry is privacy-first: no content or personal data is collected or stored
• Integrated backend support for real-time participation tracking across thousands of sites
• Added admin banner linking to “How it works” with full experiment explanation

= 7.1.0 =

🐞 Bug Fix: Admin Menu Compatibility

• Fixed a PHP notice when WP_DEBUG is enabled, caused by incorrect usage of `add_submenu_page()`
• The submenu page no longer passes an icon name (`dashicons-media-text`) as the 7th parameter — now uses a proper numeric menu position
• Improves compatibility with WordPress >= 5.3 and prevents unnecessary log noise

= 7.1.1 =

🐞 Bug Fix: LLMS Crawler Activation

• Fixed an issue where the LLMS Crawler feature was not activating correctly after plugin installation or settings update
• Ensures that the crawler logging toggle properly saves and reflects the current state in the admin UI
• Improved reliability of the global experiment opt-in status

= 7.1.4 =

🐞 Bug Fixes: Generator Stability and PHP 8.x Compatibility

• Fixed PHP warnings about undefined `$output` variable in `class-llms-generator.php` when generating LLMS data
• Fixed deprecated usage of `mb_convert_encoding()` with null input on line 428
• Ensures `$output` is always initialized before being used and passed to `mb_convert_encoding()`
• Improved error handling when no content is available to write during generation
• Verified compatibility with PHP 8.1 and 8.2 to prevent log noise and execution failures

= 7.1.5 =

🐞 Bug Fixes & Improvements: WooCommerce, WP-Rocket, PHP Notices, and I18N

• Fixed a fatal error when editing WooCommerce products (has_weight() on null) caused by the plugin calling do_shortcode() on product content — now properly checks context and avoids passing invalid post data to WooCommerce templates.
• Adjusted WP-Rocket cache clearing behavior.
• Resolved PHP Notice in admin menu creation (add_submenu_page) by ensuring the 7th parameter is numeric (position), no longer passing invalid icon string.
• Improved I18N (Internationalization) strings in admin-page.php for proper localization and improved translations.
• Added minor UI fixes and cleaned up wording in the admin area.

✅ Recommended upgrade if you use WooCommerce, Divi theme, or WP-Rocket, and/or run with WP_DEBUG enabled.
🎯 Thanks to all users who reported and helped debug these issues!

= 7.1.6 =

🐞 Bug Fixes & Enhancements: Stability, Indexing, and Compatibility

• Fixed PHP warning for undefined llms_allow_indexing key in yoast.php, added proper default handling.
• Improved compatibility with Yoast SEO & RankMath by checking settings arrays before use.
• Enhanced fallback handling for missing meta descriptions and cleaned up fallback output in generated files.
• Minor code refactoring for better PHP 8.2+ compatibility and reduced log noise.

= 8.0.0 =

✨ New Features & Improvements: Admin UI, Content Options, Markdown

• Rearranged admin dashboard: moved warning section and update frequency settings into an “Advanced Settings” card for better clarity.
• Improved content settings: added checkboxes to control inclusion of post excerpts and meta descriptions in output, with cleaner fallback to just URL + Title when unchecked.
• Added a dedicated “Custom LLMS.txt Content” panel in settings for defining a custom Title, Description, After Description, and End File Description.
• Added custom description field and an additional manual entry field per page/post, both included in llms.txt.
• Added support for attaching `.md` (Markdown) files per page/post — link to the file appears in llms.txt if enabled.
• `.md` files are stored in a dedicated `/llms_md/` folder and linked in llms.txt for reference.

= 8.0.1 =

✨ Enhancements & Options: More Flexible LLMS.txt Content Control

• Changed default behavior: options Include meta information (publish date, author, etc.), Include post excerpts, and Include taxonomies (categories, tags, etc.) are now unchecked by default for cleaner output.
• Added a new option: Include detailed content — allowing fine-grained control over whether to include detailed page/post content in the llms.txt file.
• Improved settings clarity and fallback behavior when all optional content is disabled.

= 8.0.2 =

✨ UI & Page-Level Control: Sidebar Meta Box & Exclusion Option

• Moved the Markdown (.md) file meta box to the sidebar of the page/post edit screen for a cleaner and more consistent experience.
• Added a “Do not include this page in llms.txt” checkbox at the page level to allow excluding individual pages/posts from llms.txt output.
• Updated the meta box to include: llms.txt heading, .md upload field, and the new exclusion checkbox — all neatly organized.
• Ensured the exclusion setting and uploaded .md file are saved correctly and reflected in llms.txt.
• Minor UI polishing and accessibility improvements to align with WordPress admin styles.

= 8.0.3 =

🐞 Minor Fix: Meta Box Title

• Renamed the page/post meta box title from “Markdown (.md) file” to “Llms.txt” for better clarity and consistency with the feature’s purpose.

= 8.0.4 =

🐞 Bug Fixes & i18n Improvements

• Fixed internationalization (i18n) issue in the meta box: wrapped the meta box title in __() for proper translation support (thanks to Alex Lion for the report).
• Fixed PHP warnings about undefined array keys (llms_txt_title, llms_txt_description, llms_after_txt_description, llms_end_file_description, include_md_file, detailed_content) by adding proper defaults and safe checks when saving settings.
• Minor code cleanup to improve stability and compatibility.

= 8.0.5 =

🚀 New Feature & Bug Fixes

• Added support for deleting the uploaded .md file directly from the meta box.
• Fixed the behavior of the “Do not include this page in llms.txt” checkbox — now, when activated, the page is correctly excluded from the generated llms.txt file.

= 8.0.6 =

🐞 Bug Fixes

• Fixed PHP warnings about undefined array key detailed_content in class-llms-generator.php when running cron from WP CLI.
• Added additional checks and defaults to prevent warnings in environments where detailed_content is not set.

= 8.0.7 =

🌐 I18N Improvements

• Fixed localization issue in class-llms-md.php: the “Delete file” button label is now correctly translatable using esc_html_e() with the proper text domain.
• Ensured all static strings in UI components follow internationalization best practices.

= 8.0.8 =

🛠️ SEO Compatibility Fixes

• Fixed an issue where Rank Math dynamic tags (e.g. %title%, %customterm(something)%) were not being rendered in llms.txt titles and descriptions.
• Dynamic SEO meta data now resolves correctly for all post types when using templates from Rank Math.

= 8.0.9 =

🌐 WPML URL Generation Fix

• Fixed an issue where llms.txt was generating duplicate URLs with the same language code for all translations.
• Each URL is now generated correctly according to its respective language version in multilingual setups using WPML.

= 8.1.0 =

🛠 Fix: 404 Error on llms-sitemap.xml with Yoast SEO

• Resolved an issue where the llms-sitemap.xml endpoint returned a 404 error when Yoast SEO was active.
• The sitemap rewrite rule is now properly registered and recognized, ensuring the sitemap is accessible alongside Yoast’s sitemaps.

= 8.1.1 =

🔧 Compatibility Fix: WordPress VIP Filesystem Support
	•	Resolved an issue where the plugin could not write the llms.txt file on WordPress VIP environments due to the lack of stream_lock support.
	•	Implemented fallback logic using WP_Filesystem:
	•	If the direct method is available, the plugin now writes using native PHP file handles (fopen in append mode) for better performance and memory efficiency on large files.
	•	Ensures compatibility with WordPress VIP’s restricted filesystem wrapper.
	•	Improved error handling and logging when file writing is not possible due to server restrictions.

= 8.1.2 =

🐛 Fix: Trailing Slash Redirect Issue on llms.txt and llms-full.txt
	•	Resolved an issue where WordPress would incorrectly redirect requests for /llms.txt and /llms-full.txt due to trailing slash conflicts.
	•	Implemented a filter-based override to prevent canonical redirection behavior for these endpoints.
	•	Ensures proper file access and visibility across all permalink structures.
	•	Inspired by and aligned with community solutions provided for similar plugin issues.

= 8.1.3 =

✨ New: Manual Generation Trigger for llms.txt
    • Added a "Generate Now" option in the admin to manually trigger llms.txt file generation without waiting for scheduled cron jobs.
    • Allows immediate regeneration for testing or urgent updates.

🐛 Fix: WP Engine Root File Creation Issue
    • Resolved an issue where llms.txt was generated in the uploads directory but not copied to the WordPress root on WP Engine-hosted sites.
    • Improved file system handling to ensure compatibility with WP Engine’s direct FS method and restrictive environments.
    • Includes fallback logic for reliable file movement and permission setting.

= 8.1.4 =

✨ New: ACF Template-Based Post Indexing
• Posts using ACF-based templates (with custom fields and layouts) are now fully supported in the llms.txt generation process.
• Ensures that even dynamically rendered content is included in the index file.

🔍 Improvement: Post Type Indexing Summary
• The admin interface now displays the total number of posts per type alongside how many have been indexed (e.g. “Posts (123 indexed of 1829)”).
• Makes it easier to monitor indexing coverage and debug missing entries.