<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Changes_Admin {

    private $change_tracker;

    public function __construct(Change_Tracker $change_tracker) {
        $this->change_tracker = $change_tracker;
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ai_assistant_get_changes', [$this, 'ajax_get_changes']);
        add_action('wp_ajax_ai_assistant_generate_diff', [$this, 'ajax_generate_diff']);
        add_action('wp_ajax_ai_assistant_clear_changes', [$this, 'ajax_clear_changes']);
        add_action('admin_action_ai_assistant_download_diff', [$this, 'handle_diff_download']);
    }

    public function add_admin_page(): void {
        add_management_page(
            __('AI Changes', 'ai-assistant'),
            __('AI Changes', 'ai-assistant'),
            'manage_options',
            'ai-changes',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'tools_page_ai-changes') {
            return;
        }

        wp_enqueue_style(
            'ai-assistant-changes',
            AI_ASSISTANT_PLUGIN_URL . 'assets/css/changes.css',
            [],
            AI_ASSISTANT_VERSION
        );

        wp_enqueue_script(
            'ai-assistant-changes',
            AI_ASSISTANT_PLUGIN_URL . 'assets/js/changes.js',
            ['jquery'],
            AI_ASSISTANT_VERSION,
            true
        );

        wp_localize_script('ai-assistant-changes', 'aiChanges', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ai_assistant_changes'),
            'downloadNonce' => wp_create_nonce('ai_assistant_download_diff'),
            'downloadUrl' => admin_url('admin.php?action=ai_assistant_download_diff'),
            'strings' => [
                'confirmClear' => __('Are you sure you want to clear all tracked changes? This cannot be undone.', 'ai-assistant'),
                'noSelection' => __('Please select at least one file to download.', 'ai-assistant'),
                'clearing' => __('Clearing...', 'ai-assistant'),
            ],
        ]);
    }

    public function render_page(): void {
        $directories = $this->change_tracker->get_changes_by_directory();
        $has_changes = !empty($directories);
        ?>
        <div class="wrap ai-changes-wrap">
            <h1><?php esc_html_e('AI Changes', 'ai-assistant'); ?></h1>

            <p class="description">
                <?php esc_html_e('Track and export changes made by the AI assistant. Select files or directories to generate a unified diff patch.', 'ai-assistant'); ?>
            </p>

            <?php if ($has_changes): ?>
            <div class="ai-changes-actions">
                <button type="button" class="button" id="ai-select-all">
                    <?php esc_html_e('Select All', 'ai-assistant'); ?>
                </button>
                <button type="button" class="button" id="ai-clear-selection">
                    <?php esc_html_e('Clear Selection', 'ai-assistant'); ?>
                </button>
                <button type="button" class="button" id="ai-clear-history">
                    <?php esc_html_e('Clear History', 'ai-assistant'); ?>
                </button>
            </div>

            <div class="ai-changes-tree">
                <?php foreach ($directories as $dir => $data): ?>
                <div class="ai-changes-directory" data-dir="<?php echo esc_attr($dir); ?>">
                    <div class="ai-changes-directory-header">
                        <label>
                            <input type="checkbox" class="ai-dir-checkbox" data-dir="<?php echo esc_attr($dir); ?>">
                            <span class="ai-changes-toggle">▶</span>
                            <?php
                            $is_file = pathinfo($dir, PATHINFO_EXTENSION) !== '';
                            ?><span class="ai-changes-dir-name"><?php echo esc_html($dir); ?><?php echo $is_file ? '' : '/'; ?></span>
                            <span class="ai-changes-count">(<?php echo esc_html($data['count']); ?> <?php echo $data['count'] === 1 ? 'change' : 'changes'; ?>)</span>
                        </label>
                    </div>
                    <div class="ai-changes-files" style="display: none;">
                        <?php foreach ($data['files'] as $file): ?>
                        <div class="ai-changes-file">
                            <div class="ai-changes-file-row">
                                <button type="button" class="ai-file-preview-toggle" data-id="<?php echo esc_attr($file['id']); ?>" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>">▶</button>
                                <label>
                                    <input type="checkbox" class="ai-file-checkbox"
                                           data-id="<?php echo esc_attr($file['id']); ?>"
                                           data-dir="<?php echo esc_attr($dir); ?>">
                                    <span class="ai-changes-file-path"><?php echo esc_html($file['relative_path'] ?: basename($file['path'])); ?></span>
                                    <span class="ai-changes-type ai-changes-type-<?php echo esc_attr($file['change_type']); ?>">
                                        <?php echo esc_html(ucfirst($file['change_type'])); ?>
                                    </span>
                                    <?php if ($file['is_binary']): ?>
                                    <span class="ai-changes-binary"><?php esc_html_e('Binary', 'ai-assistant'); ?></span>
                                    <?php endif; ?>
                                    <span class="ai-changes-date">
                                        <?php echo esc_html(human_time_diff(strtotime($file['date']), current_time('timestamp')) . ' ago'); ?>
                                    </span>
                                </label>
                            </div>
                            <div class="ai-file-inline-preview" data-id="<?php echo esc_attr($file['id']); ?>" style="display: none;">
                                <pre><code></code></pre>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="ai-diff-preview" class="ai-diff-preview">
                <div class="ai-diff-preview-header">
                    <h2><?php esc_html_e('Diff Preview', 'ai-assistant'); ?></h2>
                    <div class="ai-diff-preview-actions">
                        <button type="button" class="button button-primary" id="ai-download-diff">
                            <?php esc_html_e('Download .patch', 'ai-assistant'); ?>
                        </button>
                        <button type="button" class="button" id="ai-close-preview">
                            <?php esc_html_e('Close', 'ai-assistant'); ?>
                        </button>
                    </div>
                </div>
                <pre class="ai-diff-content"><code></code></pre>
            </div>
            <?php else: ?>
            <div class="ai-changes-empty">
                <p><?php esc_html_e('No changes have been tracked yet.', 'ai-assistant'); ?></p>
                <p class="description"><?php esc_html_e('Changes made through the AI assistant (file writes, edits, and deletions) will appear here.', 'ai-assistant'); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function ajax_get_changes(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $directories = $this->change_tracker->get_changes_by_directory();
        wp_send_json_success(['directories' => $directories]);
    }

    public function ajax_generate_diff(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_ids = isset($_POST['file_ids']) ? array_map('intval', (array) $_POST['file_ids']) : [];

        if (empty($file_ids)) {
            wp_send_json_error(['message' => 'No files selected']);
        }

        $diff = $this->change_tracker->generate_diff($file_ids);
        wp_send_json_success(['diff' => $diff]);
    }

    public function ajax_clear_changes(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_ids = isset($_POST['file_ids']) ? array_map('intval', (array) $_POST['file_ids']) : [];
        $deleted = $this->change_tracker->clear_changes($file_ids);

        wp_send_json_success([
            'deleted' => $deleted,
            'message' => sprintf(__('%d change(s) cleared.', 'ai-assistant'), $deleted),
        ]);
    }

    public function handle_diff_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'ai-assistant'));
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ai_assistant_download_diff')) {
            wp_die(__('Security check failed.', 'ai-assistant'));
        }

        $file_ids = isset($_GET['file_ids']) ? array_map('intval', explode(',', $_GET['file_ids'])) : [];

        if (empty($file_ids)) {
            wp_die(__('No files selected.', 'ai-assistant'));
        }

        $diff = $this->change_tracker->generate_diff($file_ids);
        $filename = 'ai-changes-' . date('Y-m-d-His') . '.patch';

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($diff));
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $diff;
        exit;
    }
}
