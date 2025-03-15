=== Website LLMs.txt ===
Contributors: websitellm, ryhowa
Tags: llm, ai, seo, rankmath, yoast
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 4.0.8
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and manage LLMS.txt files for LLM/AI content understanding, with full Yoast SEO and RankMath integration.

== Description ==

Website LLMs.txt helps search engines and AI systems better understand your website content by automatically generating and managing LLMS.txt files. It integrates seamlessly with popular SEO plugins like Yoast SEO and RankMath.

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