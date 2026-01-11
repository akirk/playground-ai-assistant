<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chat UI - Integrates with WordPress screen-meta (like Help/Screen Options tabs)
 */
class Chat_UI {

    public function __construct() {
        // Admin hooks
        add_action('admin_footer', [$this, 'render_screen_meta_tab']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Frontend hooks (only if enabled in settings)
        if ($this->is_frontend_enabled()) {
            add_action('wp_footer', [$this, 'render_screen_meta_tab']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        }
    }

    /**
     * Check if frontend display is enabled
     */
    private function is_frontend_enabled() {
        return get_option('ai_assistant_show_on_frontend', '0') === '1';
    }

    /**
     * Enqueue CSS and JavaScript
     */
    public function enqueue_assets() {
        if (!$this->user_has_access()) {
            return;
        }

        wp_enqueue_style(
            'ai-assistant-chat',
            AI_ASSISTANT_PLUGIN_URL . 'assets/css/chat.css',
            [],
            AI_ASSISTANT_VERSION
        );

        wp_enqueue_script(
            'ai-assistant-chat',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/chat.js',
            ['jquery'],
            AI_ASSISTANT_VERSION,
            true
        );

        $provider = get_option('ai_assistant_provider', 'anthropic');
        $settings = ai_assistant()->settings();

        // Get the appropriate API key based on provider
        $api_key = '';
        if ($provider === 'anthropic') {
            $api_key = $settings->get_api_key('anthropic');
        } elseif ($provider === 'openai') {
            $api_key = $settings->get_api_key('openai');
        }

        wp_localize_script('ai-assistant-chat', 'aiAssistantConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_assistant_chat'),
            'userPermission' => $settings->get_user_permission_level(),
            'provider' => $provider,
            'apiKey' => $api_key,
            'model' => get_option('ai_assistant_model', ''),
            'localEndpoint' => get_option('ai_assistant_local_endpoint', 'http://localhost:11434'),
            'settingsUrl' => admin_url('options-general.php?page=ai-assistant-settings'),
            'systemPrompt' => $settings->get_system_prompt(),
            'strings' => [
                'placeholder' => __('Ask me anything about your WordPress site...', 'ai-assistant'),
                'send' => __('Send', 'ai-assistant'),
                'thinking' => __('Thinking...', 'ai-assistant'),
                'error' => __('An error occurred. Please try again.', 'ai-assistant'),
                'confirmTitle' => __('Confirm Action', 'ai-assistant'),
                'confirm' => __('Confirm', 'ai-assistant'),
                'cancel' => __('Cancel', 'ai-assistant'),
                'newChat' => __('New Chat', 'ai-assistant'),
                'close' => __('Close', 'ai-assistant'),
            ]
        ]);
    }

    /**
     * Render the screen-meta tab and content panel (or standalone fallback)
     */
    public function render_screen_meta_tab() {
        if (!$this->user_has_access()) {
            return;
        }

        // Don't render on the dedicated AI Assistant page (it has its own UI)
        if (is_admin()) {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'tools_page_ai-conversations') {
                return;
            }
        }

        $panel_html = $this->get_panel_html();
        $button_text = esc_html__('AI Assistant', 'ai-assistant');
        ?>
        <!-- Standalone mode HTML - matches WordPress screen-meta structure -->
        <div id="ai-assistant-standalone-wrap" class="ai-assistant-standalone-wrap" style="display: none;">
            <div id="ai-assistant-standalone-panel" class="ai-assistant-standalone-panel">
                <?php echo $panel_html; ?>
            </div>
            <div class="ai-assistant-standalone-links">
                <div id="ai-assistant-standalone-trigger" class="ai-assistant-standalone-trigger hide-if-no-js">
                    <button type="button" aria-controls="ai-assistant-standalone-panel" aria-expanded="false">
                        <?php echo $button_text; ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var $screenMetaLinks = $('#screen-meta-links');
            var $screenMeta = $('#screen-meta');
            var hasScreenMeta = $screenMetaLinks.length > 0 && $screenMeta.length > 0;

