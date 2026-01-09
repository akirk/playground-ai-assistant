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
        // Note: Connection testing and model fetching now happens client-side via JavaScript
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

    /**
     * Encrypt sensitive data
     */
    public function encrypt($data) {
        if (empty($data)) {
            return '';
        }
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt sensitive data
     */
    public function decrypt($data) {
        if (empty($data)) {
            return '';
        }
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            __('Playground AI Assistant Settings', 'ai-assistant'),
            __('Playground AI Assistant', 'ai-assistant'),
            'manage_options',
            'ai-assistant-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Provider settings
        register_setting('ai_assistant_settings', 'ai_assistant_provider');
        register_setting('ai_assistant_settings', 'ai_assistant_model');
        register_setting('ai_assistant_settings', 'ai_assistant_anthropic_api_key', [
            'sanitize_callback' => [$this, 'sanitize_api_key']
        ]);
        register_setting('ai_assistant_settings', 'ai_assistant_openai_api_key', [
            'sanitize_callback' => [$this, 'sanitize_api_key']
        ]);
        register_setting('ai_assistant_settings', 'ai_assistant_local_endpoint');
        register_setting('ai_assistant_settings', 'ai_assistant_local_model');
        register_setting('ai_assistant_settings', 'ai_assistant_role_permissions');

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

        // Permissions section
        add_settings_section(
            'ai_assistant_permissions_section',
            __('Role Permissions', 'ai-assistant'),
            [$this, 'permissions_section_callback'],
            'ai-assistant-settings'
        );

        add_settings_field(
            'ai_assistant_role_permissions',
            __('Access Levels', 'ai-assistant'),
            [$this, 'permissions_field_callback'],
            'ai-assistant-settings',
            'ai_assistant_permissions_section'
        );
    }

    /**
     * Sanitize API key (encrypt before storing)
     */
    public function sanitize_api_key($value) {
        if (empty($value) || strpos($value, '***') === 0) {
            // Keep existing value if masked or empty
            return get_option(current_filter() === 'sanitize_option_ai_assistant_anthropic_api_key'
                ? 'ai_assistant_anthropic_api_key'
                : 'ai_assistant_openai_api_key');
        }
        return $this->encrypt($value);
    }

    /**
     * Get decrypted API key
     */
    public function get_api_key($provider) {
        $option = $provider === 'anthropic' ? 'ai_assistant_anthropic_api_key' : 'ai_assistant_openai_api_key';
        $encrypted = get_option($option);
        return $this->decrypt($encrypted);
    }

    /**
     * Provider section description
     */
    public function provider_section_callback() {
        echo '<p>' . esc_html__('Configure your LLM provider and API credentials.', 'ai-assistant') . '</p>';
    }

    /**
     * Permissions section description
     */
    public function permissions_section_callback() {
        echo '<p>' . esc_html__('Configure what each WordPress role can do with the AI Assistant.', 'ai-assistant') . '</p>';
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
        <script>
            var aiAssistantCurrentModel = '<?php echo esc_js($model); ?>';
            var aiAssistantCurrentProvider = '<?php echo esc_js($provider); ?>';
        </script>
        <?php
    }

    /**
     * Permissions matrix field
     */
    public function permissions_field_callback() {
        $permissions = get_option('ai_assistant_role_permissions', []);
        $roles = wp_roles()->roles;
        $levels = [
            'full' => __('Full Access', 'ai-assistant'),
            'read_only' => __('Read Only', 'ai-assistant'),
            'chat_only' => __('Chat Only (No Tools)', 'ai-assistant'),
            'none' => __('No Access', 'ai-assistant')
        ];
        ?>
        <table class="widefat" style="max-width: 500px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Role', 'ai-assistant'); ?></th>
                    <th><?php esc_html_e('Access Level', 'ai-assistant'); ?></th>
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
            .ai-provider-field[data-provider] { display: none; }
            .ai-provider-field[data-provider="<?php echo esc_attr(get_option('ai_assistant_provider', 'anthropic')); ?>"] {
                display: inline-block;
            }
            tr:has(.ai-provider-field[data-provider]:not([data-provider="<?php echo esc_attr(get_option('ai_assistant_provider', 'anthropic')); ?>"])) {
                display: none;
            }
            .ai-connection-status { margin-left: 10px; }
            .ai-connection-status.success { color: green; }
            .ai-connection-status.error { color: red; }
        </style>
        <script>
        jQuery(function($) {
            // Show/hide provider-specific fields
            $('#ai_assistant_provider').on('change', function() {
                var provider = $(this).val();
                $('.ai-provider-field').closest('tr').hide();
                $('.ai-provider-field[data-provider="' + provider + '"]').closest('tr').show();
                loadModels(provider);
            });

            // Load models for selected provider (static lists for cloud, fetch for local)
            function loadModels(provider) {
                var $select = $('#ai_assistant_model');
                $select.empty();

                var models = [];
                switch (provider) {
                    case 'anthropic':
                        models = [
                            {id: 'claude-sonnet-4-20250514', name: 'Claude Sonnet 4 (Recommended)'},
                            {id: 'claude-opus-4-20250514', name: 'Claude Opus 4'},
                            {id: 'claude-3-5-sonnet-20241022', name: 'Claude 3.5 Sonnet'},
                            {id: 'claude-3-5-haiku-20241022', name: 'Claude 3.5 Haiku'}
                        ];
                        break;
                    case 'openai':
                        models = [
                            {id: 'gpt-4o', name: 'GPT-4o (Recommended)'},
                            {id: 'gpt-4o-mini', name: 'GPT-4o Mini'},
                            {id: 'gpt-4-turbo', name: 'GPT-4 Turbo'},
                            {id: 'gpt-3.5-turbo', name: 'GPT-3.5 Turbo'}
                        ];
                        break;
                    case 'local':
                        $select.html('<option value="">Loading from local server...</option>');
                        fetchLocalModels();
                        return;
                }

                models.forEach(function(model) {
                    var selected = model.id === aiAssistantCurrentModel ? 'selected' : '';
                    $select.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                });
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
                    models.forEach(function(model) {
                        var selected = model.id === aiAssistantCurrentModel ? 'selected' : '';
                        $select.append('<option value="' + model.id + '" ' + selected + '>' + model.name + '</option>');
                    });
                } else {
                    $select.html('<option value="">No models found - check if server is running</option>');
                }
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
                var apiKey = $('#ai_assistant_anthropic_api_key').val();

                if (!apiKey || apiKey.indexOf('***') === 0) {
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
                            model: 'claude-3-5-haiku-20241022',
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
                var apiKey = $('#ai_assistant_openai_api_key').val();

                if (!apiKey || apiKey.indexOf('***') === 0) {
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

            // Initial load
            loadModels(aiAssistantCurrentProvider);
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
}
