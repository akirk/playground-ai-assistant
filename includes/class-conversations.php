<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Conversations {

    const POST_TYPE = 'ai_conversation';

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('wp_ajax_ai_assistant_save_conversation', [$this, 'ajax_save_conversation']);
        add_action('wp_ajax_ai_assistant_load_conversation', [$this, 'ajax_load_conversation']);
        add_action('wp_ajax_ai_assistant_list_conversations', [$this, 'ajax_list_conversations']);
        add_action('wp_ajax_ai_assistant_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_ai_assistant_rename_conversation', [$this, 'ajax_rename_conversation']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_columns'], 10, 2);
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('AI Conversations', 'ai-assistant'),
                'singular_name' => __('Conversation', 'ai-assistant'),
                'menu_name' => __('Conversations', 'ai-assistant'),
                'all_items' => __('All Conversations', 'ai-assistant'),
                'view_item' => __('View Conversation', 'ai-assistant'),
                'edit_item' => __('Continue Conversation', 'ai-assistant'),
                'search_items' => __('Search Conversations', 'ai-assistant'),
                'not_found' => __('No conversations found', 'ai-assistant'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'supports' => ['title'],
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }

    public function add_meta_boxes() {
        add_meta_box(
            'ai_conversation_messages',
            __('Conversation Messages', 'ai-assistant'),
            [$this, 'render_messages_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'ai_conversation_continue',
            __('Continue Conversation', 'ai-assistant'),
            [$this, 'render_continue_meta_box'],
            self::POST_TYPE,
            'side',
            'high'
        );
    }

    private function get_messages($post) {
        if (empty($post->post_content)) {
            return [];
        }
        $json = base64_decode($post->post_content);
        return json_decode($json, true) ?: [];
    }

    public function render_messages_meta_box($post) {
        $messages = $this->get_messages($post);
        if (empty($messages)) {
            echo '<p>' . esc_html__('No messages in this conversation.', 'ai-assistant') . '</p>';
            return;
        }

        echo '<div class="ai-conversation-history">';
        foreach ($messages as $message) {
            $role = esc_attr($message['role']);
            $content = $this->format_message_content($message['content']);
            echo '<div class="ai-history-message ai-history-' . $role . '">';
            echo '<strong>' . esc_html(ucfirst($role)) . ':</strong>';
            echo '<div class="ai-history-content">' . $content . '</div>';
            echo '</div>';
        }
        echo '</div>';

        echo '<style>
            .ai-conversation-history { max-height: 600px; overflow-y: auto; }
            .ai-history-message { padding: 10px; margin: 5px 0; border-radius: 5px; }
            .ai-history-user { background: #e3f2fd; }
            .ai-history-assistant { background: #f5f5f5; }
            .ai-history-system { background: #fff3e0; font-style: italic; }
            .ai-history-content { margin-top: 5px; white-space: pre-wrap; }
            .ai-history-content pre { background: #263238; color: #aed581; padding: 10px; overflow-x: auto; }
        </style>';
    }

    public function render_continue_meta_box($post) {
        $conversation_url = admin_url('tools.php?page=ai-conversations&conversation=' . $post->ID);
        echo '<p>';
        echo '<a href="' . esc_url($conversation_url) . '" class="button button-primary button-large" style="width:100%;text-align:center;">';
        echo esc_html__('Continue this conversation', 'ai-assistant');
        echo '</a>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Opens the chat interface with this conversation loaded.', 'ai-assistant') . '</p>';
    }

    private function format_message_content($content) {
        if (is_array($content)) {
            $text = '';
            foreach ($content as $block) {
                if (is_array($block)) {
                    if (isset($block['type']) && $block['type'] === 'text') {
                        $text .= $block['text'];
                    } elseif (isset($block['type']) && $block['type'] === 'tool_use') {
                        $text .= "\n[Tool: " . $block['name'] . "]\n";
                    } elseif (isset($block['type']) && $block['type'] === 'tool_result') {
                        $text .= "\n[Tool Result]\n";
                    }
                }
            }
            $content = $text ?: json_encode($content, JSON_PRETTY_PRINT);
        }
        return wp_kses_post(nl2br(esc_html($content)));
    }

    public function add_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['message_count'] = __('Messages', 'ai-assistant');
                $new_columns['last_message'] = __('Last Message', 'ai-assistant');
            }
        }
        return $new_columns;
    }

    public function render_columns($column, $post_id) {
        $post = get_post($post_id);
        $messages = $this->get_messages($post);

        switch ($column) {
            case 'message_count':
                echo count($messages);
                break;
            case 'last_message':
                if (!empty($messages)) {
                    $last = end($messages);
                    $content = is_array($last['content']) ? '[Complex content]' : $last['content'];
                    echo esc_html(wp_trim_words($content, 10, '...'));
                }
                break;
        }
    }

    public function ajax_save_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $messages_base64 = $_POST['messages'] ?? '';
        $title = sanitize_text_field($_POST['title'] ?? '');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');

        // Decode base64 to get message count for title generation
        $messages_json = base64_decode($messages_base64);
        $messages = json_decode($messages_json, true) ?: [];

        if (empty($title) && !empty($messages)) {
            $first_user_message = array_filter($messages, function($m) {
                return $m['role'] === 'user';
            });
            $first = reset($first_user_message);
            if ($first) {
                $content = is_array($first['content']) ? '' : $first['content'];
                $title = wp_trim_words($content, 8, '...');
            }
            if (empty($title)) {
                $title = __('Conversation', 'ai-assistant') . ' ' . date('Y-m-d H:i');
            }
        }

        $post_data = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_author' => get_current_user_id(),
            'post_content' => $messages_base64,
        ];

        if ($conversation_id > 0) {
            $existing = get_post($conversation_id);
            if ($existing && $existing->post_type === self::POST_TYPE) {
                $post_data['ID'] = $conversation_id;
            }
        }

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            wp_send_json_error(['message' => $post_id->get_error_message()]);
        }

        update_post_meta($post_id, '_ai_message_count', count($messages));
        if ($provider) {
            update_post_meta($post_id, '_ai_provider', $provider);
        }
        if ($model) {
            update_post_meta($post_id, '_ai_model', $model);
        }

        wp_send_json_success([
            'conversation_id' => $post_id,
            'title' => get_the_title($post_id),
        ]);
    }

    public function ajax_load_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        // Send base64 directly - client will decode
        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => $post->post_title,
            'messages_base64' => $post->post_content ?: '',
            'provider' => get_post_meta($conversation_id, '_ai_provider', true) ?: '',
            'model' => get_post_meta($conversation_id, '_ai_model', true) ?: '',
        ]);
    }

    public function ajax_list_conversations() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $args = [
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 20,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if (!current_user_can('edit_others_posts')) {
            $args['author'] = get_current_user_id();
        }

        $query = new \WP_Query($args);
        $conversations = [];

        foreach ($query->posts as $post) {
            $message_count = get_post_meta($post->ID, '_ai_message_count', true);
            $conversations[] = [
                'id' => $post->ID,
                'title' => $post->post_title,
                'date' => $post->post_modified,
                'message_count' => $message_count ?: 0,
            ];
        }

        wp_send_json_success(['conversations' => $conversations]);
    }

    public function ajax_delete_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('delete_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_delete_post($conversation_id, true);

        wp_send_json_success(['deleted' => true]);
    }

    public function ajax_rename_conversation() {
        check_ajax_referer('ai_assistant_chat', '_wpnonce');

        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');

        if ($conversation_id <= 0) {
            wp_send_json_error(['message' => 'Invalid conversation ID']);
        }

        if (empty($title)) {
            wp_send_json_error(['message' => 'Title cannot be empty']);
        }

        $post = get_post($conversation_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(['message' => 'Conversation not found']);
        }

        if ($post->post_author != get_current_user_id() && !current_user_can('edit_others_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        wp_update_post([
            'ID' => $conversation_id,
            'post_title' => $title,
        ]);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'title' => $title,
        ]);
    }
}
