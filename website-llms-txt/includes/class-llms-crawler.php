<?php

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Crawler
{
    public function __construct()
    {
        add_action('admin_init', function () {
            $settings = apply_filters('get_llms_generator_settings', []);
            if (!isset($settings['llms_local_log_enabled']) || !$settings['llms_local_log_enabled']) {
                $this->send_status(0);
            } else {
                $this->send_status(1);
            }
            register_setting('llms_options_group', 'llms_crawler_options');
        });

        add_action('init', array($this, 'init'));
    }

    public function init() {
        if (strpos($_SERVER['REQUEST_URI'], '/llms.txt') !== false) {
            $this->llms_check_ai_bot();
        }
    }

    public function llms_log_bot_visit($bot_name) {
        $log = get_option('llms_local_log', []);
        $log = array_filter($log, fn($row) => $row['bot'] !== $bot_name);

        $log[] = [
            'bot' => $bot_name,
            'seen' => current_time('mysql'),
        ];

        if (count($log) > 100) array_shift($log);

        update_option('llms_local_log', $log);
    }

    public function send_status( $active )
    {
        $need_send = true;
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $site_hash = hash('sha256', $domain);
        $enabled_status = get_option('llms_site_log_enabled_status');
        if(isset($enabled_status[$site_hash]) && $enabled_status[$site_hash] === $active) {
            $need_send = false;
        }

        if($need_send) {
            $array[$site_hash] = $active;
            update_option('llms_site_log_enabled_status', $array);
            wp_remote_post('https://llmstxt.ryanhoward.dev/api/site-status', [
                'method'  => 'POST',
                'timeout' => 5,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([
                    'site' => $site_hash,
                    'active'  => $active,
                ]),
            ]);
        }
    }

    public function llms_check_ai_bot() {
        $settings = apply_filters('get_llms_generator_settings', []);
        if (!isset($settings['llms_local_log_enabled']) || !$settings['llms_local_log_enabled']) {
            return;
        }

        $domain = parse_url(home_url(), PHP_URL_HOST);
        $site_hash = hash('sha256', $domain);

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $bots = $this->llms_get_known_bots();

        foreach ($bots as $agent => $info) {
            if (stripos($user_agent, $agent) !== false) {
                $this->llms_log_bot_visit($agent);

                $timestamp = current_time( 'mysql' );
                $iso_time  = date( DATE_ATOM, strtotime( $timestamp ) );

                wp_remote_post('https://llmstxt.ryanhoward.dev/api/stats', [
                    'method'  => 'POST',
                    'timeout' => 5,
                    'headers' => [
                    'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode([
                        'bot'  => $agent,
                        'site' => $site_hash,
                        'slug' => $info['slug'],
                        'date'  => $iso_time,
                    ]),
                ]);

                break;
            }
        }
    }

    public function llms_get_known_bots() {
        return [
            'GPTBot' => ['slug' => 'gptbot', 'type' => 'confirmed_ai'],
            'ChatGPT-User' => ['slug' => 'chatgpt_user', 'type' => 'confirmed_ai'],
            'ClaudeBot' => ['slug' => 'claudebot', 'type' => 'confirmed_ai'],
            'Claude-Web' => ['slug' => 'claude_web', 'type' => 'confirmed_ai'],
            'Anthropic' => ['slug' => 'anthropic', 'type' => 'confirmed_ai'],
            'PerplexityBot' => ['slug' => 'perplexity', 'type' => 'confirmed_ai'],
            'MistralAI-User' => ['slug' => 'mistralai', 'type' => 'confirmed_ai'],
            'Bytespider' => ['slug' => 'bytespider', 'type' => 'possible_ai'],
            'Amazonbot' => ['slug' => 'amazonbot', 'type' => 'possible_ai'],
            'Google-Extended' => ['slug' => 'google_extended', 'type' => 'confirmed_ai'],
            'GoogleOther' => ['slug' => 'googleother', 'type' => 'possible_ai'],
            'Googlebot' => ['slug' => 'googlebot', 'type' => 'standard'],
            'Bingbot' => ['slug' => 'bingbot', 'type' => 'standard'],
            'AhrefsBot' => ['slug' => 'ahrefsbot', 'type' => 'standard'],
            'SemrushBot' => ['slug' => 'semrushbot', 'type' => 'standard'],
            'CCBot' => [ 'slug' => 'ccbot', 'type' => 'confirmed_ai' ],
        ];
    }
}
