<?php
namespace AI_Assistant\Providers;

use AI_Assistant\LLM_Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI Provider
 */
class OpenAI extends LLM_Provider {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    private $api_key;
    private $model;

    public function __construct() {
        $this->api_key = ai_assistant()->settings()->get_api_key('openai');
        $this->model = get_option('ai_assistant_model');
    }

    /**
     * Send message to OpenAI
     */
    public function send_message(array $messages, array $tools = [], string $system_prompt = ''): array {
        if (empty($this->api_key)) {
            throw new \Exception('OpenAI API key not configured');
        }

        // Add system message if provided
        $openai_messages = [];
        if (!empty($system_prompt)) {
            $openai_messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

        // Convert messages to OpenAI format
        foreach ($messages as $message) {
            $openai_messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $body = [
            'model' => $this->model,
            'messages' => $openai_messages,
            'max_tokens' => 4096,
        ];

        if (!empty($tools)) {
            $body['tools'] = $this->format_tools($tools);
            $body['tool_choice'] = 'auto';
        }

        $response = $this->make_request(self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
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
            ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini'],
            ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo'],
            ['id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo'],
        ];
    }

    /**
     * Validate connection by listing models (no token cost)
     */
    public function validate_connection(): bool {
        if (empty($this->api_key)) {
            throw new \Exception('API key not configured');
        }

        // Check API key format
        if (strpos($this->api_key, 'sk-') !== 0) {
            throw new \Exception('Invalid API key format (should start with sk-)');
        }

        // Use the models endpoint to verify key without using tokens
        try {
            $response = wp_remote_get('https://api.openai.com/v1/models', [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                ],
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
     * Format tools for OpenAI API (function calling)
     */
    protected function format_tools(array $tools): array {
        $formatted = [];

        foreach ($tools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
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

        $choice = $response['choices'][0] ?? null;
        if ($choice && !empty($choice['message']['tool_calls'])) {
            foreach ($choice['message']['tool_calls'] as $tc) {
                if ($tc['type'] === 'function') {
                    $tool_calls[] = [
                        'id' => $tc['id'],
                        'name' => $tc['function']['name'],
                        'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
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
        $choice = $response['choices'][0] ?? null;

        if (!$choice) {
            throw new \Exception('No response from OpenAI');
        }

        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $tool_calls = $this->parse_tool_calls($response);

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];
    }
}
