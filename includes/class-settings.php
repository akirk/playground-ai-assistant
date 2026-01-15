<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Settings page and option management
 */
class Settings {

    private $encryption_key;

    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_ai_assistant_get_skill', [$this, 'ajax_get_skill']);
        add_action('wp_ajax_ai_assistant_get_current_settings', [$this, 'ajax_get_current_settings']);
        add_action('load-tools_page_ai-conversations', [$this, 'add_help_tabs']);
        add_action('load-settings_page_ai-assistant-settings', [$this, 'add_help_tabs']);

        // Encrypt API keys before storing
        add_filter('pre_update_option_ai_assistant_anthropic_api_key', [$this, 'encrypt_api_key'], 10, 2);
        add_filter('pre_update_option_ai_assistant_openai_api_key', [$this, 'encrypt_api_key'], 10, 2);
    }

    public function ajax_get_skill() {
        check_ajax_referer('ai_assistant_skills', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $skill_id = sanitize_file_name($_POST['skill'] ?? '');
        if (empty($skill_id)) {
            wp_send_json_error(['message' => 'Skill ID is required']);
        }

        $skills_dir = plugin_dir_path(__DIR__) . 'skills/';
        $skill_file = $skills_dir . $skill_id . '.md';

        if (!file_exists($skill_file)) {
            wp_send_json_error(['message' => 'Skill not found']);
        }

        $content = file_get_contents($skill_file);
        if ($content === false) {
            wp_send_json_error(['message' => 'Failed to read skill']);
        }

        $frontmatter = [];
        $body = $content;

        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $matches)) {
            foreach (explode("\n", $matches[1]) as $line) {
                if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                    $frontmatter[$kv[1]] = trim($kv[2], '"\'');
                }
            }
            $body = $matches[2];
        }

        wp_send_json_success([
            'id' => $skill_id,
            'title' => $frontmatter['title'] ?? $skill_id,
            'content' => $body,
        ]);
    }

    public function ajax_get_current_settings() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_send_json_success([
            'provider' => get_option('ai_assistant_provider', 'anthropic'),
            'model' => get_option('ai_assistant_model', ''),
        ]);
    }

    /**
     * Get or generate encryption key
     */
    private function get_encryption_key() {
        $key = get_option('ai_assistant_encryption_key');
        if (!$key) {
            $key = wp_generate_password(32, true, true);
            update_option('ai_assistant_encryption_key', $key);
        }
        return $key;
    }

    private const ENCRYPTION_PREFIX = '$enc$';

    /**
     * Check if a value is already encrypted
     */
    public function is_encrypted($data) {
        return is_string($data) && strpos($data, self::ENCRYPTION_PREFIX) === 0;
    }

    /**
     * Encrypt sensitive data
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        if (empty($this->encryption_key)) {
            $this->encryption_key = $this->get_encryption_key();
        }
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        if ($encrypted === false) {
            return '';
        }
        return self::ENCRYPTION_PREFIX . base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public function decrypt($data) {
        if (empty($data) || !$this->is_encrypted($data)) {
            return '';
        }
        $data = substr($data, strlen(self::ENCRYPTION_PREFIX));
        $decoded = base64_decode($data);
        if ($decoded === false || strlen($decoded) < 17) {
            return '';
        }
        $iv = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        $result = openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return $result === false ? '' : $result;
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        // Add to Tools menu
        add_management_page(
            __('AI Conversations', 'ai-assistant'),
            __('AI Conversations', 'ai-assistant'),
            'edit_posts',
            'ai-conversations',
            [$this, 'render_chat_page']
        );

        // Settings under Settings menu
        add_options_page(
            __('AI Assistant Settings', 'ai-assistant'),
            __('AI Assistant', 'ai-assistant'),
            'manage_options',
            'ai-assistant-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Add help tabs to the chat page
     */
    public function add_help_tabs() {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id'      => 'ai-assistant-overview',
            'title'   => __('Overview', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant helps you manage your WordPress site through natural language conversation. Ask questions, request changes, or get help with content.', 'ai-assistant') . '</p>'
                       . '<p>' . __('Your conversation history is saved automatically and can be accessed from the sidebar.', 'ai-assistant') . '</p>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-capabilities',
            'title'   => __('Capabilities', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant can help you with:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li>' . __('<strong>Content</strong> - Create, edit, and manage posts and pages', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Media</strong> - Upload and organize images and files', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Settings</strong> - View and modify site configuration', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Plugins</strong> - Get information about installed plugins', 'ai-assistant') . '</li>'
                       . '<li>' . __('<strong>Database</strong> - Query data (read-only unless you have full access)', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-tools',
            'title'   => __('Available Tools', 'ai-assistant'),
            'content' => '<p>' . __('The AI Assistant has access to the following tools:', 'ai-assistant') . '</p>'
                       . '<ul id="ai-tools-list"><li><em>Loading...</em></li></ul>'
                       . '<script>
                           jQuery(function($) {
                               function populateTools() {
                                   if (typeof window.aiAssistant !== "undefined" && window.aiAssistant.getTools) {
                                       var tools = window.aiAssistant.getTools();
                                       var $list = $("#ai-tools-list");
                                       $list.empty();
                                       tools.forEach(function(tool) {
                                           $list.append("<li><code>" + tool.name + "</code> - " + $("<div>").text(tool.description).html() + "</li>");
                                       });
                                   } else {
                                       setTimeout(populateTools, 100);
                                   }
                               }
                               populateTools();
                           });
                       </script>',
        ]);

        $skills_content = $this->get_skills_help_content();
        $screen->add_help_tab([
            'id'      => 'ai-assistant-skills',
            'title'   => __('Available Skills', 'ai-assistant'),
            'content' => $skills_content,
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-assistant-yolo',
            'title'   => __('YOLO Mode', 'ai-assistant'),
            'content' => '<p>' . __('When YOLO Mode is enabled, the assistant will execute actions without asking for confirmation. Use with caution.', 'ai-assistant') . '</p>'
                       . '<p>' . __('With YOLO Mode disabled (default), you will be prompted to approve any changes before they are made.', 'ai-assistant') . '</p>',
        ]);

        $system_prompt = $this->get_system_prompt();
        $screen->add_help_tab([
            'id'      => 'ai-assistant-system-prompt',
            'title'   => __('System Prompt', 'ai-assistant'),
            'content' => '<p>' . __('The following system prompt is sent to the AI with each conversation:', 'ai-assistant') . '</p>'
                       . '<pre style="white-space: pre-wrap; background: #f6f7f7; padding: 10px; border-radius: 4px; max-height: 400px; overflow-y: auto;">' . esc_html($system_prompt) . '</pre>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'ai-assistant') . '</strong></p>'
            . '<p><a href="' . esc_url(admin_url('options-general.php?page=ai-assistant-settings')) . '">' . __('Plugin Settings', 'ai-assistant') . '</a></p>'
        );
    }

    /**
     * Render the dedicated chat page
     */
    public function render_chat_page() {
        $conversation_id = isset($_GET['conversation']) ? intval($_GET['conversation']) : 0;
        $settings_url = admin_url('options-general.php?page=ai-assistant-settings');
        ?>
        <div class="wrap ai-assistant-page">
            <div class="ai-chat-layout">
                <!-- Sidebar -->
                <div class="ai-chat-sidebar">
                    <div class="ai-sidebar-header">
                        <button type="button" id="ai-assistant-new-chat" class="button button-primary">
                            + <?php esc_html_e('New Chat', 'ai-assistant'); ?>
                        </button>
                    </div>
                    <div class="ai-sidebar-conversations" id="ai-sidebar-conversations">
                        <div class="ai-sidebar-loading"><?php esc_html_e('Loading...', 'ai-assistant'); ?></div>
                    </div>
                    <div class="ai-sidebar-footer">
                        <a href="<?php echo esc_url($settings_url); ?>" class="ai-sidebar-link">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e('Settings', 'ai-assistant'); ?>
                        </a>
                    </div>
                </div>

                <!-- Main Chat Area -->
                <div class="ai-chat-main">
                    <div class="ai-chat-main-header">
                        <button type="button" class="ai-sidebar-toggle" id="ai-sidebar-toggle">
                            <span class="dashicons dashicons-menu"></span> <?php esc_html_e('Chats', 'ai-assistant'); ?>
                        </button>
                        <div class="ai-header-actions">
                            <span id="ai-token-count" class="ai-token-count" title="<?php esc_attr_e('Estimated token usage', 'ai-assistant'); ?>">0 tokens</span>
                            <span class="ai-header-sep">|</span>
                            <button type="button" id="ai-assistant-summarize" class="ai-header-btn" title="<?php esc_attr_e('Generate conversation summary', 'ai-assistant'); ?>" style="display: none;">
                                <span class="dashicons dashicons-media-text"></span>
                            </button>
                            <label class="ai-yolo-label" title="<?php esc_attr_e('Skip confirmation prompts for destructive actions', 'ai-assistant'); ?>"><input type="checkbox" id="ai-assistant-yolo"> YOLO Mode</label>
                        </div>
                    </div>
                    <div class="ai-assistant-chat-container">
                        <div id="ai-assistant-messages"></div>
                        <div id="ai-assistant-loading" style="display: none;">
                            <div class="ai-loading-dots"><span></span><span></span><span></span></div>
                        </div>
                        <div id="ai-assistant-pending-actions"></div>
                        <div class="ai-assistant-input-area">
                            <textarea id="ai-assistant-input" placeholder="<?php esc_attr_e('Ask me anything about your WordPress site...', 'ai-assistant'); ?>" rows="3"></textarea>
                            <button type="button" id="ai-assistant-send" class="button button-primary"><?php esc_html_e('Send', 'ai-assistant'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            var aiAssistantPageConfig = {
                conversationId: <?php echo intval($conversation_id); ?>,
                isFullPage: true
            };
            jQuery(document).ready(function($) {
                $('#ai-sidebar-toggle').on('click', function() {
                    $('.ai-chat-sidebar').toggleClass('mobile-visible');
                });
                // Hide sidebar when conversation is selected on mobile
                $(document).on('click', '.ai-conv-item', function() {
                    if (window.innerWidth <= 782) {
                        $('.ai-chat-sidebar').removeClass('mobile-visible');
                    }
                });
            });
        </script>
        <?php
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Provider settings
        register_setting('ai_assistant_settings', 'ai_assistant_provider');
        register_setting('ai_assistant_settings', 'ai_assistant_model');
        register_setting('ai_assistant_settings', 'ai_assistant_anthropic_api_key', [
            'sanitize_callback' => [$this, 'sanitize_api_key_input']
        ]);
        register_setting('ai_assistant_settings', 'ai_assistant_openai_api_key', [
            'sanitize_callback' => [$this, 'sanitize_api_key_input']
        ]);
        register_setting('ai_assistant_settings', 'ai_assistant_local_endpoint');
        register_setting('ai_assistant_settings', 'ai_assistant_local_model');
        register_setting('ai_assistant_settings', 'ai_assistant_summarization_model');
        register_setting('ai_assistant_settings', 'ai_assistant_role_permissions');

        // Display settings
        register_setting('ai_assistant_settings', 'ai_assistant_show_on_frontend');

        // Provider section
        add_settings_section(
            'ai_assistant_provider_section',
            __('LLM Provider Settings', 'ai-assistant'),
            [$this, 'provider_section_callback'],
            'ai-assistant-settings'
        );

        add_settings_field(
            'ai_assistant_provider',
            __('Provider', 'ai-assistant'),
            [$this, 'provider_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        add_settings_field(
            'ai_assistant_anthropic_api_key',
            __('Anthropic API Key', 'ai-assistant'),
            [$this, 'anthropic_api_key_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        add_settings_field(
            'ai_assistant_openai_api_key',
            __('OpenAI API Key', 'ai-assistant'),
            [$this, 'openai_api_key_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        add_settings_field(
            'ai_assistant_local_endpoint',
            __('Local LLM Endpoint', 'ai-assistant'),
            [$this, 'local_endpoint_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        add_settings_field(
            'ai_assistant_model',
            __('Model', 'ai-assistant'),
            [$this, 'model_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        add_settings_field(
            'ai_assistant_summarization_model',
            __('Summarization Model', 'ai-assistant'),
            [$this, 'summarization_model_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_provider_section'
        );

        // Permissions section (table is rendered in the section callback, not as a field)
        add_settings_section(
            'ai_assistant_permissions_section',
            __('Role Permissions', 'ai-assistant'),
            [$this, 'permissions_section_callback'],
            'ai-assistant-settings'
        );

        // Display section
        add_settings_section(
            'ai_assistant_display_section',
            __('Display Settings', 'ai-assistant'),
            [$this, 'display_section_callback'],
            'ai-assistant-settings'
        );

        add_settings_field(
            'ai_assistant_show_on_frontend',
            __('Frontend Access', 'ai-assistant'),
            [$this, 'frontend_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_display_section'
        );
    }

    /**
     * Sanitize API key input - just validates, doesn't encrypt
     * Returns null to skip the update if masked value submitted
     */
    public function sanitize_api_key_input($value) {
        if (empty($value) || strpos($value, '***') === 0) {
            return null;
        }
        return trim($value);
    }

    /**
     * Encrypt API key before storing (called via pre_update_option filter)
     */
    public function encrypt_api_key($value, $old_value) {
        if ($value === null || $value === '') {
            return $old_value;
        }
        if ($this->is_encrypted($value)) {
            return $value;
        }
        return $this->encrypt($value);
    }

    /**
     * Get decrypted API key
     */
    public function get_api_key($provider) {
        $option = $provider === 'anthropic' ? 'ai_assistant_anthropic_api_key' : 'ai_assistant_openai_api_key';
        return $this->decrypt(get_option($option));
    }

    /**
     * Provider section description
     */
    public function provider_section_callback() {
        echo '<p>' . esc_html__('Configure your LLM provider and API credentials.', 'ai-assistant') . '</p>';
    }

    /**
     * Permissions section with table
     */
    public function permissions_section_callback() {
        $permissions = get_option('ai_assistant_role_permissions', []);
        $roles = wp_roles()->roles;
        $levels = [
            'full' => __('Full Access', 'ai-assistant'),
            'read_only' => __('Read Only', 'ai-assistant'),
            'chat_only' => __('Chat Only (No Tools)', 'ai-assistant'),
            'none' => __('No Access', 'ai-assistant')
        ];
        ?>
        <p><?php esc_html_e('Configure what each WordPress role can do with the AI Assistant.', 'ai-assistant'); ?></p>
        <table class="wp-list-table widefat fixed striped" style="max-width: 500px;">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Role', 'ai-assistant'); ?></th>
                    <th scope="col"><?php esc_html_e('Access Level', 'ai-assistant'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($roles as $role_slug => $role) : ?>
                    <tr>
                        <td><?php echo esc_html($role['name']); ?></td>
                        <td>
                            <select name="ai_assistant_role_permissions[<?php echo esc_attr($role_slug); ?>]">
                                <?php foreach ($levels as $level_slug => $level_name) : ?>
                                    <option value="<?php echo esc_attr($level_slug); ?>"
                                            <?php selected($permissions[$role_slug] ?? 'none', $level_slug); ?>>
                                        <?php echo esc_html($level_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e('Full Access: All file and database operations. Read Only: Can read files and query database. Chat Only: Can chat but no tool execution.', 'ai-assistant'); ?>
        </p>
        <?php
    }

    /**
     * Display section description
     */
    public function display_section_callback() {
        echo '<p>' . esc_html__('Configure where the AI Assistant appears.', 'ai-assistant') . '</p>';
    }

    /**
     * Frontend access checkbox field
     */
    public function frontend_field_callback() {
        $show_on_frontend = get_option('ai_assistant_show_on_frontend', '0');
        ?>
        <label>
            <input type="checkbox"
                   name="ai_assistant_show_on_frontend"
                   value="1"
                   <?php checked($show_on_frontend, '1'); ?>>
            <?php esc_html_e('Show AI Assistant on the frontend', 'ai-assistant'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('When enabled, logged-in users with access will see the AI Assistant button on the frontend of your site.', 'ai-assistant'); ?>
        </p>
        <?php
    }

    /**
     * Provider dropdown field
     */
    public function provider_field_callback() {
        $provider = get_option('ai_assistant_provider', 'anthropic');
        ?>
        <select name="ai_assistant_provider" id="ai_assistant_provider">
            <option value="anthropic" <?php selected($provider, 'anthropic'); ?>>
                <?php esc_html_e('Anthropic (Claude)', 'ai-assistant'); ?>
            </option>
            <option value="openai" <?php selected($provider, 'openai'); ?>>
                <?php esc_html_e('OpenAI (ChatGPT)', 'ai-assistant'); ?>
            </option>
            <option value="local" <?php selected($provider, 'local'); ?>>
                <?php esc_html_e('Local LLM (Ollama/LM Studio)', 'ai-assistant'); ?>
            </option>
        </select>
        <?php
    }

    /**
     * Anthropic API key field
     */
    public function anthropic_api_key_field_callback() {
        $api_key = get_option('ai_assistant_anthropic_api_key');
        $masked = $api_key ? '***' . substr($this->decrypt($api_key), -4) : '';
        ?>
        <input type="password"
               name="ai_assistant_anthropic_api_key"
               id="ai_assistant_anthropic_api_key"
               value="<?php echo esc_attr($masked); ?>"
               class="regular-text ai-provider-field"
               data-provider="anthropic"
               placeholder="sk-ant-..."
               autocomplete="off">
        <button type="button" class="button ai-test-connection" data-provider="anthropic">
            <?php esc_html_e('Test Connection', 'ai-assistant'); ?>
        </button>
        <span class="ai-connection-status"></span>
        <?php
    }

    /**
     * OpenAI API key field
     */
    public function openai_api_key_field_callback() {
        $api_key = get_option('ai_assistant_openai_api_key');
        $masked = $api_key ? '***' . substr($this->decrypt($api_key), -4) : '';
        ?>
        <input type="password"
               name="ai_assistant_openai_api_key"
               id="ai_assistant_openai_api_key"
               value="<?php echo esc_attr($masked); ?>"
               class="regular-text ai-provider-field"
               data-provider="openai"
               placeholder="sk-..."
               autocomplete="off">
        <button type="button" class="button ai-test-connection" data-provider="openai">
            <?php esc_html_e('Test Connection', 'ai-assistant'); ?>
        </button>
        <span class="ai-connection-status"></span>
        <?php
    }

    /**
     * Local LLM endpoint field
     */
    public function local_endpoint_field_callback() {
        $endpoint = get_option('ai_assistant_local_endpoint', '');
        ?>
        <input type="url"
               name="ai_assistant_local_endpoint"
               id="ai_assistant_local_endpoint"
               value="<?php echo esc_url($endpoint); ?>"
               class="regular-text ai-provider-field"
               data-provider="local"
               placeholder="Leave empty to auto-detect">
        <button type="button" class="button" id="ai-auto-detect-endpoint">
            <?php esc_html_e('Auto-Detect', 'ai-assistant'); ?>
        </button>
        <button type="button" class="button ai-test-connection" data-provider="local">
            <?php esc_html_e('Test Connection', 'ai-assistant'); ?>
        </button>
        <span class="ai-connection-status"></span>
        <p class="description">
            <?php esc_html_e('Common endpoints: Ollama (localhost:11434), LM Studio (localhost:1234). Leave empty to auto-detect.', 'ai-assistant'); ?>
        </p>
        <?php
    }

    /**
     * Model selection field
     */
    public function model_field_callback() {
        $model = get_option('ai_assistant_model', '');
        $provider = get_option('ai_assistant_provider', 'anthropic');
        ?>
        <select name="ai_assistant_model" id="ai_assistant_model">
            <option value=""><?php esc_html_e('Select a model...', 'ai-assistant'); ?></option>
        </select>
        <button type="button" class="button" id="ai-refresh-models">
            <?php esc_html_e('Refresh Models', 'ai-assistant'); ?>
        </button>
        <?php
        $anthropic_key = $this->get_api_key('anthropic');
        $openai_key = $this->get_api_key('openai');
        ?>
        <script>
            var aiAssistantCurrentModel = '<?php echo esc_js($model); ?>';
            var aiAssistantCurrentProvider = '<?php echo esc_js($provider); ?>';
            var aiAssistantAnthropicKey = '<?php echo esc_js($anthropic_key); ?>';
            var aiAssistantOpenAIKey = '<?php echo esc_js($openai_key); ?>';
            var aiAssistantSummarizationModel = '<?php echo esc_js(get_option('ai_assistant_summarization_model', '')); ?>';
        </script>
        <?php
    }

    /**
     * Summarization model selection field
     */
    public function summarization_model_field_callback() {
        $model = get_option('ai_assistant_summarization_model', '');
        ?>
        <select name="ai_assistant_summarization_model" id="ai_assistant_summarization_model">
            <option value=""><?php esc_html_e('Same as chat model (default)', 'ai-assistant'); ?></option>
        </select>
        <p class="description">
            <?php esc_html_e('Model used for generating conversation summaries. Choose a faster/cheaper model to reduce costs.', 'ai-assistant'); ?>
        </p>
        <?php
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('ai_assistant_settings');
                do_settings_sections('ai-assistant-settings');
                submit_button();
                ?>
            </form>
        </div>
        <style>
            /* Hide all provider-specific rows by default */
            .ai-provider-row { display: none; }
            /* Show rows matching the current provider */
            .ai-settings-form[data-provider="anthropic"] .ai-provider-row[data-provider="anthropic"],
            .ai-settings-form[data-provider="openai"] .ai-provider-row[data-provider="openai"],
            .ai-settings-form[data-provider="local"] .ai-provider-row[data-provider="local"] {
                display: table-row;
            }
            .ai-connection-status { margin-left: 10px; }
            .ai-connection-status.success { color: green; }
            .ai-connection-status.error { color: red; }
        </style>
        <script>
        jQuery(function($) {
            // Add data-provider attribute to the form and provider-specific rows
            var $form = $('#ai_assistant_provider').closest('form');
            var $submitBtn = $form.find('#submit');
            $form.addClass('ai-settings-form').attr('data-provider', $('#ai_assistant_provider').val());

            // Enable/disable submit based on model selection
            function updateSubmitState() {
                var modelVal = $('#ai_assistant_model').val();
                var hasValidModel = modelVal && modelVal !== '';
                $submitBtn.prop('disabled', !hasValidModel);
            }

            // Mark provider-specific rows
            $('.ai-provider-field[data-provider]').each(function() {
                $(this).closest('tr').addClass('ai-provider-row').attr('data-provider', $(this).data('provider'));
            });

            // Show/hide provider-specific fields
            $('#ai_assistant_provider').on('change', function() {
                var provider = $(this).val();
                $form.attr('data-provider', provider);
                loadModels(provider);
            });

            // Fetch OpenAI models from API
            async function fetchOpenAIModels() {
                // Use stored decrypted key, or fall back to form value if user just entered a new one
                var formKey = $('#ai_assistant_openai_api_key').val();
                var apiKey = (formKey && formKey.indexOf('***') !== 0) ? formKey : aiAssistantOpenAIKey;

                if (!apiKey) {
                    return null;
                }

                try {
                    var response = await fetch('https://api.openai.com/v1/models', {
                        method: 'GET',
                        headers: {
                            'Authorization': 'Bearer ' + apiKey
                        }
                    });

                    if (response.ok) {
                        var data = await response.json();
                        if (data.data && data.data.length > 0) {
                            // Filter to chat models and sort by id
                            var chatModels = data.data
                                .filter(function(m) {
                                    return m.id.indexOf('gpt-') === 0 &&
                                           m.id.indexOf('instruct') === -1 &&
                                           m.id.indexOf('realtime') === -1;
                                })
                                .map(function(m) {
                                    return {id: m.id, name: m.id};
                                })
                                .sort(function(a, b) {
                                    // Prioritize gpt-4o models
                                    if (a.id.indexOf('gpt-4o') === 0 && b.id.indexOf('gpt-4o') !== 0) return -1;
                                    if (b.id.indexOf('gpt-4o') === 0 && a.id.indexOf('gpt-4o') !== 0) return 1;
                                    return a.id.localeCompare(b.id);
                                });
                            return chatModels;
                        }
                    }
                } catch (e) {
                    console.log('Failed to fetch OpenAI models:', e);
                }
                return null;
            }

            // Fetch Anthropic models from API
            async function fetchAnthropicModels() {
                // Use stored decrypted key, or fall back to form value if user just entered a new one
                var formKey = $('#ai_assistant_anthropic_api_key').val();
                var apiKey = (formKey && formKey.indexOf('***') !== 0) ? formKey : aiAssistantAnthropicKey;

                if (!apiKey) {
                    return null;
                }

                try {
                    var response = await fetch('https://api.anthropic.com/v1/models?limit=100', {
                        method: 'GET',
                        headers: {
                            'x-api-key': apiKey,
                            'anthropic-version': '2023-06-01',
                            'anthropic-dangerous-direct-browser-access': 'true'
                        }
                    });

                    if (response.ok) {
                        var data = await response.json();
                        if (data.data && data.data.length > 0) {
                            return data.data.map(function(m) {
                                return {id: m.id, name: m.display_name || m.id};
                            });
                        }
                    }
                } catch (e) {
                    console.log('Failed to fetch Anthropic models:', e);
                }
                return null;
            }

            // Populate summarization model dropdown with same models
            function populateSummarizationModels(models) {
                var $sumSelect = $('#ai_assistant_summarization_model');
                $sumSelect.empty();
                $sumSelect.append('<option value="">Same as chat model (default)</option>');
                if (models && models.length > 0) {
                    models.forEach(function(model) {
                        var selected = model.id === aiAssistantSummarizationModel ? 'selected' : '';
                        $sumSelect.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                    });
                }
            }

            // Load models for selected provider
            function loadModels(provider) {
                var $select = $('#ai_assistant_model');
                $select.empty();

                switch (provider) {
                    case 'anthropic':
                        var formKey = $('#ai_assistant_anthropic_api_key').val();
                        var hasSavedKey = formKey && formKey.indexOf('***') === 0;
                        var apiKey = hasSavedKey ? aiAssistantAnthropicKey : formKey;
                        if (!apiKey) {
                            $select.html('<option value="">Enter API key to load models</option>');
                            updateSubmitState();
                            return;
                        }
                        $select.html('<option value="">Loading models...</option>');
                        updateSubmitState();
                        fetchAnthropicModels().then(function(models) {
                            $select.empty();
                            if (models && models.length > 0) {
                                var selectedModel = aiAssistantCurrentModel;
                                if (!selectedModel) {
                                    var sonnet = models.find(function(m) {
                                        return m.id.indexOf('sonnet') > -1 && m.id.indexOf('4-5') > -1;
                                    });
                                    if (sonnet) {
                                        selectedModel = sonnet.id;
                                    }
                                }
                                models.forEach(function(model) {
                                    var selected = model.id === selectedModel ? 'selected' : '';
                                    $select.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                                });
                                populateSummarizationModels(models);
                            } else {
                                $select.html('<option value="">Failed to load models - check API key</option>');
                            }
                            updateSubmitState();
                        });
                        return;
                    case 'openai':
                        var openaiFormKey = $('#ai_assistant_openai_api_key').val();
                        var openaiHasSavedKey = openaiFormKey && openaiFormKey.indexOf('***') === 0;
                        var openaiKey = openaiHasSavedKey ? aiAssistantOpenAIKey : openaiFormKey;
                        if (!openaiKey) {
                            $select.html('<option value="">Enter API key to load models</option>');
                            updateSubmitState();
                            return;
                        }
                        $select.html('<option value="">Loading models...</option>');
                        updateSubmitState();
                        fetchOpenAIModels().then(function(models) {
                            $select.empty();
                            if (models && models.length > 0) {
                                var selectedModel = aiAssistantCurrentModel;
                                if (!selectedModel) {
                                    var preferred = models.find(function(m) { return m.id === 'gpt-4o'; });
                                    if (preferred) {
                                        selectedModel = preferred.id;
                                    } else if (models.length > 0) {
                                        selectedModel = models[0].id;
                                    }
                                }
                                models.forEach(function(model) {
                                    var selected = model.id === selectedModel ? 'selected' : '';
                                    $select.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                                });
                                populateSummarizationModels(models);
                            } else {
                                $select.html('<option value="">Failed to load models - check API key</option>');
                            }
                            updateSubmitState();
                        });
                        return;
                    case 'local':
                        $select.html('<option value="">Loading from local server...</option>');
                        updateSubmitState();
                        fetchLocalModels();
                        return;
                }
            }

            // Fetch models from local LLM server
            async function fetchLocalModels() {
                var $select = $('#ai_assistant_model');
                var endpoint = $('#ai_assistant_local_endpoint').val() || 'http://localhost:11434';
                endpoint = endpoint.replace(/\/$/, '');

                var models = [];

                // Try Ollama API
                try {
                    var response = await fetch(endpoint + '/api/tags');
                    if (response.ok) {
                        var data = await response.json();
                        if (data.models) {
                            models = data.models.map(function(m) {
                                return {id: m.name, name: m.name};
                            });
                        }
                    }
                } catch (e) {}

                // Try OpenAI-compatible API (LM Studio)
                if (models.length === 0) {
                    try {
                        var response = await fetch(endpoint + '/v1/models');
                        if (response.ok) {
                            var data = await response.json();
                            if (data.data) {
                                models = data.data.map(function(m) {
                                    return {id: m.id, name: m.id};
                                });
                            }
                        }
                    } catch (e) {}
                }

                // Try alternate port (LM Studio on 1234)
                if (models.length === 0 && endpoint.indexOf(':11434') > -1) {
                    var altEndpoint = endpoint.replace(':11434', ':1234');
                    try {
                        var response = await fetch(altEndpoint + '/v1/models');
                        if (response.ok) {
                            var data = await response.json();
                            if (data.data) {
                                models = data.data.map(function(m) {
                                    return {id: m.id, name: m.id};
                                });
                                // Update the endpoint field
                                $('#ai_assistant_local_endpoint').val(altEndpoint);
                            }
                        }
                    } catch (e) {}
                }

                $select.empty();
                if (models.length > 0) {
                    var selectedModel = aiAssistantCurrentModel;
                    if (!selectedModel) {
                        selectedModel = models[0].id;
                    }
                    models.forEach(function(model) {
                        var selected = model.id === selectedModel ? 'selected' : '';
                        $select.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                    });
                    populateSummarizationModels(models);
                } else {
                    $select.html('<option value="">No models found - check if server is running</option>');
                }
                updateSubmitState();
            }

            // Test connection button - client-side testing
            $('.ai-test-connection').on('click', async function() {
                var $btn = $(this);
                var $status = $btn.nextAll('.ai-connection-status').first();
                var provider = $btn.data('provider');

                $status.text('Testing...').removeClass('success error');

                try {
                    if (provider === 'anthropic') {
                        await testAnthropicConnection($status);
                    } else if (provider === 'openai') {
                        await testOpenAIConnection($status);
                    } else if (provider === 'local') {
                        await testLocalConnection($status);
                    }
                } catch (error) {
                    $status.text(error.message).addClass('error');
                }
            });

            async function testAnthropicConnection($status) {
                var formKey = $('#ai_assistant_anthropic_api_key').val();
                var apiKey = (formKey && formKey.indexOf('***') !== 0) ? formKey : aiAssistantAnthropicKey;

                if (!apiKey) {
                    $status.text('Enter API key first').addClass('error');
                    return;
                }

                if (apiKey.indexOf('sk-ant-') !== 0) {
                    $status.text('Invalid key format (should start with sk-ant-)').addClass('error');
                    return;
                }

                try {
                    var response = await fetch('https://api.anthropic.com/v1/messages', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'x-api-key': apiKey,
                            'anthropic-version': '2023-06-01',
                            'anthropic-dangerous-direct-browser-access': 'true'
                        },
                        body: JSON.stringify({
                            model: 'claude-3-haiku-20240307',
                            max_tokens: 1,
                            messages: [{role: 'user', content: 'hi'}]
                        })
                    });

                    if (response.status === 401) {
                        $status.text('Invalid API key').addClass('error');
                    } else if (response.status === 403) {
                        $status.text('API key lacks permissions').addClass('error');
                    } else if (response.ok) {
                        $status.text('Connected to Anthropic API!').addClass('success');
                    } else {
                        var data = await response.json();
                        $status.text(data.error?.message || 'Connection failed').addClass('error');
                    }
                } catch (error) {
                    $status.text('Network error: ' + error.message).addClass('error');
                }
            }

            async function testOpenAIConnection($status) {
                var formKey = $('#ai_assistant_openai_api_key').val();
                var apiKey = (formKey && formKey.indexOf('***') !== 0) ? formKey : aiAssistantOpenAIKey;

                if (!apiKey) {
                    $status.text('Enter API key first').addClass('error');
                    return;
                }

                if (apiKey.indexOf('sk-') !== 0) {
                    $status.text('Invalid key format (should start with sk-)').addClass('error');
                    return;
                }

                try {
                    var response = await fetch('https://api.openai.com/v1/models', {
                        headers: {
                            'Authorization': 'Bearer ' + apiKey
                        }
                    });

                    if (response.status === 401) {
                        $status.text('Invalid API key').addClass('error');
                    } else if (response.ok) {
                        $status.text('Connected to OpenAI API!').addClass('success');
                    } else {
                        var data = await response.json();
                        $status.text(data.error?.message || 'Connection failed').addClass('error');
                    }
                } catch (error) {
                    $status.text('Network error: ' + error.message).addClass('error');
                }
            }

            async function testLocalConnection($status) {
                var endpoint = $('#ai_assistant_local_endpoint').val() || 'http://localhost:11434';
                endpoint = endpoint.replace(/\/$/, '');

                var endpoints = [endpoint];
                if (endpoint.indexOf(':11434') > -1) {
                    endpoints.push(endpoint.replace(':11434', ':1234'));
                }
                if (endpoint.indexOf(':1234') > -1) {
                    endpoints.push(endpoint.replace(':1234', ':11434'));
                }

                for (var i = 0; i < endpoints.length; i++) {
                    var testEndpoint = endpoints[i];

                    // Try Ollama
                    try {
                        var response = await fetch(testEndpoint + '/api/tags');
                        if (response.ok) {
                            if (testEndpoint !== endpoint) {
                                $('#ai_assistant_local_endpoint').val(testEndpoint);
                            }
                            $status.text('Connected to Ollama at ' + testEndpoint).addClass('success');
                            loadModels('local');
                            return;
                        }
                    } catch (e) {}

                    // Try OpenAI-compatible (LM Studio)
                    try {
                        var response = await fetch(testEndpoint + '/v1/models');
                        if (response.ok) {
                            if (testEndpoint !== endpoint) {
                                $('#ai_assistant_local_endpoint').val(testEndpoint);
                            }
                            $status.text('Connected to LM Studio at ' + testEndpoint).addClass('success');
                            loadModels('local');
                            return;
                        }
                    } catch (e) {}
                }

                $status.text('No local LLM found. Make sure Ollama or LM Studio is running.').addClass('error');
            }

            // Auto-detect endpoint button
            $('#ai-auto-detect-endpoint').on('click', async function() {
                var $btn = $(this);
                var $status = $btn.nextAll('.ai-connection-status').first();

                $btn.prop('disabled', true);
                $status.text('Detecting...').removeClass('success error');

                await testLocalConnection($status);
                $btn.prop('disabled', false);
            });

            // Refresh models button
            $('#ai-refresh-models').on('click', function() {
                loadModels($('#ai_assistant_provider').val());
            });

            // Reload models when API key changes
            $('#ai_assistant_anthropic_api_key').on('change', function() {
                if ($('#ai_assistant_provider').val() === 'anthropic') {
                    loadModels('anthropic');
                }
            });

            $('#ai_assistant_openai_api_key').on('change', function() {
                if ($('#ai_assistant_provider').val() === 'openai') {
                    loadModels('openai');
                }
            });

            // Initial load - disable submit until models are loaded
            $submitBtn.prop('disabled', true);
            loadModels(aiAssistantCurrentProvider);

            // Make sections collapsible (collapsed by default)
            function makeCollapsible(headingText, wrapperClass) {
                var $heading = $('h2:contains("' + headingText + '")');
                if ($heading.length) {
                    $heading.css('cursor', 'pointer')
                        .append(' <span class="dashicons dashicons-arrow-down-alt2" style="vertical-align: middle;"></span>');

                    var $content = $heading.nextUntil('h2, .submit, p.submit');
                    $content.wrapAll('<div class="' + wrapperClass + '"></div>');
                    var $wrapper = $('.' + wrapperClass);
                    $wrapper.hide();

                    $heading.on('click', function() {
                        $wrapper.slideToggle(200);
                        var $icon = $(this).find('.dashicons');
                        $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                    });
                }
            }

            makeCollapsible('Role Permissions', 'ai-permissions-content');
            makeCollapsible('Display Settings', 'ai-display-content');
        });
        </script>
        <?php
    }

    // Note: Connection testing and model fetching now happens client-side via JavaScript
    // to support WordPress Playground sandbox environment where PHP cannot reach external APIs

    /**
     * Get user's permission level
     */
    public function get_user_permission_level($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return 'none';
        }

        $permissions = get_option('ai_assistant_role_permissions', []);

        // Check each role the user has, return highest permission
        $level_priority = ['full' => 4, 'read_only' => 3, 'chat_only' => 2, 'none' => 1];
        $highest_level = 'none';
        $highest_priority = 0;

        foreach ($user->roles as $role) {
            $level = $permissions[$role] ?? 'none';
            $priority = $level_priority[$level] ?? 0;
            if ($priority > $highest_priority) {
                $highest_priority = $priority;
                $highest_level = $level;
            }
        }

        return $highest_level;
    }

    /**
     * Get page-specific CSS selector hints for the current admin screen
     */
    private function get_page_selector_hints(): string {
        global $pagenow;
        $hints = [];

        // Get current screen if available
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $screen_id = $screen ? $screen->id : '';

        // Plugin pages
        if ($pagenow === 'plugins.php') {
            $hints[] = '- .wp-list-table.plugins: Table of installed plugins';
            $hints[] = '- tr.active: Currently active plugins';
            $hints[] = '- tr.inactive: Currently inactive plugins';
            $hints[] = '- .plugin-title strong: Plugin names';
        } elseif ($pagenow === 'plugin-install.php') {
            $hints[] = '- .plugin-card: Each available plugin in the directory';
            $hints[] = '- .plugin-card .name: Plugin name';
            $hints[] = '- .plugin-card .desc: Plugin description';
            $hints[] = '- .plugin-action-buttons: Install/activate buttons';
        }

        // Posts/Pages
        elseif ($pagenow === 'edit.php') {
            $post_type = $_GET['post_type'] ?? 'post';
            $hints[] = "- .wp-list-table.posts: Table of {$post_type}s";
            $hints[] = '- .row-title: Post/page titles';
            $hints[] = '- .column-date: Publication dates';
            $hints[] = '- tr.status-publish: Published items';
            $hints[] = '- tr.status-draft: Draft items';
        } elseif ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $hints[] = '- #title, #post-title-0: Post title field';
            $hints[] = '- #content, .editor-styles-wrapper: Post content area';
            $hints[] = '- #categorydiv: Categories metabox';
            $hints[] = '- #tagsdiv-post_tag: Tags metabox';
        }

        // Media
        elseif ($pagenow === 'upload.php') {
            $hints[] = '- .wp-list-table.media: Media library table view';
            $hints[] = '- .attachments-browser: Media grid view';
            $hints[] = '- .attachment: Individual media items';
        }

        // Users
        elseif ($pagenow === 'users.php') {
            $hints[] = '- .wp-list-table.users: Users table';
            $hints[] = '- .column-username: Usernames';
            $hints[] = '- .column-role: User roles';
        } elseif ($pagenow === 'user-edit.php' || $pagenow === 'profile.php') {
            $hints[] = '- #your-profile: User profile form';
            $hints[] = '- #email: User email field';
            $hints[] = '- #role: User role selector';
        }

        // Themes
        elseif ($pagenow === 'themes.php') {
            if (isset($_GET['page'])) {
                $hints[] = '- #customize-controls: Customizer panel';
            } else {
                $hints[] = '- .themes: Theme grid';
                $hints[] = '- .theme: Individual theme cards';
                $hints[] = '- .theme.active: Currently active theme';
            }
        }

        // Settings
        elseif ($pagenow === 'options-general.php' || strpos($pagenow, 'options-') === 0) {
            $hints[] = '- .form-table: Settings form';
            $hints[] = '- .form-table th: Setting labels';
            $hints[] = '- .form-table td: Setting inputs';
        }

        // Comments
        elseif ($pagenow === 'edit-comments.php') {
            $hints[] = '- .wp-list-table.comments: Comments table';
            $hints[] = '- .comment-author: Comment author info';
            $hints[] = '- .comment-body: Comment content';
        }

        // Dashboard
        elseif ($pagenow === 'index.php' && $screen_id === 'dashboard') {
            $hints[] = '- #dashboard-widgets: Dashboard widget area';
            $hints[] = '- .postbox: Individual dashboard widgets';
            $hints[] = '- #welcome-panel: Welcome panel (if visible)';
        }

        // Menus
        elseif ($pagenow === 'nav-menus.php') {
            $hints[] = '- #menu-to-edit: Current menu items';
            $hints[] = '- .menu-item: Individual menu items';
            $hints[] = '- #menu-settings-column: Available menu items';
        }

        // Widgets
        elseif ($pagenow === 'widgets.php') {
            $hints[] = '- #widgets-right: Widget areas/sidebars';
            $hints[] = '- #available-widgets: Available widgets';
            $hints[] = '- .widget: Individual widgets';
        }

        // Frontend (non-admin)
        if (!is_admin()) {
            $hints[] = '- article, .post, .hentry: Blog posts';
            $hints[] = '- .entry-title: Post titles';
            $hints[] = '- .entry-content: Post content';
            $hints[] = '- .widget-area, .sidebar: Sidebar widgets';
            $hints[] = '- .site-header, header: Site header';
            $hints[] = '- .site-footer, footer: Site footer';
            $hints[] = '- .nav-menu, .main-navigation: Navigation menus';
        }

        return implode("\n", $hints);
    }

    /**
     * Get skills help tab content
     */
    private function get_skills_help_content(): string {
        $skills_dir = plugin_dir_path(__DIR__) . 'skills/';

        if (!is_dir($skills_dir)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        $files = glob($skills_dir . '*.md');
        if (empty($files)) {
            return '<p>' . __('No skills available. Add .md files to the skills/ directory.', 'ai-assistant') . '</p>';
        }

        $skills_by_category = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $frontmatter = [];
            if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $content, $matches)) {
                foreach (explode("\n", $matches[1]) as $line) {
                    if (preg_match('/^(\w+):\s*(.+)$/', trim($line), $kv)) {
                        $frontmatter[$kv[1]] = trim($kv[2], '"\'');
                    }
                }
            }

            $skill_id = basename($file, '.md');
            $category = $frontmatter['category'] ?? 'general';

            $skills_by_category[$category][] = [
                'id' => $skill_id,
                'title' => $frontmatter['title'] ?? $skill_id,
                'description' => $frontmatter['description'] ?? '',
            ];
        }

        ksort($skills_by_category);

        $nonce = wp_create_nonce('ai_assistant_skills');

        $html = '<p>' . __('Skills are specialized knowledge documents the AI can load on-demand. Click a skill to view its content:', 'ai-assistant') . '</p>';

        foreach ($skills_by_category as $category => $skills) {
            $html .= '<h4 style="margin: 1em 0 0.5em; text-transform: capitalize;">' . esc_html($category) . '</h4>';
            $html .= '<ul style="margin-top: 0;">';
            foreach ($skills as $skill) {
                $html .= '<li>';
                $html .= '<a href="#" class="ai-skill-link" data-skill="' . esc_attr($skill['id']) . '">';
                $html .= '<code>' . esc_html($skill['id']) . '</code>';
                if ($skill['title'] !== $skill['id']) {
                    $html .= ' - ' . esc_html($skill['title']);
                }
                $html .= '</a>';
                if ($skill['description']) {
                    $html .= '<br><em>' . esc_html($skill['description']) . '</em>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<div id="ai-skill-content" style="display:none; margin-top: 1em; padding: 10px; background: #f6f7f7; border-radius: 4px;">';
        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
        $html .= '<strong id="ai-skill-title"></strong>';
        $html .= '<a href="#" id="ai-skill-close" style="text-decoration: none;">&times; close</a>';
        $html .= '</div>';
        $html .= '<pre id="ai-skill-body" style="white-space: pre-wrap; margin: 0; max-height: 300px; overflow-y: auto;"></pre>';
        $html .= '</div>';

        $html .= '<script>
        jQuery(function($) {
            var nonce = ' . json_encode($nonce) . ';
            var ajaxUrl = ' . json_encode(admin_url('admin-ajax.php')) . ';

            $(".ai-skill-link").on("click", function(e) {
                e.preventDefault();
                var skill = $(this).data("skill");
                var $content = $("#ai-skill-content");
                var $body = $("#ai-skill-body");
                var $title = $("#ai-skill-title");

                $body.text("Loading...");
                $title.text("");
                $content.show();

                $.post(ajaxUrl, {
                    action: "ai_assistant_get_skill",
                    skill: skill,
                    _wpnonce: nonce
                }, function(response) {
                    if (response.success) {
                        $title.text(response.data.title);
                        $body.text(response.data.content);
                    } else {
                        $body.text("Error: " + response.data.message);
                    }
                }).fail(function() {
                    $body.text("Failed to load skill");
                });
            });

            $("#ai-skill-close").on("click", function(e) {
                e.preventDefault();
                $("#ai-skill-content").hide();
            });
        });
        </script>';

        return $html;
    }

    /**
     * Get the system prompt for the AI assistant
     */
    public function get_system_prompt() {
        $current_path = $_SERVER['REQUEST_URI'] ?? '/';
        // Strip the site's base path (e.g., /scope:default) from the URI
        $site_path = wp_parse_url(home_url(), PHP_URL_PATH);
        if ($site_path && $site_path !== '/' && strpos($current_path, $site_path) === 0) {
            $current_path = substr($current_path, strlen($site_path)) ?: '/';
        }
        $page_hints = $this->get_page_selector_hints();
        $wp_info = [
            'siteUrl' => get_site_url(),
            'currentPath' => $current_path,
            'wpVersion' => get_bloginfo('version'),
            'theme' => get_template(),
            'phpVersion' => phpversion(),
        ];

        $prompt = <<<PROMPT
You are the Playground AI Assistant integrated into WordPress. You help users manage and modify their WordPress installation.

Current WordPress Information:
- Site URL: {$wp_info['siteUrl']}
- Current Page: {$wp_info['currentPath']}
- WordPress Version: {$wp_info['wpVersion']}
- Active Theme: {$wp_info['theme']}
- PHP Version: {$wp_info['phpVersion']}
PROMPT;

        if ($page_hints) {
            $prompt .= "\n\nPAGE STRUCTURE (useful CSS selectors for get_page_html on this page):\n" . $page_hints;
        }

        $prompt .= <<<'PROMPT'


You have access to tools that let you interact with the WordPress filesystem and database. All file paths are relative to wp-content/.

If the user describes something they are seeing on the page, references UI elements, or asks about content visible on screen, use the get_page_html tool to see what they're looking at.

WORDPRESS ABILITIES API:
For common WordPress operations (posts, options, queries, users), use run_php with standard WordPress functions.
Use the Abilities API (list_abilities, get_ability, execute_ability) when:
- The task involves plugin-specific functionality (e.g., WooCommerce, forms, SEO plugins)
- The user asks about what actions are available
- You're unsure how to accomplish something with standard WordPress functions
Abilities expose plugin/theme capabilities in a standardized way.

FILE EDITING RULES:
- Use write_file ONLY for creating NEW files
- Use edit_file for modifying EXISTING files - it uses search/replace operations which is more efficient and easier to review
- The edit_file tool takes an array of {search, replace} pairs - each search string must be unique in the file
- If an edit_file operation fails (string not found or not unique), use read_file to see the current content and retry

IMPORTANT: For any destructive operations (file deletion, database modification, file overwriting), the user will be asked to confirm before execution. Be clear about what changes you're proposing.

Always explain what you're about to do before using tools.
PROMPT;

        $prompt .= $this->load_skills();

        return $prompt;
    }

    /**
     * Load all skill files from the skills directory
     */
    private function load_skills() {
        $skills_dir = dirname(__DIR__) . '/skills';
        if (!is_dir($skills_dir)) {
            return '';
        }

        $skill_files = glob($skills_dir . '/*.md');
        if (empty($skill_files)) {
            return '';
        }

        $skills_content = "\n\n=== SKILLS ===\nThe following skill documents contain important guidance. Follow them carefully.\n";

        foreach ($skill_files as $file) {
            $content = file_get_contents($file);
            $content = preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $content);
            $skill_name = basename($file, '.md');
            $skills_content .= "\n--- SKILL: {$skill_name} ---\n{$content}\n";
        }

        return $skills_content;
    }
}
