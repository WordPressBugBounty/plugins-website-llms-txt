<?php

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_MD
{
    public function __construct()
    {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_post' ) );
        add_action( 'post_edit_form_tag', array( $this, 'post_edit_form_tag' ) );
        add_filter( 'upload_mimes', array( $this, 'upload_mimes' ) );
    }

    public function upload_mimes( $mimes ) {
        $mimes['md'] = 'text/plain';
        return $mimes;
    }

    public function post_edit_form_tag() {
        echo ' enctype="multipart/form-data"';
    }

    public function add_meta_boxes() {
        $settings = apply_filters('get_llms_generator_settings', []);
        if(isset($settings['include_md_file']) && $settings['include_md_file']) {
            add_meta_box( 'md_upload', __('Llms.txt', 'website-llms-txt'), function ( $post ) {
                $md_url = get_post_meta( $post->ID, '_md_url', true );
                $md_toggle = get_post_meta( $post->ID, '_llmstxt_page_md', true );
                wp_nonce_field( 'save_md_file', 'md_file_nonce' );
                ?>
                <label class="switch">
                    <input type="checkbox" name="llmstxt-page-md" <?php checked( $md_toggle, 'yes' ); ?> />
                    <span class="slider"></span>
                    <?php esc_html_e('Do not include this page in llms.txt', 'website-llms-txt'); ?>
                </label>
                <style>
                    .switch {
                        display: inline-flex;
                        align-items: center;
                        font-family: sans-serif;
                        font-size: 14px;
                        cursor: pointer;
                        gap: 8px;
                    }

                    .switch input {
                        display: none;
                    }

                    .slider {
                        position: relative;
                        width: 40px;
                        height: 20px;
                        background-color: #ccc;
                        border-radius: 20px;
                        transition: 0.4s;
                    }

                    .slider::before {
                        content: "";
                        position: absolute;
                        left: 2px;
                        top: 2px;
                        width: 16px;
                        height: 16px;
                        background-color: #fff;
                        border-radius: 50%;
                        transition: 0.4s;
                    }

                    input:checked + .slider {
                        background-color: #2271b1;
                    }

                    input:checked + .slider::before {
                        transform: translateX(20px);
                    }
                </style>
                <p><?php esc_html_e('Upload a .md file for this page/post.', 'website-llms-txt'); ?></p>
                <input type="file" name="md_file">
                <?php if ( $md_url ) : ?>
                    <p><?php esc_html_e('Current:', 'website-llms-txt'); ?> <a href="<?= esc_url( $md_url ) ?>" target="_blank"><?= basename( $md_url ) ?></a></p>
                <?php endif; ?>
                <?php if($md_url): ?>
                    <button type="submit" name="delete_md_file" value="1" class="button button-secondary">
                        Delete file
                    </button>
                <?php endif; ?>
                <?php
            },null,'side' );
        }
    }

    public function save_post( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['md_file_nonce'] ) || ! wp_verify_nonce( $_POST['md_file_nonce'], 'save_md_file' ) ) {
            return;
        }

        if ( isset( $_POST['llmstxt-page-md'] ) ) {
            update_post_meta( $post_id, '_llmstxt_page_md', 'yes' );
        } else {
            delete_post_meta( $post_id, '_llmstxt_page_md' );
        }

        if ( isset( $_POST['delete_md_file'] ) && $_POST['delete_md_file'] == '1' ) {

            $md_url = get_post_meta( $post_id, '_md_url', true );
            if ( $md_url ) {
                $md_path = $this->get_path_from_url( $md_url );
                if ( file_exists( $md_path ) ) {
                    unlink( $md_path );
                }
                delete_post_meta( $post_id, '_md_url' );
            }

            return;
        }

        if ( isset( $_FILES['md_file'] ) && ! empty( $_FILES['md_file']['tmp_name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            add_filter( 'upload_dir', [ $this, 'llms_md_upload_dir' ] );
            $uploaded = wp_handle_upload( $_FILES['md_file'], [
                'test_form' => false
            ] );
            remove_filter( 'upload_dir', [ $this, 'llms_md_upload_dir' ] );
            if ( ! isset( $uploaded['error'] ) ) {
                update_post_meta( $post_id, '_md_url', esc_url_raw( $uploaded['url'] ) );
            }
        }
    }

    private function get_path_from_url( $url ) {
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $url, $base_url ) !== false ) {
            $relative_path = str_replace( $base_url, '', $url );
            return $base_dir . $relative_path;
        }

        return false;
    }

    public function llms_md_upload_dir( $dirs ) {
        $subdir = '/llms_md';

        $dirs['subdir'] = $subdir;
        $dirs['path']   = $dirs['basedir'] . $subdir;
        $dirs['url']    = $dirs['baseurl'] . $subdir;

        return $dirs;
    }
}