<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Abstract LLM Provider class
 */
abstract class LLM_Provider {

    /**
     * Send a message to the LLM
     *
     * @param array $messages Conversation messages
     * @param array $tools Available tools
     * @param string $system_prompt System prompt
     * @return array Response with 'content' and optional 'tool_calls'
     */
    abstract public function send_message(array $messages, array $tools = [], string $system_prompt = ''): array;

    /**
     * Get available models for this provider
     *
     * @return array Array of ['id' => 'model-id', 'name' => 'Model Name']
     */
    abstract public function get_available_models(): array;

    /**
     * Validate the connection/API key
     *
     * @return bool True if connection is valid
     */
    abstract public function validate_connection(): bool;

    /**
     * Make an HTTP request
     *
     * @param string $url Endpoint URL
     * @param array $args Request arguments
     * @return array Response body
     */
    protected function make_request(string $url, array $args): array {
        $defaults = [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ]
        ];

        $args = wp_parse_args($args, $defaults);

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('Request failed: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code >= 400) {
            $error_message = $data['error']['message'] ?? $data['error'] ?? 'Unknown error';
            throw new \Exception("API error ($status_code): $error_message");
        }

        return $data;
    }

    /**
     * Convert tools to provider-specific format
     *
     * @param array $tools Tools in standard format
     * @return array Tools in provider-specific format
     */
    abstract protected function format_tools(array $tools): array;

    /**
     * Parse tool calls from provider response
     *
     * @param array $response Raw provider response
     * @return array Standardized tool calls
     */
    abstract protected function parse_tool_calls(array $response): array;
}
