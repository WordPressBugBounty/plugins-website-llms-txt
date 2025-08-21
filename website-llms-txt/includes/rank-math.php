<?php
if (!defined('ABSPATH')) {
    exit;
}

// Include the provider class
require_once plugin_dir_path(__FILE__) . 'class-llms-provider.php';

/**
 * Register the LLMS sitemap provider with Rank Math
 */
add_filter('rank_math/sitemap/providers', function($providers) {
    $providers['llms'] = new LLMS_Sitemap_Provider();
    return $providers;
});

/**
 * Clear SEO plugin sitemap caches when LLMS.txt is updated
 */
add_action('llms_clear_seo_caches_rank_math', function() {
    // Clear RankMath cache if active
    if (class_exists('\RankMath\Sitemap\Cache')) {
        \RankMath\Sitemap\Cache::invalidate_storage();
    }

    // Clear Yoast cache if active
    if (class_exists('WPSEO_Sitemaps_Cache')) {
        WPSEO_Sitemaps_Cache::clear();
    }
});

add_action('llms_clear_seo_caches', function() {
    // Clear RankMath cache if active
    if (class_exists('\RankMath\Sitemap\Cache')) {
        \RankMath\Sitemap\Cache::invalidate_storage();
    }
    
    // Clear Yoast cache if active
    if (class_exists('WPSEO_Sitemaps_Cache')) {
        WPSEO_Sitemaps_Cache::clear();
    }
});

// Explicitly exclude from sitemap generation
add_filter('rank_math/sitemap/exclude_post_type', function($exclude, $post_type) {
    if ($post_type === 'llms_txt') {
        return true;
    }
    return $exclude;
}, 20, 2);

add_filter('llms_generator_get_post_meta_description', 'llm_rank_math_compatibility_get_post_meta_description', 10, 2);
function llm_rank_math_compatibility_get_post_meta_description( $meta_description, $post ) {
    if (class_exists('RankMath')) {
        // Try using RankMath's helper class first
        if (class_exists('RankMath\Helper')) {
            $desc = RankMath\Helper::get_post_meta('description', $post->ID);
            if (!empty($desc)) {
                return $desc;
            }
        }

        // Fallback to Post class if Helper doesn't work
        if (class_exists('RankMath\Post\Post')) {
            return RankMath\Post\Post::get_meta('description', $post->ID);
        }
    }
    return $meta_description;
}

add_filter('llms_generator_get_site_meta_description', 'llm_rank_math_get_site_meta_description', 10);
function llm_rank_math_get_site_meta_description( $site_description ) {
    if (class_exists('RankMath')) {
        $rank_math_description = get_option('rank_math_description');
        if($rank_math_description) {
            $site_description = $rank_math_description;
        }
    }

    return $site_description;
}