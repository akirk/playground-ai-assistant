<?php
/**
 * Plugin Name: Playground AI Assistant
 * Plugin URI: https://github.com/example/playground-ai-assistant
 * Description: AI-powered chat interface for WordPress Playground - modify your site using Claude, ChatGPT, or local LLMs
 * Version: 1.0.0
 * Author: Playground AI Assistant
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

    return $is_wasm && $is_playground_path;
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

    // Handle provider classes
    if (strpos($relative_class, 'Providers\\') === 0) {
        $relative_class = substr($relative_class, 10); // Remove 'Providers\'
        $file = $base_dir . 'providers/class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    } else {
        $file = $base_dir . 'class-' . strtolower(str_replace('_', '-', $relative_class)) . '.php';
    }

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
        $this->executor = new AI_Assistant\Executor($this->tools);
        $this->conversations = new AI_Assistant\Conversations();
        $this->chat_ui = new AI_Assistant\Chat_UI();
        $this->api_handler = new AI_Assistant\API_Handler($this->get_provider(), $this->tools, $this->executor);
        $this->plugin_downloads = new AI_Assistant\Plugin_Downloads();
    }

    /**
     * Get conversations instance
     */
    public function conversations() {
        return $this->conversations;
    }

    /**
     * Get the configured LLM provider
     */
    public function get_provider() {
        $provider_type = get_option('ai_assistant_provider', 'anthropic');

        switch ($provider_type) {
            case 'openai':
                return new AI_Assistant\Providers\OpenAI();
            case 'local':
                return new AI_Assistant\Providers\Local_LLM();
            case 'anthropic':
            default:
                return new AI_Assistant\Providers\Anthropic();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
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

        // Create conversation history table
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_assistant_conversations';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            messages longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
