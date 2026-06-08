=== Website LLMs.txt ===
Contributors: ryhowa, samsonovteamwork
Tags: llm, ai, seo, rankmath, yoast
Requires at least: 5.8
Tested up to: 6.9.4
Requires PHP: 7.2
Stable tag: 8.4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and manage LLMS.txt files for LLM/AI content understanding, with full Yoast SEO, Rank Math, SEOPress, and AIOSEO integration.

== Description ==

**Website LLMs.txt** generates and manages an `llms.txt` file, a structured, AI-ready index that helps large language models like ChatGPT, Claude, and Perplexity understand your site’s most important content.

### How llms.txt works
Traditional sitemaps and robots files guide search engines. But as AI-driven systems such as ChatGPT, Claude, and Perplexity increasingly ingest web content, they benefit from a clear, structured list of a site’s most important URLs.
`llms.txt` offers that: a plain-text or Markdown list of essential public URLs, optionally annotated with titles, descriptions, and grouping, designed for AI consumption rather than general web crawling.

### Key benefits
✅ **AI discovery readiness**: future-proof your site for AI indexing and content retrieval.
✅ **Fully automatic**: the plugin builds and updates your `llms.txt` file on its own schedule.
✅ **SEO plugin integration**: works seamlessly with Yoast SEO, Rank Math, SEOPress, and AIOSEO, automatically excluding content marked as *noindex* or *nofollow*.
✅ **Advanced controls**: choose post types, customize file titles or descriptions, attach optional Markdown files, and trigger manual regeneration.
✅ **Developer-friendly**: includes filters such as `llms_generator_get_post_meta_description` for description logic, performance tuning, and custom indexing behavior.
✅ **AI crawler detection**: opt in to track whether GPTBot, ClaudeBot, or PerplexityBot are actually reading your site’s `llms.txt`.
✅ **WooCommerce & multisite ready**: respects product visibility rules and scales easily across large or networked sites.
✅ **Privacy-first experiment**: anonymous, encrypted telemetry helps reveal which bots are accessing `llms.txt` files across the web.

### Activation & setup
1. Activate the plugin.
2. Visit *Settings → LLMs.txt* to configure post types, update frequency (immediate, daily, or weekly), and optional crawler logging.
3. The plugin generates `llms.txt` (and optionally `llms-full.txt`) and serves it from your site root.
4. Content updates trigger automatic regeneration. All noindex/nofollow rules from your SEO plugin are respected.
5. If you enable AI crawler logging, local and global logs record each visit from known AI bots, viewable right inside your WordPress dashboard.

### Use cases for llms.txt
- Publishers, SaaS companies, developers, and documentation sites that want to make their content easier for AI systems to interpret.
- SEO-driven websites testing AI engine optimization tactics.
- Agencies and site owners preparing for the next phase of AI search and retrieval.

