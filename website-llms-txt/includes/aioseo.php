<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * All in One SEO (AIOSEO) integration for Website LLMs.txt
 *
 * AIOSEO stores per-post SEO data in its own database table, and a post's
 * meta description can contain smart tags (e.g. #post_title, #site_title).
 * Rather than read the raw stored template, we ask AIOSEO's own runtime for
 * the effective, fully rendered description, mirroring how the Yoast and
 * Rank Math integrations behave. When AIOSEO is unavailable or has no
 * description for the post, we return the existing value unchanged so the
 * generator can fall back to its own excerpt/content logic.
 */

add_filter('llms_generator_get_post_meta_description', 'llms_aioseo_get_post_meta_description', 10, 2);
function llms_aioseo_get_post_meta_description( $meta_description, $post ) {
    if ( ! function_exists('aioseo') || ! is_object($post) || empty($post->ID) ) {
        return $meta_description;
    }

    $aioseo = aioseo();
    if ( ! isset($aioseo->meta) || ! isset($aioseo->meta->description)
        || ! method_exists($aioseo->meta->description, 'getPostDescription') ) {
        return $meta_description;
    }

    try {
        $description = $aioseo->meta->description->getPostDescription( $post );
    } catch ( \Throwable $e ) {
        return $meta_description;
    }

    $description = is_string($description) ? trim($description) : '';
    if ( $description !== '' ) {
        return $description;
    }

    return $meta_description;
}
