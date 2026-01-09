<?php
namespace AI_Assistant\Providers;

use AI_Assistant\LLM_Provider;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local LLM Provider (Ollama / LM Studio)
 *
 * Supports both Ollama's native API and OpenAI-compatible endpoints
 */
class Local_LLM extends LLM_Provider {

    private $endpoint;
    private $model;
    private $api_type; // 'ollama' or 'openai'

    // Common local LLM endpoints to try
    private const DEFAULT_ENDPOINTS = [
        'http://localhost:11434', // Ollama default
        'http://localhost:1234',  // LM Studio default
        'http://127.0.0.1:11434', // Ollama alternative
        'http://127.0.0.1:1234',  // LM Studio alternative
    ];

    public function __construct() {
        $configured_endpoint = get_option('ai_assistant_local_endpoint', '');
        $this->model = get_option('ai_assistant_model', '');

        // Auto-detect endpoint if not configured or if configured endpoint fails
        if (empty($configured_endpoint)) {
            $detected = $this->auto_detect_endpoint();
            $this->endpoint = $detected['endpoint'];
            $this->api_type = $detected['api_type'];
        } else {
            $this->endpoint = rtrim($configured_endpoint, '/');
            $this->api_type = $this->detect_api_type($this->endpoint);
        }
    }

    /**
     * Auto-detect available local LLM endpoint
     */
    private function auto_detect_endpoint(): array {
        foreach (self::DEFAULT_ENDPOINTS as $endpoint) {
            $api_type = $this->detect_api_type($endpoint);
            if ($api_type !== 'unknown') {
                // Save the detected endpoint for future use
                update_option('ai_assistant_local_endpoint', $endpoint);
                return ['endpoint' => $endpoint, 'api_type' => $api_type];
            }
        }

        // Default to Ollama endpoint if nothing found
        return ['endpoint' => 'http://localhost:11434', 'api_type' => 'ollama'];
    }

    /**
     * Detect which API type the endpoint supports
     */
    private function detect_api_type(string $endpoint): string {
        $endpoint = rtrim($endpoint, '/');

        // Try OpenAI-compatible endpoint first (LM Studio uses this)
        $response = wp_remote_get($endpoint . '/v1/models', ['timeout' => 3]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return 'openai';
        }

        // Try Ollama endpoint
        $response = wp_remote_get($endpoint . '/api/tags', ['timeout' => 3]);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return 'ollama';
        }

        return 'unknown';
    }

    /**
     * Send message to local LLM
     */
    public function send_message(array $messages, array $tools = [], string $system_prompt = ''): array {
        if (empty($this->model)) {
            throw new \Exception('No model selected. Please configure a model in settings.');
        }

        if ($this->api_type === 'ollama') {
            return $this->send_ollama_message($messages, $tools, $system_prompt);
        } else {
            return $this->send_openai_message($messages, $tools, $system_prompt);
        }
    }

    /**
     * Send message using Ollama's native API
     */
    private function send_ollama_message(array $messages, array $tools, string $system_prompt): array {
        $url = $this->endpoint . '/api/chat';

        // Format messages for Ollama
        $ollama_messages = [];

        if (!empty($system_prompt)) {
            $ollama_messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

        foreach ($messages as $message) {
            $ollama_messages[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        $body = [
            'model' => $this->model,
            'messages' => $ollama_messages,
            'stream' => false,
        ];

        // Ollama supports tools in newer versions
        if (!empty($tools)) {
            $body['tools'] = $this->format_tools_ollama($tools);
        }

        $response = $this->make_request($url, [
            'body' => json_encode($body),
        ]);

        return $this->parse_ollama_response($response);
    }

    /**
     * Send message using OpenAI-compatible API (LM Studio)
     */
    private function send_openai_message(array $messages, array $tools, string $system_prompt): array {
        $url = $this->endpoint . '/v1/chat/completions';

        // Format messages
        $openai_messages = [];

        if (!empty($system_prompt)) {
            $openai_messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

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

        $response = $this->make_request($url, [
            'body' => json_encode($body),
        ]);

        return $this->parse_openai_response($response);
    }

    /**
     * Get available models
     */
    public function get_available_models(): array {
        $models = [];

        if ($this->api_type === 'ollama') {
            // Ollama API
            $response = wp_remote_get($this->endpoint . '/api/tags', ['timeout' => 10]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['models'])) {
                    foreach ($body['models'] as $model) {
                        $models[] = [
                            'id' => $model['name'],
                            'name' => $model['name'],
                        ];
                    }
                }
            }
        } else {
            // OpenAI-compatible API
            $response = wp_remote_get($this->endpoint . '/v1/models', ['timeout' => 10]);
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                if (!empty($body['data'])) {
                    foreach ($body['data'] as $model) {
                        $models[] = [
                            'id' => $model['id'],
                            'name' => $model['id'],
                        ];
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Validate connection
     */
    public function validate_connection(): bool {
        try {
            $models = $this->get_available_models();
            return !empty($models);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format tools for OpenAI-compatible API
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
     * Format tools for Ollama API
     */
    private function format_tools_ollama(array $tools): array {
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
        // This is implemented in the specific response parsers
        return [];
    }

    /**
     * Parse Ollama response
     */
    private function parse_ollama_response(array $response): array {
        $message = $response['message'] ?? [];
        $content = $message['content'] ?? '';
        $tool_calls = [];

        // Ollama returns tool calls in message.tool_calls
        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $tool_calls[] = [
                    'id' => uniqid('tc_'),
                    'name' => $tc['function']['name'],
                    'arguments' => $tc['function']['arguments'] ?? [],
                ];
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
        ];
    }

    /**
     * Parse OpenAI-compatible response
     */
    private function parse_openai_response(array $response): array {
        $choice = $response['choices'][0] ?? null;

        if (!$choice) {
            throw new \Exception('No response from local LLM');
        }

        $message = $choice['message'] ?? [];
        $content = $message['content'] ?? '';
        $tool_calls = [];

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                if ($tc['type'] === 'function') {
                    $args = $tc['function']['arguments'] ?? '{}';
                    $tool_calls[] = [
                        'id' => $tc['id'] ?? uniqid('tc_'),
                        'name' => $tc['function']['name'],
                        'arguments' => is_string($args) ? json_decode($args, true) : $args,
                    ];
                }
            }
        }

        return [
            'content' => $content,
            'tool_calls' => $tool_calls,
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];
    }
}
