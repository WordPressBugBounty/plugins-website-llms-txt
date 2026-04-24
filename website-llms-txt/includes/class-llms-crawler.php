<?php

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Crawler
{
    public function __construct()
    {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        $settings = apply_filters('get_llms_generator_settings', []);
        if (empty($settings['llms_local_log_enabled'])) {
            return;
        }
        $this->llms_check_ai_bot();
    }

    public function llms_log_bot_visit($bot_name, $page = '/') {
        // Update per-bot summary log (keyed by bot name)
        $log = get_option('llms_local_log', []);

        // Handle migration from old format (array of {bot, seen})
        if (!empty($log) && isset(reset($log)['bot']) && !is_string(key($log))) {
            $migrated = [];
            foreach ($log as $row) {
                if (isset($row['bot'])) {
                    $migrated[$row['bot']] = [
                        'count' => $row['count'] ?? 1,
                        'seen'  => $row['seen'] ?? '',
                        'type'  => $row['type'] ?? '',
                    ];
                }
            }
            $log = $migrated;
        }

        $bots = $this->llms_get_known_bots();
        $type = isset($bots[$bot_name]) ? $bots[$bot_name]['type'] : '';

        $prev_count = isset($log[$bot_name]) ? (int) ($log[$bot_name]['count'] ?? 0) : 0;

        $log[$bot_name] = [
            'count' => $prev_count + 1,
            'seen'  => current_time('mysql'),
            'type'  => $type,
        ];

        // Cap at 100 bots
        if (count($log) > 100) {
            $log = array_slice($log, -100, 100, true);
        }

        update_option('llms_local_log', $log);

        // Update daily hit counter
        $today = current_time('Y-m-d');
        $daily = get_option('llms_bot_hits_today', ['date' => '', 'count' => 0]);
        if ($daily['date'] !== $today) {
            $daily = ['date' => $today, 'count' => 0];
        }
        $daily['count']++;
        update_option('llms_bot_hits_today', $daily);
    }

    public function llms_check_ai_bot() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        if (empty($user_agent)) {
            return;
        }

        $bots = $this->llms_get_known_bots();
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $page_path = $request_uri ? (wp_parse_url($request_uri, PHP_URL_PATH) ?: '/') : '/';

        foreach ($bots as $agent => $info) {
            if (stripos($user_agent, $agent) !== false) {
                // Always log locally
                $this->llms_log_bot_visit($agent, $page_path);

                // Throttle remote POST: once per bot+page per hour
                $throttle_key = 'vk_bot_' . md5($agent . $page_path);
                if (get_transient($throttle_key)) {
                    return;
                }
                set_transient($throttle_key, 1, HOUR_IN_SECONDS);

                // Send to Visibility Kit
                $domain = wp_parse_url(home_url(), PHP_URL_HOST);

                wp_remote_post('https://api.visibilitykit.ai/api/v1/telemetry', [
                    'method'    => 'POST',
                    'timeout'   => 5,
                    'blocking'  => false,
                    'headers'   => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => wp_json_encode([
                        'domain'        => $domain,
                        'bot'           => $agent,
                        'botType'       => $info['type'],
                        'page'          => $page_path,
                        'timestamp'     => gmdate(DATE_ATOM),
                        'pluginVersion' => defined('LLMS_VERSION') ? LLMS_VERSION : 'unknown',
                    ]),
                ]);

                break;
            }
        }
    }

    public function llms_get_known_bots() {
        // Order matters: more specific patterns must come before less specific ones
        // (e.g. OAI-SearchBot before GPTBot, Claude-SearchBot before ClaudeBot)
        return [
            // OpenAI
            'OAI-SearchBot'       => ['slug' => 'oai_searchbot',       'type' => 'search'],
            'ChatGPT-User'        => ['slug' => 'chatgpt_user',        'type' => 'user_action'],
            'GPTBot'              => ['slug' => 'gptbot',              'type' => 'training'],

            // Anthropic
            'Claude-SearchBot'    => ['slug' => 'claude_searchbot',    'type' => 'search'],
            'Claude-User'         => ['slug' => 'claude_user',         'type' => 'user_action'],
            'ClaudeBot'           => ['slug' => 'claudebot',           'type' => 'training'],
            'Claude-Web'          => ['slug' => 'claude_web',          'type' => 'training'],

            // Perplexity
            'PerplexityBot'       => ['slug' => 'perplexitybot',       'type' => 'search'],
            'Perplexity-User'     => ['slug' => 'perplexity_user',     'type' => 'user_action'],

            // Meta
            'Meta-ExternalFetcher' => ['slug' => 'meta_externalfetcher', 'type' => 'user_action'],
            'Meta-ExternalAgent'   => ['slug' => 'meta_externalagent',   'type' => 'training'],

            // Mistral
            'MistralAI-User'      => ['slug' => 'mistralai_user',      'type' => 'user_action'],

            // Google
            'Google-Extended'     => ['slug' => 'google_extended',     'type' => 'training'],
            'GoogleOther'         => ['slug' => 'googleother',         'type' => 'search'],

            // Apple
            'Applebot'            => ['slug' => 'applebot',            'type' => 'search'],

            // Amazon
            'Amazonbot'           => ['slug' => 'amazonbot',           'type' => 'search'],

            // Other
            'Bytespider'          => ['slug' => 'bytespider',          'type' => 'training'],
            'CCBot'               => ['slug' => 'ccbot',               'type' => 'training'],
            'TikTokSpider'        => ['slug' => 'tiktokspider',        'type' => 'user_action'],
            'PetalBot'            => ['slug' => 'petalbot',            'type' => 'search'],
            'YouBot'              => ['slug' => 'youbot',              'type' => 'search'],
        ];
    }
}
