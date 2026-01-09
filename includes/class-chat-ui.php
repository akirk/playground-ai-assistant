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
        // Add our tab to screen-meta-links
        add_action('admin_footer', [$this, 'render_screen_meta_tab']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue CSS and JavaScript
     */
    public function enqueue_assets() {
        if (!is_admin() || !$this->user_has_access()) {
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
            'wpInfo' => [
                'siteUrl' => get_site_url(),
                'wpVersion' => get_bloginfo('version'),
                'theme' => get_template(),
                'phpVersion' => phpversion(),
            ],
            'strings' => [
                'placeholder' => __('Ask me anything about your WordPress site...', 'ai-assistant'),
                'send' => __('Send', 'ai-assistant'),
                'thinking' => __('Thinking...', 'ai-assistant'),
                'error' => __('An error occurred. Please try again.', 'ai-assistant'),
                'confirm' => __('Confirm', 'ai-assistant'),
                'cancel' => __('Cancel', 'ai-assistant'),
                'confirmTitle' => __('Confirm Action', 'ai-assistant'),
                'confirmAll' => __('Confirm All', 'ai-assistant'),
                'skipAll' => __('Skip All', 'ai-assistant'),
                'newChat' => __('New Chat', 'ai-assistant'),
                'close' => __('Close', 'ai-assistant'),
            ]
        ]);
    }

    /**
     * Render the screen-meta tab and content panel
     */
    public function render_screen_meta_tab() {
        if (!is_admin() || !$this->user_has_access()) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add the AI Assistant tab button to screen-meta-links
            var $screenMetaLinks = $('#screen-meta-links');
            if ($screenMetaLinks.length) {
                $screenMetaLinks.prepend(
                    '<div id="ai-assistant-link-wrap" class="hide-if-no-js screen-meta-toggle">' +
                    '<button type="button" id="ai-assistant-link" class="button show-settings" aria-controls="ai-assistant-wrap" aria-expanded="false">' +
                    '<?php esc_html_e('AI Assistant', 'ai-assistant'); ?>' +
                    '</button>' +
                    '</div>'
                );
            }

            // Add the AI Assistant panel to screen-meta
            var $screenMeta = $('#screen-meta');
            if ($screenMeta.length) {
                $screenMeta.prepend(<?php echo json_encode($this->get_panel_html()); ?>);
            }

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
                    // Close our panel
                    $wrap.slideUp('fast', function() {
                        $wrap.removeClass('screen-meta-active').addClass('hidden');
                    });
                    $button.attr('aria-expanded', 'false');
                } else {
                    // Open our panel
                    $wrap.removeClass('hidden').addClass('screen-meta-active').slideDown('fast', function() {
                        $('#ai-assistant-input').focus();
                    });
                    $button.attr('aria-expanded', 'true');

                    // Initialize welcome message if empty
                    if ($('#ai-assistant-messages').children().length === 0) {
                        window.aiAssistant.loadWelcomeMessage();
                    }
                }
            });
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
        $settings = esc_html__('Settings', 'ai-assistant');
        $send = esc_html__('Send', 'ai-assistant');
        $placeholder = esc_attr__('Ask me anything about your WordPress site...', 'ai-assistant');
        $aria_label = esc_attr__('AI Assistant Tab', 'ai-assistant');
        $settings_url = esc_url(admin_url('options-general.php?page=ai-assistant-settings'));

        return '<div id="ai-assistant-wrap" class="hidden" tabindex="-1" aria-label="' . $aria_label . '">
            <div id="ai-assistant-columns">
                <div class="ai-assistant-chat-container">
                    <div class="ai-assistant-header">
                        <h2>' . $title . '</h2>
                        <div class="ai-assistant-header-actions">
                            <button type="button" id="ai-assistant-new-chat" class="button">' . $new_chat . '</button>
                            <a href="' . $settings_url . '" class="button">' . $settings . '</a>
                            <button type="button" id="ai-assistant-expand" class="button" title="Expand">&#10530;</button>
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