            if (hasScreenMeta) {
                // Screen-meta mode: inject into WordPress admin UI
                $screenMetaLinks.prepend(
                    '<div id="ai-assistant-link-wrap" class="hide-if-no-js screen-meta-toggle">' +
                    '<button type="button" id="ai-assistant-link" class="button show-settings" aria-controls="ai-assistant-wrap" aria-expanded="false">' +
                    '<?php echo esc_js($button_text); ?>' +
                    '</button>' +
                    '</div>'
                );

                // Move the panel HTML from standalone to screen-meta
                var $standaloneWrap = $('#ai-assistant-standalone-wrap');
                var $standalonePanel = $('#ai-assistant-standalone-panel');
                var panelContent = $standalonePanel.html();
                $standaloneWrap.remove();
                $screenMeta.prepend(panelContent);

                // Close our panel when other screen-meta buttons are clicked
                $('#contextual-help-link, #show-settings-link').on('click', function() {
                    var $wrap = $('#ai-assistant-wrap');
                    if ($wrap.hasClass('screen-meta-active')) {
                        $wrap.slideUp('fast', function() {
                            $wrap.removeClass('screen-meta-active').addClass('hidden');
                        });
                        $('#ai-assistant-link').attr('aria-expanded', 'false');
                    }
                });

                // Handle toggle behavior (matching WordPress core pattern)
                $('#ai-assistant-link').on('click', function() {
                    var $wrap = $('#ai-assistant-wrap');
                    var $button = $(this);
                    var isExpanded = $button.attr('aria-expanded') === 'true';

                    // Close other panels first (like WordPress does)
                    $('#screen-meta').find('.screen-meta-active').not($wrap).slideUp('fast', function() {
                        $(this).removeClass('screen-meta-active').addClass('hidden');
                    });
                    $('.screen-meta-toggle button').not($button).attr('aria-expanded', 'false');

                    if (isExpanded) {
                        $wrap.slideUp('fast', function() {
                            $wrap.removeClass('screen-meta-active').addClass('hidden');
                        });
                        $button.attr('aria-expanded', 'false');
                    } else {
                        $wrap.removeClass('hidden').addClass('screen-meta-active').slideDown('fast', function() {
                            setTimeout(function() {
                                $('#ai-assistant-input').trigger('focus');
                                window.aiAssistant.scrollToBottom();
                            }, 50);
                        });
                        $button.attr('aria-expanded', 'true');

                        if ($('#ai-assistant-messages').children().length === 0) {
                            window.aiAssistant.loadWelcomeMessage();
                        }
                    }
                });
            } else {
                // Standalone mode: matches WordPress screen-meta behavior exactly
                var $wrap = $('#ai-assistant-standalone-wrap');
                var $panel = $('#ai-assistant-standalone-panel');
                var $trigger = $('#ai-assistant-standalone-trigger');
                var $button = $trigger.find('button');

                // Show the standalone wrapper
                $wrap.show();

                $button.on('click', function() {
                    var isExpanded = $button.attr('aria-expanded') === 'true';

                    if (isExpanded) {
                        // Close panel with slideUp animation (matches WordPress behavior)
                        $panel.slideUp('fast');
                        $button.attr('aria-expanded', 'false');
                    } else {
                        // Open panel with slideDown animation (matches WordPress behavior)
                        $panel.slideDown('fast', function() {
                            setTimeout(function() {
                                $('#ai-assistant-input').trigger('focus');
                                window.aiAssistant.scrollToBottom();
                            }, 50);
                        });
                        $button.attr('aria-expanded', 'true');

                        if ($('#ai-assistant-messages').children().length === 0) {
                            window.aiAssistant.loadWelcomeMessage();
                        }
                    }
                });

                // Close on Escape key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && $button.attr('aria-expanded') === 'true') {
                        $button.trigger('click');
                    }
                });
            }
        });
        </script>
        <?php
    }

    /**
     * Get the HTML for the panel content
     */
    private function get_panel_html() {
        $title = esc_html__('Playground AI Assistant', 'ai-assistant');
        $new_chat = esc_html__('New Chat', 'ai-assistant');
        $history = esc_html__('Conversations', 'ai-assistant');
        $settings = esc_html__('Settings', 'ai-assistant');
        $send = esc_html__('Send', 'ai-assistant');
        $placeholder = esc_attr__('Ask me anything about your WordPress site...', 'ai-assistant');
        $aria_label = esc_attr__('AI Assistant Tab', 'ai-assistant');
        $history_url = esc_url(admin_url('tools.php?page=ai-conversations'));
        $settings_url = esc_url(admin_url('options-general.php?page=ai-assistant-settings'));

        return '<div id="ai-assistant-wrap" class="hidden" tabindex="-1" aria-label="' . $aria_label . '">
            <div id="ai-assistant-columns">
                <div class="ai-assistant-chat-container">
                    <div class="ai-assistant-header">
                        <h2>' . $title . '</h2>
                        <div class="ai-assistant-header-actions">
                            <span id="ai-token-count" class="ai-token-count" title="Estimated token usage">0 tokens</span>
                            <span class="ai-header-sep">|</span>
                            <label class="ai-yolo-label" title="Skip confirmation prompts for destructive actions"><input type="checkbox" id="ai-assistant-yolo"> YOLO Mode</label>
                            <span class="ai-header-sep">|</span>
                            <a href="#" id="ai-assistant-new-chat" class="ai-header-link">' . $new_chat . '</a>
                            <span class="ai-header-sep">|</span>
                            <a href="' . $history_url . '" class="ai-header-link">' . $history . '</a>
                            <span class="ai-header-sep">|</span>
                            <a href="' . $settings_url . '" class="ai-header-link">' . $settings . '</a>
                        </div>
                    </div>
                    <div id="ai-assistant-messages"></div>
                    <div id="ai-assistant-loading" style="display: none;">
                        <div class="ai-loading-dots"><span></span><span></span><span></span></div>
                    </div>
                    <div id="ai-assistant-pending-actions"></div>
                    <div class="ai-assistant-input-area">
                        <textarea id="ai-assistant-input" placeholder="' . $placeholder . '" rows="2"></textarea>
                        <button type="button" id="ai-assistant-send" class="button button-primary">' . $send . '</button>
                    </div>
                </div>
            </div>
        </div>';
    }

    /**
     * Check if current user has access to AI Assistant
     */
    private function user_has_access() {
        $permission = ai_assistant()->settings()->get_user_permission_level();
        return $permission !== 'none';
    }
}
