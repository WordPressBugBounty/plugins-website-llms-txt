=== Website LLMs.txt ===
Contributors: ryhowa, samsonovteamwork
Tags: llm, ai, seo, rankmath, yoast
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 6.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and manage LLMS.txt files for LLM/AI content understanding, with full Yoast SEO and RankMath integration.

== Description ==

Website LLMs.txt helps your website become discoverable in the age of generative AI.

This plugin automatically generates an LLMs.txt file — a simple, structured list of important public URLs from your site — designed specifically for Large Language Models (LLMs) like ChatGPT, Perplexity, Claude, and other AI systems.
It works much like a traditional XML sitemap, but is optimized for the way AI agents read and learn from the web.

The plugin integrates seamlessly with popular SEO tools like Yoast SEO, Rank Math, and now AIOSEO, automatically excluding content marked as noindex or nofollow.

✅ Future-proof your site for AI discovery
✅ Lightweight, automatic, and customizable
✅ No need for manual configuration

Whether you’re running a blog, store, portfolio, or membership site — LLMs.txt ensures your content is seen and understood by the next generation of intelligent assistants.

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