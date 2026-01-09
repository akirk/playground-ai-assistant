<?php
namespace AI_Assistant\Providers;

use AI_Assistant\LLM_Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Anthropic (Claude) Provider
 */
class Anthropic extends LLM_Provider {

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    private $api_key;
    private $model;

    public function __construct() {
        $this->api_key = ai_assistant()->settings()->get_api_key('anthropic');
        $this->model = get_option('ai_assistant_model', 'claude-sonnet-4-5-20250929');
    }

    /**
     * Send message to Claude
     */
    public function send_message(array $messages, array $tools = [], string $system_prompt = ''): array {
        if (empty($this->api_key)) {
            throw new \Exception('Anthropic API key not configured');
        }

        // Convert messages to Anthropic format
        $anthropic_messages = $this->format_messages($messages);

        $body = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'messages' => $anthropic_messages,
        ];

        if (!empty($system_prompt)) {
            $body['system'] = $system_prompt;
        }

        if (!empty($tools)) {
            $body['tools'] = $this->format_tools($tools);
        }

        $response = $this->make_request(self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->api_key,
                'anthropic-version' => self::API_VERSION,
            ],
            'body' => json_encode($body),
        ]);

        return $this->parse_response($response);
    }

    /**
     * Get available models
     */
    public function get_available_models(): array {
        return [
            // Current models
            ['id' => 'claude-sonnet-4-5-20250929', 'name' => 'Claude Sonnet 4.5'],
            ['id' => 'claude-haiku-4-5-20251001', 'name' => 'Claude Haiku 4.5'],
            ['id' => 'claude-opus-4-5-20251101', 'name' => 'Claude Opus 4.5'],
            // Legacy models
            ['id' => 'claude-sonnet-4-20250514', 'name' => 'Claude Sonnet 4'],
            ['id' => 'claude-opus-4-20250514', 'name' => 'Claude Opus 4'],
            ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku'],
        ];
    }

    /**
     * Validate connection by checking API key format and making a minimal API call
     */
    public function validate_connection(): bool {
        if (empty($this->api_key)) {
            throw new \Exception('API key not configured');
        }

        // Check API key format
        if (strpos($this->api_key, 'sk-ant-') !== 0) {
            throw new \Exception('Invalid API key format (should start with sk-ant-)');
        }

        // Make a minimal request to verify the key works
        // Using a tiny max_tokens to minimize cost
        try {
            $response = wp_remote_post(self::API_URL, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->api_key,
                    'anthropic-version' => self::API_VERSION,
                ],
                'body' => json_encode([
                    'model' => 'claude-3-haiku-20240307',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]),
            ]);

            if (is_wp_error($response)) {
                throw new \Exception('Network error: ' . $response->get_error_message());
            }

            $status = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status === 401) {
                throw new \Exception('Invalid API key');
            } elseif ($status === 403) {
                throw new \Exception('API key lacks permissions');
            } elseif ($status >= 400) {
                throw new \Exception($body['error']['message'] ?? 'API error: ' . $status);
            }

            return true;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Format messages for Anthropic API
     */
    private function format_messages(array $messages): array {
        $formatted = [];

        foreach ($messages as $message) {
            $role = $message['role'];

            // Anthropic uses 'user' and 'assistant' roles only
            if ($role === 'system') {
                continue; // System is handled separately
            }

            $formatted[] = [
                'role' => $role,
                'content' => $message['content'],
            ];
        }

        return $formatted;
    }

    /**
     * Format tools for Anthropic API
     */
    protected function format_tools(array $tools): array {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ];
        }

        return $formatted;
    }

    /**
     * Parse tool calls from response
     */
    protected function parse_tool_calls(array $response): array {
        $tool_calls = [];

        if (!empty($response['content'])) {
            foreach ($response['content'] as $content) {
                if ($content['type'] === 'tool_use') {
                    $tool_calls[] = [
                        'id' => $content['id'],
                        'name' => $content['name'],
                        'arguments' => $content['input'],
                    ];
                }
            }
        }

        return $tool_calls;
    }

    /**
     * Parse full response
     */
    private function parse_response(array $response): array {
        $text_content = '';
        $tool_calls = [];

        if (!empty($response['content'])) {
            foreach ($response['content'] as $content) {
                if ($content['type'] === 'text') {
                    $text_content .= $content['text'];
                } elseif ($content['type'] === 'tool_use') {
                    $tool_calls[] = [
                        'id' => $content['id'],
                        'name' => $content['name'],
                        'arguments' => $content['input'],
                    ];
                }
            }
        }

        return [
            'content' => $text_content,
            'tool_calls' => $tool_calls,
            'stop_reason' => $response['stop_reason'] ?? null,
        ];
    }
}
