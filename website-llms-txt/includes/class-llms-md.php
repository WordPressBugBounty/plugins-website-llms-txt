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
                $custom_txt = get_post_meta($post->ID, '_llmstxt_custom_note', true);
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
                    <p><?php esc_html_e('Current:', 'website-llms-txt'); ?> <a href="<?php echo esc_url( $md_url ); ?>" target="_blank"><?php echo esc_html( basename( $md_url ) ); ?></a></p>
                <?php endif; ?>
                <?php if($md_url): ?>
                    <button type="submit" name="delete_md_file" value="1" class="button button-secondary">
                        <?php esc_html_e('Delete file', 'website-llms-txt'); ?>
                    </button>
                <?php endif; ?>
                <hr style="margin: 15px 0;">
                <p><strong><?php esc_html_e('Custom llms.txt text', 'website-llms-txt'); ?></strong></p>
                <textarea name="llmstxt_custom_note" rows="4" style="width:100%;"><?php echo esc_textarea($custom_txt); ?></textarea>
                <p class="description"><?php esc_html_e('This text will be included in the llms.txt output for this post.', 'website-llms-txt'); ?></p>
                <?php
            },null,'side' );
        }
    }

    public function save_post( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! isset( $_POST['md_file_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['md_file_nonce'] ) ), 'save_md_file' ) ) {
            return;
        }

        // A nonce says the request came from our form. It does not say the person
        // sending it may edit this post: the nonce is tied to the action and the
        // session, not to the post id, so anybody who can reach a page carrying
        // one could send it back with somebody else's post id. Everything below
        // writes post meta and uploads a file against $post_id, so the
        // capability check belongs here, before any of it.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( isset( $_POST['llmstxt-page-md'] ) ) {
            update_post_meta( $post_id, '_llmstxt_page_md', 'yes' );
        } else {
            delete_post_meta( $post_id, '_llmstxt_page_md' );
        }

        if ( isset( $_POST['llmstxt_custom_note'] ) ) {
            update_post_meta($post_id, '_llmstxt_custom_note', sanitize_textarea_field( wp_unslash( $_POST['llmstxt_custom_note'] ) ));
        } else {
            delete_post_meta( $post_id, '_llmstxt_custom_note' );
        }

        if ( isset( $_POST['delete_md_file'] ) && $_POST['delete_md_file'] == '1' ) {

            $md_url = get_post_meta( $post_id, '_md_url', true );
            if ( $md_url ) {
                $md_path = $this->get_path_from_url( $md_url );
                if ( file_exists( $md_path ) ) {
                    wp_delete_file( $md_path );
                }
                delete_post_meta( $post_id, '_md_url' );
            }

            return;
        }

        if ( isset( $_FILES['md_file'] ) && ! empty( $_FILES['md_file']['tmp_name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $this->protect_md_dir();

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

    /**
     * Put index.php and .htaccess into uploads/llms_md/.
     *
     * The .md files in here are meant to be fetched: their URLs are written into
     * the generated llms.txt as Markdown links, so this is not about hiding them.
     * It is about the two things a writable upload directory should never do.
     *
     *  - index.php, so a request for the directory itself gets an empty page
     *    instead of a listing of every file in it. This is the part that works
     *    everywhere, including nginx, and it is why there is no Options -Indexes
     *    in the .htaccess: with an index file the listing is already gone, and
     *    Options is the directive most likely to be refused by AllowOverride and
     *    turn the whole directory into a 500.
     *  - .htaccess, so that a file that arrives here with an executable extension
     *    is served as bytes rather than run. upload_mimes() only adds .md, but
     *    the same directory is reachable by anything else that decides to write
     *    to it, and defence here costs nothing.
     *
     * Apache only. nginx and IIS ignore .htaccess entirely, which is exactly why
     * the index.php half is not skipped when it is written.
     *
     * Written once, when a file is first uploaded, and never rewritten, so an
     * administrator who edits either file keeps their edit.
     *
     * @return void
     */
    private function protect_md_dir() {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
            return;
        }

        $dir = trailingslashit( $upload_dir['basedir'] ) . 'llms_md';

        if ( ! wp_mkdir_p( $dir ) ) {
            return;
        }

        $htaccess = "# Files in this directory are linked from the generated llms.txt and are\n"
            . "# meant to be downloadable. This only stops anything here being executed.\n"
            . "<FilesMatch \"\\.(?i:php|php[0-9]|phtml|phps|phar|pl|py|cgi|asp|aspx|jsp|sh|shtml)$\">\n"
            . "  <IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "  </IfModule>\n"
            . "</FilesMatch>\n";

        $files = array(
            $dir . '/index.php' => "<?php\n// Silence is golden.\n",
            $dir . '/.htaccess' => $htaccess,
        );

        global $wp_filesystem;

        foreach ( $files as $path => $contents ) {
            if ( file_exists( $path ) ) {
                continue;
            }

            if ( $wp_filesystem ) {
                $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not available on this request and the alternative is leaving the directory unprotected.
                @file_put_contents( $path, $contents );
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