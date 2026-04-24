<?php

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_VisibilityKit
{
    public function __construct()
    {
        // AJAX handlers for connect/disconnect
        add_action('wp_ajax_vk_connect', array($this, 'handle_vk_connect'));
        add_action('wp_ajax_vk_disconnect', array($this, 'handle_vk_disconnect'));

        // Frontend vk-core.js injection
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
    }

    /**
     * Handle the Visibility Kit connect AJAX request.
     */
    public function handle_vk_connect() {
        check_ajax_referer('vk_connect_nonce', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
        }

        $takeover_raw = isset($_POST['takeover']) ? sanitize_text_field(wp_unslash($_POST['takeover'])) : '';
        $takeover = !empty($takeover_raw) && $takeover_raw !== 'false' && $takeover_raw !== '0';
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);

        $payload = [
            'email'         => $email,
            'domain'        => $domain,
            'source'        => 'llmstxt-plugin',
            'pluginVersion' => defined('LLMS_VERSION') ? LLMS_VERSION : 'unknown',
        ];
        if ($takeover) {
            $payload['takeover'] = true;
        }

        $response = wp_remote_post('https://api.visibilitykit.ai/api/v1/plugin/connect', [
            'method'  => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && !empty($body['success'])) {
            update_option('vk_embed_token', sanitize_text_field($body['embedToken']));
            update_option('vk_client_id', sanitize_text_field($body['clientId']));
            update_option('vk_connected_email', $email);

            // Auto-enable bot tracking on connect
            $settings = get_option('llms_generator_settings', []);
            if (empty($settings['llms_local_log_enabled'])) {
                $settings['llms_local_log_enabled'] = 1;
                update_option('llms_generator_settings', $settings);
            }

            wp_send_json_success([
                'message' => $body['message'] ?? 'Connected successfully.',
            ]);
        } else {
            $payload = [
                'message' => !empty($body['message']) ? $body['message'] : 'Connection failed.',
            ];
            if (!empty($body['error'])) {
                $payload['error'] = $body['error'];
            }
            if (!empty($body['canTakeover'])) {
                $payload['canTakeover'] = true;
            }
            wp_send_json_error($payload);
        }
    }

    /**
     * Handle the Visibility Kit disconnect AJAX request.
     */
    public function handle_vk_disconnect() {
        check_ajax_referer('vk_connect_nonce', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        delete_option('vk_embed_token');
        delete_option('vk_client_id');
        delete_option('vk_connected_email');
        delete_option('vk_summary_data');
        delete_transient('vk_summary_fresh');

        // Disable bot tracking on disconnect
        $settings = get_option('llms_generator_settings', []);
        $settings['llms_local_log_enabled'] = 0;
        update_option('llms_generator_settings', $settings);

        wp_send_json_success();
    }

    /**
     * Enqueue vk-core.js on the frontend when connected.
     */
    public function enqueue_frontend_scripts() {
        if (is_admin()) {
            return;
        }

        $embed_token = get_option('vk_embed_token');
        if (empty($embed_token)) {
            return;
        }

        wp_enqueue_script(
            'vk-core',
            'https://cdn.visibilitykit.ai/t/' . sanitize_text_field($embed_token) . '/vk.js',
            [],
            LLMS_VERSION,
            true
        );
    }
}
