<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Slim SEO integration for Website LLMs.txt
 *
 * Slim SEO stores all post-level SEO meta in a single serialized array
 * under the 'slim_seo' post meta key. Fields: title, description, noindex.
 */

add_filter('llms_generator_get_post_meta_description', 'llms_slim_seo_get_post_meta_description', 10, 2);
function llms_slim_seo_get_post_meta_description( $meta_description, $post ) {
    $data = get_post_meta($post->ID, 'slim_seo', true);
    if (!empty($data['description'])) {
        return $data['description'];
    }
    return $meta_description;
}
