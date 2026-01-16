<?php
/**
 * Plugin Name: Playground AI Assistant
 * Plugin URI: https://github.com/akirk/playground-ai-assistant
 * Description: AI-powered chat interface for WordPress Playground to modify it to your liking. Bring your own key or use a local LLM
 * Version: 1.0.0
 * Author: Alex Kirk
 * Author URI: https://alex.kirk.at
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-assistant
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safety check: Only run in WordPress Playground environment
 */
function ai_assistant_is_playground(): bool {
    $is_wasm = isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'PHP.wasm') !== false;
    $is_playground_path = strpos(ABSPATH, '/wordpress') !== false;
    $has_playground_function = function_exists('post_message_to_js');

    return $is_wasm && $is_playground_path && $has_playground_function;
}

if (!ai_assistant_is_playground()) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Playground AI Assistant</strong> only runs in WordPress Playground environments.</p></div>';
    });
    return;
}

define('AI_ASSISTANT_VERSION', '1.0.0');
define('AI_ASSISTANT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_ASSISTANT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AI_ASSISTANT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'AI_Assistant\\';
    $base_dir = AI_ASSISTANT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main plugin class
 */
final class AI_Assistant {

    private static $instance = null;

    private $settings;
    private $chat_ui;
    private $api_handler;
    private $tools;
    private $executor;
    private $conversations;
    private $plugin_downloads;
    private $git_tracker;
    private $changes_admin;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function init() {
        // Load text domain
        load_plugin_textdomain('ai-assistant', false, dirname(AI_ASSISTANT_PLUGIN_BASENAME) . '/languages');

        // Initialize components
        $this->settings = new AI_Assistant\Settings();
        $this->tools = new AI_Assistant\Tools();
        $this->git_tracker = new AI_Assistant\Git_Tracker();
        $this->executor = new AI_Assistant\Executor($this->tools, $this->git_tracker);
        $this->conversations = new AI_Assistant\Conversations();
        $this->chat_ui = new AI_Assistant\Chat_UI();
        $this->api_handler = new AI_Assistant\API_Handler($this->tools, $this->executor);
        $this->plugin_downloads = new AI_Assistant\Plugin_Downloads();
        $this->changes_admin = new AI_Assistant\Changes_Admin($this->git_tracker);
    }

    /**
     * Get conversations instance
     */
    public function conversations() {
        return $this->conversations;
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Ensure encryption key exists (needed for API key storage)
        if (!get_option('ai_assistant_encryption_key')) {
            update_option('ai_assistant_encryption_key', wp_generate_password(32, true, true));
        }

        // Set default options
        if (!get_option('ai_assistant_provider')) {
            update_option('ai_assistant_provider', 'anthropic');
        }
        if (!get_option('ai_assistant_role_permissions')) {
            update_option('ai_assistant_role_permissions', [
                'administrator' => 'full',
                'editor' => 'read_only',
                'author' => 'chat_only',
                'contributor' => 'chat_only',
                'subscriber' => 'none'
            ]);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
    }

    /**
     * Get settings instance
     */
    public function settings() {
        return $this->settings;
    }

    /**
     * Get tools instance
     */
    public function tools() {
        return $this->tools;
    }

    /**
     * Get executor instance
     */
    public function executor() {
        return $this->executor;
    }
}

/**
 * Returns the main instance of AI_Assistant
 */
function ai_assistant() {
    return AI_Assistant::instance();
}

// Initialize
ai_assistant();
