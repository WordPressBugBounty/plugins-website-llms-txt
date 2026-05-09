<?php

if (!defined('ABSPATH')) {
    exit;
}

class LLMS_Crawler
{
    const VK_API_BASE       = 'https://api.visibilitykit.ai';
    const BOT_LIST_TRANSIENT = 'vk_bot_list_v1';
    const BOT_LIST_TTL       = DAY_IN_SECONDS;
    const BOT_LIST_FAIL_TTL  = HOUR_IN_SECONDS;

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

    public function llms_log_bot_visit($bot_name, $page = '/', $type = '') {
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

        // Server is the source of truth for `type`. If the caller passed an
        // empty string (legacy fallback path) try to recover from the remote
        // list, then fall back to the bundled taxonomy.
        if ($type === '') {
            $remote = $this->llms_get_remote_bot_list();
            foreach ($remote as $row) {
                if (!empty($row['bot']) && $row['bot'] === $bot_name && !empty($row['botType'])) {
                    $type = $row['botType'];
                    break;
                }
            }
            if ($type === '') {
                $bots = $this->llms_get_known_bots();
                $type = isset($bots[$bot_name]) ? $bots[$bot_name]['type'] : '';
            }
        }

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

        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $page_path = $request_uri ? (wp_parse_url($request_uri, PHP_URL_PATH) ?: '/') : '/';

        $remote_bots = $this->llms_get_remote_bot_list();
        $matched = $this->llms_match_user_agent($user_agent, $remote_bots);
        if ($matched === null) {
            return;
        }

        $bot_name = $matched['bot'];
        $bot_type = isset($matched['botType']) ? $matched['botType'] : '';

        // Always log locally
        $this->llms_log_bot_visit($bot_name, $page_path, $bot_type);

        // Throttle remote POST: once per bot+page per hour
        $throttle_key = 'vk_bot_' . md5($bot_name . $page_path);
        if (get_transient($throttle_key)) {
            return;
        }
        set_transient($throttle_key, 1, HOUR_IN_SECONDS);

        // Send to Visibility Kit. Sending both `userAgent` (preferred by
        // Stage-5 server) and `bot` (used by Stage-2 server) keeps the
        // payload compatible across server versions.
        $domain = wp_parse_url(home_url(), PHP_URL_HOST);

        wp_remote_post(self::VK_API_BASE . '/api/v1/telemetry', [
            'method'    => 'POST',
            'timeout'   => 5,
            'blocking'  => false,
            'headers'   => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'domain'        => $domain,
                'bot'           => $bot_name,
                'botType'       => $bot_type,
                'userAgent'     => $user_agent,
                'page'          => $page_path,
                'timestamp'     => gmdate(DATE_ATOM),
                'pluginVersion' => defined('LLMS_VERSION') ? LLMS_VERSION : 'unknown',
            ]),
        ]);
    }

    /**
     * Match a UA string against the remote bot list.
     *
     * Iterates in the order the server returned them — already sorted
     * by priority DESC then userAgentMatch length DESC — so the first
     * substring hit wins. Returns the matching row or null.
     */
    public function llms_match_user_agent($user_agent, $bots) {
        if (empty($bots) || !is_array($bots) || $user_agent === '') {
            return null;
        }
        foreach ($bots as $row) {
            if (empty($row['userAgentMatch']) || empty($row['bot'])) {
                continue;
            }
            if (stripos($user_agent, $row['userAgentMatch']) !== false) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Fetch the active bot taxonomy from the VK API, cached as a
     * transient. Falls back to the bundled `llms_get_known_bots()` list
     * (shaped to match the API response) when the API is unreachable so
     * detection keeps working through outages. Failure cache is shorter
     * than success cache so the API recovers quickly.
     */
    public function llms_get_remote_bot_list() {
        $cached = get_transient(self::BOT_LIST_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::VK_API_BASE . '/api/v1/bot-list.json', [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $fallback = $this->llms_known_bots_as_remote_shape();
            set_transient(self::BOT_LIST_TRANSIENT, $fallback, self::BOT_LIST_FAIL_TTL);
            return $fallback;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['bots']) || !is_array($data['bots'])) {
            $fallback = $this->llms_known_bots_as_remote_shape();
            set_transient(self::BOT_LIST_TRANSIENT, $fallback, self::BOT_LIST_FAIL_TTL);
            return $fallback;
        }

        $ttl = isset($data['ttl']) && is_numeric($data['ttl']) && (int) $data['ttl'] > 0
            ? (int) $data['ttl']
            : self::BOT_LIST_TTL;
        set_transient(self::BOT_LIST_TRANSIENT, $data['bots'], $ttl);
        return $data['bots'];
    }

    /**
     * Bundled fallback that mirrors the shape of the bot-list.json
     * response. Used when the remote API is unreachable so the plugin
     * keeps detecting bots through network blips. Order matches the
     * original taxonomy: more specific patterns first.
     */
    public function llms_known_bots_as_remote_shape() {
        $out = [];
        foreach ($this->llms_get_known_bots() as $name => $info) {
            $out[] = [
                'bot'            => $name,
                'botType'        => $info['type'],
                'userAgentMatch' => $name,
                'priority'       => 100,
                'track'          => $info['type'] !== 'training',
            ];
        }
        return $out;
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
