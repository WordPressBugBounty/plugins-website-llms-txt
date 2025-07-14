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
        add_meta_box( 'md_upload', 'Markdown (.md) file', function ( $post ) {
            $md_url = get_post_meta( $post->ID, '_md_url', true );
            wp_nonce_field( 'save_md_file', 'md_file_nonce' );
            ?>
            <p><?php esc_html_e('Upload a .md file for this page/post.', 'website-llms-txt'); ?></p>
            <input type="file" name="md_file">
            <?php if ( $md_url ) : ?>
                <p><?php esc_html_e('Current:', 'website-llms-txt'); ?> <a href="<?= esc_url( $md_url ) ?>" target="_blank"><?= basename( $md_url ) ?></a></p>
            <?php endif; ?>
            <?php
        } );
    }

    public function save_post( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['md_file_nonce'] ) || ! wp_verify_nonce( $_POST['md_file_nonce'], 'save_md_file' ) ) {
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

    public function llms_md_upload_dir( $dirs ) {
        $subdir = '/llms_md';

        $dirs['subdir'] = $subdir;
        $dirs['path']   = $dirs['basedir'] . $subdir;
        $dirs['url']    = $dirs['baseurl'] . $subdir;

        return $dirs;
    }
}