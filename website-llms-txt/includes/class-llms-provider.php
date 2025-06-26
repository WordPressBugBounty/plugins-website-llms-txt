<?php
if (!defined('ABSPATH')) {
    exit;
}

use RankMath\Sitemap\Providers\Provider;

/**
 * LLMS sitemap provider.
 */
class LLMS_Sitemap_Provider implements Provider
{
    /**
     * Check if provider supports given sitemap type.
     *
     * @param string $type Sitemap type.
     *
     * @return boolean
     */
    public function handles_type($type)
    {
        return 'llms' === $type;
    }

    /**
     * Get sitemap index links for the sitemap.
     *
     * @param int $max_entries Maximum number of entries per sitemap.
     *
     * @return array
     */
    public function get_index_links($max_entries)
    {
        $settings = apply_filters('get_llms_generator_settings', []);
        if($settings['llms_allow_indexing']) {
            $latest_post = apply_filters('get_llms_content', '');
            if (empty($latest_post)) {
                return [];
            }

            return [
                [
                    'loc' => \RankMath\Sitemap\Router::get_base_url('llms-sitemap.xml'),
                    'lastmod' => get_post_modified_time('c', true, $latest_post[0]),
                ]
            ];
        } else {
            return [];
        }
    }

    /**
     * Get sitemap entries for the sitemap.
     *
     * @param string $type Sitemap type.
     * @param int $max_entries Maximum number of entries per sitemap.
     * @param int $current_page Current page of the sitemap.
     *
     * @return array
     */
    public function get_sitemap_links($type, $max_entries, $current_page)
    {
        $settings = apply_filters('get_llms_generator_settings', []);
        if($settings['llms_allow_indexing']) {
            $latest_post = apply_filters('get_llms_content', '');

            if (empty($latest_post)) {
                return [];
            }

            return [
                [
                    'loc' => home_url('/llms.txt'),
                    'lastmod' => get_post_modified_time('c', true, $latest_post[0]),
                    'changefreq' => 'weekly',
                    'priority' => 0.8
                ]
            ];
        } else {
            return [];
        }
    }
}