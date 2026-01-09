<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX API Handler for tool execution
 *
 * Note: All LLM communication happens client-side via JavaScript.
 * This handler only executes tools (file operations, DB operations, etc.)
 */
class API_Handler {

    private $tools;
    private $executor;

    public function __construct($provider, $tools, $executor) {
        $this->tools = $tools;
        $this->executor = $executor;

        add_action('wp_ajax_ai_assistant_execute_tool', [$this, 'handle_execute_tool']);
    }

    /**
     * Handle tool execution AJAX request
     */
    public function handle_execute_tool() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $permission = ai_assistant()->settings()->get_user_permission_level();
        if ($permission === 'none' || $permission === 'chat_only') {
            wp_send_json_error(['message' => 'Tool execution not allowed']);
        }

        $tool_name = sanitize_text_field($_POST['tool'] ?? '');
        $arguments_json = stripslashes($_POST['arguments'] ?? '{}');
        $arguments = json_decode($arguments_json, true);

        if (empty($tool_name)) {
            wp_send_json_error(['message' => 'Tool name is required']);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid arguments JSON']);
        }

        try {
            $result = $this->executor->execute_tool($tool_name, $arguments, $permission);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