### The llms.txt experiment & further reading
- [Are AI bots actually reading llms.txt files?](https://completeseo.com/are-ai-bots-actually-reading-llms-txt-files/)
- [Everything we know about llms.txt](https://completeseo.com/everything-we-know-about-llms-txt/)


== Installation ==

1. Upload the plugin files to `/wp-content/plugins/website-llms-txt`
2. Activate the plugin through the *Plugins* screen in WordPress
3. Go to *Settings → LLMs.txt* to configure options and generate your file


== Frequently Asked Questions ==

= What is llms.txt? =
`llms.txt` is a plain-text or Markdown file placed at the root of your domain (for example `https://example.com/llms.txt`) that lists your site’s most important public URLs. It helps large language models (LLMs) like ChatGPT, Claude, and Perplexity better understand your site’s structure and priority content.

= How does the Website LLMs.txt plugin work? =
The plugin automatically generates and maintains your `llms.txt` file based on published content. It pulls titles and descriptions from your site, respects SEO plugin settings (Yoast SEO, Rank Math, SEOPress, and AIOSEO), and excludes anything marked as *noindex* or *nofollow*. The file is then served from your site root, ready for AI crawlers to read.

= How often is llms.txt updated? =
You can set the update frequency in the plugin settings: immediate, daily, or weekly. You can also click “Generate Now” in the admin panel to rebuild the file at any time.

= Does this guarantee visibility in ChatGPT, Claude, or Perplexity? =
No. There’s no guarantee that any AI model will immediately use `llms.txt`, but it’s clear that several systems, including GPTBot, ClaudeBot, and PerplexityBot, are already crawling these files. Using `llms.txt` positions your site ahead of the curve as AI indexing becomes more structured.

= What’s the difference between llms.txt and llms-full.txt? =
`llms.txt` is a concise, curated list of key URLs.
`llms-full.txt` is an optional extended file generated by the plugin that includes a more comprehensive export of your site’s content. It’s useful for documentation sites, developer platforms, or large content hubs that want to expose additional structure to AI systems.

= What if my host doesn’t allow writing to the root directory? =
The plugin includes fallback logic for environments such as WordPress VIP or read-only hosting. In those cases, it serves `llms.txt` virtually through WordPress rewrite rules, so the file is still accessible at `https://example.com/llms.txt`.

= Does it work with SEO plugins like Yoast or Rank Math? =
Yes. It automatically integrates with Yoast SEO, Rank Math, SEOPress, and AIOSEO. Pages marked as *noindex* or *nofollow* in any of those plugins will be excluded from your `llms.txt` file automatically.

= Can I track which AI bots visit my llms.txt file? =
Yes. When crawler logging is enabled, visits from AI crawlers such as GPTBot, ClaudeBot, and PerplexityBot are recorded. You can view these visits in your WordPress dashboard. If you opt into the global experiment, your data is anonymized and encrypted before contributing to a shared dataset that tracks AI bot behavior across thousands of sites.

= Will it conflict with sitemap.xml or robots.txt? =
No. `llms.txt` complements your sitemap and robots file. Sitemaps tell search engines what to crawl; `llms.txt` helps AI systems understand what’s most valuable. They work together without overlap or conflict.

= Can I customize what appears in llms.txt? =
Yes. You can include or exclude specific post types, add a custom title or description, and even attach Markdown (`.md`) files to individual posts or pages. The plugin provides a straightforward settings panel and per-page controls for fine-tuning output.

= I’m a developer. Are there filters or hooks available? =
Yes. Filters such as `llms_generator_get_post_meta_description` and others allow you to modify how descriptions are generated or extend what metadata appears in the file. Developers can also adjust caching behavior, database queries, and output formatting.

= Is any personal data shared when I enable crawler logging? =
No. All telemetry is privacy-first. Local logs remain on your site. If you opt into the public experiment, only anonymized data (bot name, timestamp, and a hashed version of your domain) is shared. No content, user, or identifiable data is ever transmitted.

= Does the plugin set any cookies, and is the Visibility Kit connection optional? =
Out of the box, the plugin sets no cookies and loads no third-party scripts. The optional Visibility Kit integration is strictly opt-in: nothing is added to your site until an administrator enters an email address and clicks **Connect to Visibility Kit** in the plugin settings. Only after you connect does the plugin load the Visibility Kit script (`vk.js`), which sets first-party analytics cookies (`_vk_vid`, `_vk_session_id`, `_vk_attr_first`, `_vk_landing`, `_vk_referrer`) used to attribute AI-referred visits. You can remove the script and stop the cookies at any time with **Disconnect from Visibility Kit** in the settings. If you use a Consent Management Platform (Cookiebot, Complianz, etc.), connect only after wiring the script into your consent flow so the cookies fire after consent, and declare the `_vk_*` cookies in your cookie policy. AI bot tracking is a separate setting that is off by default and sets no visitor cookies.

= Does it work on WordPress Multisite? =
Yes. On a network-activated install, each subsite gets its own rewrite rules, and sites created later have rules registered automatically. The plugin also detects Multisite when deciding whether to write `llms.txt` to the site root, so each subsite serves its own file at its own URL.


== Changelog ==

= 8.4.3 =

✨ New: All in One SEO meta descriptions

• The plugin now pulls meta descriptions from All in One SEO (AIOSEO) when "Include post excerpts / meta descriptions" is enabled, in addition to the existing Yoast SEO, Rank Math, and Slim SEO support. AIOSEO smart tags (for example #post_title) are rendered to their final text. Previously only AIOSEO's index/noindex rules were honored and its descriptions were skipped. After updating, regenerate the file (Settings → LLMs.txt → Generate Now). Thanks to the reporter on the wp.org support forum.

= 8.4.2 =

🐛 Encoding fix

• Fixed garbled non-ASCII text (mojibake) in `llms.txt` that could appear after updating to 8.4.1, for example Cyrillic, Greek, or CJK content rendering as `Ð...` sequences. The 8.4.1 release removed the UTF-8 byte-order mark, which on many servers was the only signal telling browsers the statically served file is UTF-8; without it the file could be mis-read as Latin-1. The BOM has been restored (it does not affect the "missing H1" validators, which were tripped by the `---` separators removed in 8.4.1), and the virtual file route now also sends an explicit `text/plain; charset=utf-8` header. Update and regenerate (Settings → LLMs.txt → Generate Now) to refresh the file. Thanks to the reporter on the wp.org support forum.

= 8.4.1 =

🐛 Fixes & compliance

• Removed the `---` horizontal-rule separators from the generated `llms.txt`. Some validators (Google Lighthouse "Agent Accessibility", Semrush Site Audit) read an early `---` as a YAML front-matter delimiter and discarded the `# Title`, reporting a false "missing H1". The file now uses `##` section headings only, matching the llmstxt.org format. Thanks to the reporter on the wp.org support forum.
• Removed the UTF-8 byte-order mark (BOM) from the start of the file. The first byte is now the `#` H1 marker, which further improves compatibility with strict Markdown and llms.txt validators.
• Documentation: added FAQ entries clarifying that the plugin sets no cookies until you opt in to Visibility Kit, listing the `_vk_*` cookies for cookie-policy/CMP declaration, and confirming WordPress Multisite support.
• Condensed older changelog entries (7.1.6 and earlier) to stay within wp.org's readme length limit.

= 8.4.0 =

🤖 Server-side bot classification

• Bot taxonomy is now sourced from the Visibility Kit API (cached for 24h via a transient) rather than baked into the plugin. New bots and reclassifications take effect without a plugin update.
• Telemetry payloads now include the raw User-Agent string. The server is the source of truth for bot/botType.
• Bundled fallback list still ships with the plugin so detection keeps working when the API is unreachable.
• Internal: per-page-load detection now iterates the API list in (priority DESC, length DESC) order so longer/more-specific UA matches win.

= 8.3.4 =

🐛 Fix

• Resolves a fatal error ("Call to a member function setup() on null") that could occur when Rank Math is active but its setup wizard has not been completed. The Rank Math integration now also checks that `rank_math()->variables` is initialized before calling it. Thanks to the reporter on the wp.org support forum.

= 8.3.3 =

🚀 New: Visibility Kit AI referral tracking

• Connect the plugin with an email to track AI referral sessions and AI search bot indexing for your site.
• New in-plugin "AI Search Traffic" widget showing referral session counts for ChatGPT, Claude, Gemini, and Perplexity.
• Announcement banner renders across wp-admin inviting connection, with a persistent per-user dismiss.
• If a domain was previously connected under a different email, the plugin now offers an inline "Take it over with this email" flow. No support ticket required.

🤖 Expanded AI bot detection

• Reclassified bots into user-action, search-indexing, and training categories.
• Added ChatGPT-User, Claude-User, Perplexity-User, OAI-SearchBot, Claude-SearchBot, Applebot, TikTokSpider, Meta-ExternalFetcher, Meta-ExternalAgent, and more.
• Detection now runs on every page load (not only /llms.txt), with per-bot-per-page-per-hour throttling on remote telemetry.
• Local bot log format migrated to a keyed-by-bot structure with counts; existing data migrates automatically on first read.

🛡️ Security and code quality

• All POST/SERVER superglobal reads now use wp_unslash() with appropriate sanitizers (sanitize_text_field, sanitize_email, esc_url_raw).
• Admin page outputs escaped with esc_attr(), esc_textarea(), and esc_html() in place of raw echo and <?= short-echo tags.
• Translator comments added for printf'd strings containing HTML tags.
• parse_url() → wp_parse_url(), unlink() → wp_delete_file(), strip_tags() → wp_strip_all_tags(), date() → gmdate() for UTC outputs.

🧹 Housekeeping

• Added VK option cleanup (embed token, connected email, summary cache) to uninstall.
• Domain Path header now resolves to an included languages/ directory.
• Tested up to WordPress 6.9; reduced to 5 tags for wp.org compliance.

= 8.2.8 =

🔧 Fixes and integrations previously staged in source control

• Flywheel-aware cleanup: root llms.txt is now deleted correctly from Flywheel's split www/ directory layout on plugin deactivation and uninstall, alongside the uploads-dir copies.
• Slim SEO integration: respects Slim SEO's per-post noindex flag when generating llms.txt, and pulls Slim SEO meta descriptions when available.
• Multisite activation: network-wide activate handles each subsite's rewrite rules, and new subsites created afterward get rules installed automatically via wp_initialize_site.

= 8.2.7 =

🔒 Security: Hardened admin interface against potential XSS vectors

• Improved sanitization and escaping for dynamic post type labels used in admin form fields.
• Replaced label-based array keys with post type slugs to prevent attribute injection risks.
• Ensures all dynamic values used in HTML attributes are properly escaped with esc_attr().
• Prevents potential stored XSS scenarios caused by maliciously registered custom post type labels.
• Minor stability improvements to avoid PHP notices when settings values are missing.

= 8.2.6 =

🛠 Fix: Correct WPML slugs and duplicate URLs in llms.txt

• Fixed an issue where original language slugs (e.g. .de) were duplicated and appeared for both original and translated pages.
• The generator now resolves the real WPML permalink for each language, instead of reusing the source language slug.
• Each language entry is now written with its own correct localized URL (no mixed or duplicated slugs).
• Prevents cases where translated pages were listed with the original language URL.
• Ensures llms.txt contains only valid, language-correct links for all WPML translations.

= 8.2.5 =

🛠 **Fix: Multilingual llms.txt generation with WPML**

• The generated `llms.txt` file now contains **all WPML language versions at once**.
• Each language is rendered with its **correct localized permalink** (`/en/`, `/ro/`, etc.).
• The output is **no longer dependent on the currently viewed language**.
• This ensures that a single `llms.txt` file always exposes **all valid multilingual URLs**, regardless of which language version is accessed.

Result:

* One unified `llms.txt`
* All WPML languages included
* All links resolve correctly
* No missing or fallback-to-default-language URLs

= 8.2.4 =

🛠 Improvement: Gravity Forms exclusion control

• Added an option to exclude Gravity Forms form fields from the generated llms.txt output.
• When disabled, all Gravity Forms markup (`<form id="gform_...">`, wrappers, and fields) is completely removed before file generation.
• Prevents unintended exposure of form structure and field labels in llms.txt.

= 8.2.3 =

📝 Update: README.txt improvements
• Updated the link for “All websites counter & experiment details” to the new, correct URL.
• Minor text adjustments for clarity and consistency within the documentation.

= 8.2.2 =

🛠 Fix: PHP Fatal Error (ArgumentCountError)
• Fixed the issue: Fatal error: Uncaught ArgumentCountError: 5 arguments are required, 3 given in admin-page.php:356

= 8.2.1 =

🛠 Fix: PHP Fatal Error (ArgumentCountError)
• Fixed the issue: Fatal error: Uncaught ArgumentCountError: 5 arguments are required, 3 given in admin-page.php:356
• The error occurred because printf() was used with a translatable string that expected more placeholders than arguments provided.
• Replaced it with a safe sprintf() and wp_kses_post() implementation to properly escape HTML and ensure compatibility with PHP 8.x.

= 8.2.0 =

🧩 New: LLMs.txt Reset Block
• Added a new “LLMs.txt Reset” section in the settings panel.
• Allows safely deleting and recreating the llms.txt file.
• Clears any related transient cache entries.
• Automatically rebuilds a fresh version of llms.txt based on current settings and published content.

📝 Improved Field Descriptions for Custom LLMs.txt Content
• Updated admin field labels and descriptions for better clarity:
• Title: manually define the title for the generated file.
• Description: add an introductory section before URLs.
• After Description: insert optional text before the list of links.
• End File Description: append footer text (e.g., disclaimer or contact info).

⚙️ Enhancement:
• Improved layout consistency and help text readability across the settings panel.


= 8.1.9 =

✨ New: SEOPress Support
• Added compatibility with SEOPress plugin for meta data handling.

✨ Improvement: Title Generation
• Refactored title generation. Titles are now fetched dynamically from the actual page to ensure accuracy.

✨ Enhancement: Admin Panel UX
• Added a progress bar for the “Generate Now” process in the admin panel for better visibility of ongoing tasks.

= 8.1.8 =

✨ Improvement: Hidden Posts Exclusion
• Posts and products marked with WooCommerce catalog visibility settings “exclude-from-catalog” or “exclude-from-search” are now excluded from being listed in llms.txt.
• Ensures that items set to Hidden, Shop only, or Search results only do not appear in the generated llms.txt file.
• Aligns llms.txt output with WooCommerce visibility rules for better consistency and control.

= 8.1.7 =

🐞 Fixed: XML Sitemap Stylesheet Issues
• Fixed an issue where llms-sitemap.xml displayed a blank page in Chrome/Edge or the error Parsing an XSLT stylesheet failed in Firefox.
• Added a check to ensure the stylesheet file (main-sitemap.xsl) exists before including it. If missing, the XML now loads correctly without the XSL.
• Improved cross-browser compatibility for displaying XML sitemaps.

✨ New: Post Type Customization in llms.txt
• Added support for customizing post type display names in the llms.txt file.
• Developers can now provide more descriptive or human-friendly titles for each custom post type section, improving clarity for both search engines and users.

= 8.1.6 =

🛠 Improved: Extensibility & Performance
• Added filter llms_generator_get_post_meta_description to make it easier to extend or replace the logic for retrieving page/post descriptions (e.g. integrating with Yoast, RankMath, or custom SEO functions).
• Added new filter to control which database index/field is used when building the llms.txt file, giving developers more flexibility for performance tuning and custom setups.

= 8.1.5 =

📝 New: Custom Description Field per Page/Post
• Added a new “Description” textarea field to the llms.txt metabox on individual pages/posts.
• This allows site admins to manually override the default description shown in the llms.txt output.
• Useful for precise control over how content is described or interpreted by LLMs and search engines.

🐛 Fix: Missing Description Field UI
• Fixed an issue where the changelog referenced a description field, but it was not visible in the admin UI unless specific settings were enabled.
• Now shown whenever page-level llms.txt settings are active.

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
• Fixed the behavior of the “Do not include this page in llms.txt” checkbox. Now, when activated, the page is correctly excluded from the generated llms.txt file.

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
• Updated the meta box to include: llms.txt heading, .md upload field, and the new exclusion checkbox, all neatly organized.
• Ensured the exclusion setting and uploaded .md file are saved correctly and reflected in llms.txt.
• Minor UI polishing and accessibility improvements to align with WordPress admin styles.

= 8.0.1 =

✨ Enhancements & Options: More Flexible LLMS.txt Content Control

• Changed default behavior: options Include meta information (publish date, author, etc.), Include post excerpts, and Include taxonomies (categories, tags, etc.) are now unchecked by default for cleaner output.
• Added a new option: Include detailed content, allowing fine-grained control over whether to include detailed page/post content in the llms.txt file.
• Improved settings clarity and fallback behavior when all optional content is disabled.

= 8.0.0 =

✨ New Features & Improvements: Admin UI, Content Options, Markdown

• Rearranged admin dashboard: moved warning section and update frequency settings into an “Advanced Settings” card for better clarity.
• Improved content settings: added checkboxes to control inclusion of post excerpts and meta descriptions in output, with cleaner fallback to just URL + Title when unchecked.
• Added a dedicated “Custom LLMS.txt Content” panel in settings for defining a custom Title, Description, After Description, and End File Description.
• Added custom description field and an additional manual entry field per page/post, both included in llms.txt.
• Added support for attaching `.md` (Markdown) files per page/post. Link to the file appears in llms.txt if enabled.
• `.md` files are stored in a dedicated `/llms_md/` folder and linked in llms.txt for reference.

= Older versions =

Changelog entries for versions 7.1.6 and earlier have been condensed to keep this file within wp.org's length limit. The full version history is preserved in the plugin source. Contact the plugin authors if you need details on an older release.
