<?php
if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Yoast_Integration {
    private function __construct() {
        add_action('init', array($this, 'add_rewrite_rules'), 1);
        add_action('init', array($this, 'maybe_generate_sitemap'), 999);
        add_filter('wpseo_sitemap_index', array($this, 'add_to_index'));
        add_filter('wpseo_sitemap_llms_content', array($this, 'generate_sitemap'));
        add_action('llms_clear_seo_caches', array($this, 'clear_sitemap_cache'));
        add_filter('query_vars', array($this, 'query_vars'));
        add_filter('llms_generator_get_post_meta_description', array($this, 'get_post_meta_description'), 10,2);
        add_filter('llms_generator_get_site_meta_description', array($this, 'get_site_meta_description'), 10);
    }

    public function get_site_meta_description( $site_description ) {
        // Guard on YoastSEO(), not on WPSEO_Options. WPSEO_Options has existed since
        // Yoast 1.5; YoastSEO() arrived in Yoast 14.0, so testing the old symbol and
        // then calling the new one is a fatal on every generation for anyone on an
        // older Yoast. Same shape as the str_contains() defect fixed in this release:
        // a newer symbol called behind a guard for an older one.
        //
        // DO NOT reach for isset() or empty() on ->description. Yoast's Meta::__get()
        // falls back to the context object when the presentation has no such property,
        // and Meta::__isset() does not. `description` is a context property
        // (Meta_Tags_Context::generate_description()); Indexable_Presentation declares
        // meta_description, open_graph_description and twitter_description, but no
        // description. So on every modern Yoast the value is readable while isset() is
        // false, and empty() is false too because empty() calls __isset() first. An
        // earlier 8.5.4 revision used isset() here and silently dropped the site
        // description out of the file on every Yoast site. Measured on Yoast 28.1.
        //
        // Reading into a variable first is 8.5.3's own shape and is what makes this
        // correct: it calls __get() and nothing else.
        if (function_exists('YoastSEO')) {
            $yoast = YoastSEO();
            $meta = isset($yoast->meta) ? $yoast->meta->for_posts_page() : null;
            $yoast_description = is_object($meta) ? $meta->description : null;
            if($yoast_description) {
                $site_description = $yoast_description;
            }
        }
        return $site_description;
    }

    // DELIBERATELY NOT CHANGED IN 8.5.4, and not an oversight.
    //
    // This carries the same isset() defect described above, so on every modern Yoast
    // it always falls through and this plugin has never used a Yoast per-post
    // description. That is a real bug and it is pre-existing: 8.5.3 has this exact
    // line.
    //
    // Correcting it here would start injecting Yoast's per-post descriptions into the
    // generated file on every site running Yoast, which is a broad output change on a
    // large population, in a release whose whole discipline is that a site with no
    // gating plugin sees no change it did not ask for. Restoring the sibling above is
    // putting back something 8.5.3 did; changing this one would be shipping something
    // 8.5.3 never did, under cover of a security release.
    //
    // Fix it in 8.6.0, where it can be its own change with its own changelog line.
    public function get_post_meta_description( $meta_description, $post ) {
        if (function_exists('YoastSEO') && isset(YoastSEO()->meta, YoastSEO()->meta->for_post($post->ID)->description)) {
            return YoastSEO()->meta->for_post($post->ID)->description;
        }
        return $meta_description;
    }

    public function query_vars( $vars ) {
        $vars[] = 'sitemap';
        return $vars;
    }

    public function add_rewrite_rules() {
        global $wp_rewrite;
        $existing_rules = $wp_rewrite->wp_rewrite_rules();
        if (!isset($existing_rules['^llms-sitemap\.xml$'])) {
            add_rewrite_rule('^llms-sitemap\.xml$', 'index.php?sitemap=llms', 'top');
        }
    }

    public function maybe_generate_sitemap() {
        if(isset($_SERVER['REQUEST_URI'])) {
            $request_uri = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])));
            if (isset($request_uri['path']) && $request_uri['path'] == '/llms-sitemap.xml') {
                status_header(200);
                header('Content-Type: application/xml; charset=utf-8');
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generate_sitemap() returns XML with all dynamic values pre-escaped via esc_url / esc_xml.
                echo $this->generate_sitemap();
                exit;
            }
        }
    }

    public function generate_sitemap() {
        $settings = apply_filters('get_llms_generator_settings', []);
        if(isset($settings['llms_allow_indexing']) && $settings['llms_allow_indexing']) {
            $latest_post = get_posts([
                'post_type' => 'llms_txt',
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);

            if (empty($latest_post) && !class_exists('WPSEO_Sitemaps_Renderer')) {
                return '';
            }

            $url = array(
                'loc' => home_url('/llms.txt'),
                'lastmod' => get_post_modified_time('c', true, $latest_post[0]),
                'changefreq' => 'weekly',
                'priority' => '0.8'
            );

            $loc = esc_url($url['loc']);
            $lastmod = esc_xml($url['lastmod']);
            $changefreq = esc_xml($url['changefreq']);
            $priority = esc_xml($url['priority']);


            $sitemap = sprintf(
                '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">' . "\n" .
                '    <url>' . "\n" .
                '        <loc>%1$s</loc>' . "\n" .
                '        <lastmod>%2$s</lastmod>' . "\n" .
                '        <changefreq>%3$s</changefreq>' . "\n" .
                '        <priority>%4$s</priority>' . "\n" .
                '    </url>' . "\n" .
                '</urlset>',
                $loc,
                $lastmod,
                $changefreq,
                $priority
            );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Yoast sitemap renderer emits pre-escaped XML.
            echo (new WPSEO_Sitemaps_Renderer())->get_output( $sitemap );
        }
    }

    public function add_to_index($sitemap) {
        $settings = apply_filters('get_llms_generator_settings', []);
        if(isset($settings['llms_allow_indexing']) && $settings['llms_allow_indexing']) {
            $latest_post = get_posts([
                'post_type' => 'llms_txt',
                'posts_per_page' => 1,
                'post_status' => 'publish'
            ]);

            if (!empty($latest_post)) {
                $entry = "\n<sitemap>";
                $entry .= "\n\t<loc>" . esc_url(home_url('llms-sitemap.xml')) . "</loc>";
                $entry .= "\n\t<lastmod>" . esc_xml(get_post_modified_time('c', true, $latest_post[0])) . "</lastmod>";
                $entry .= "\n</sitemap>\n";
                return $sitemap . $entry;
            }
        }

        return $sitemap;
    }

    public function clear_sitemap_cache() {
        do_action('wpseo_cache_clear_sitemap');
    }

    public static function get_instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }
}

LLMS_Yoast_Integration::get_instance();