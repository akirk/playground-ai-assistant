<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Changes_Admin {

    private $change_tracker;
    private $executor;

    public function __construct(Change_Tracker $change_tracker) {
        $this->change_tracker = $change_tracker;
        $this->executor = new Executor($change_tracker);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_ai_assistant_get_changes', [$this, 'ajax_get_changes']);
        add_action('wp_ajax_ai_assistant_generate_diff', [$this, 'ajax_generate_diff']);
        add_action('wp_ajax_ai_assistant_clear_changes', [$this, 'ajax_clear_changes']);
        add_action('wp_ajax_ai_assistant_apply_patch', [$this, 'ajax_apply_patch']);
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
                'importing' => __('Importing...', 'ai-assistant'),
                'importSuccess' => __('Patch applied successfully! %d file(s) modified.', 'ai-assistant'),
                'importError' => __('Failed to apply patch.', 'ai-assistant'),
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

            <div class="ai-changes-actions">
                <input type="file" id="ai-patch-file" accept=".patch,.diff,.txt" style="display:none;">
                <button type="button" class="button" id="ai-import-patch">
                    <?php esc_html_e('Import Patch', 'ai-assistant'); ?>
                </button>
                <?php if ($has_changes): ?>
                <button type="button" class="button" id="ai-select-all">
                    <?php esc_html_e('Select All', 'ai-assistant'); ?>
                </button>
                <button type="button" class="button" id="ai-clear-selection">
                    <?php esc_html_e('Clear Selection', 'ai-assistant'); ?>
                </button>
                <button type="button" class="button" id="ai-clear-history">
                    <?php esc_html_e('Clear History', 'ai-assistant'); ?>
                </button>
                <?php endif; ?>
            </div>

            <?php if ($has_changes): ?>

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

    public function ajax_apply_patch(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (empty($_FILES['patch_file'])) {
            wp_send_json_error(['message' => 'No file uploaded']);
        }

        $file = $_FILES['patch_file'];
        $patch_content = file_get_contents($file['tmp_name']);

        if ($patch_content === false) {
            wp_send_json_error(['message' => 'Failed to read file']);
        }

        try {
            $operations = $this->parse_patch($patch_content);

            if (empty($operations)) {
                wp_send_json_error(['message' => 'No valid operations found in patch']);
            }

            $modified = 0;
            foreach ($operations as $op) {
                $this->executor->execute_tool($op['tool'], $op['arguments']);
                $modified++;
            }

            wp_send_json_success([
                'modified' => $modified,
                'message' => sprintf(__('%d file(s) modified.', 'ai-assistant'), $modified),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function parse_patch(string $patch): array {
        $operations = [];
        $blocks = preg_split('/^diff --git /m', $patch);

        foreach ($blocks as $block) {
            if (empty(trim($block))) {
                continue;
            }

            $block = 'diff --git ' . $block;
            $op = $this->parse_diff_block($block);
            if ($op) {
                $operations[] = $op;
            }
        }

        return $operations;
    }

    private function parse_diff_block(string $block): ?array {
        $lines = explode("\n", $block);

        // Extract path from "diff --git a/path b/path"
        if (!preg_match('/^diff --git a\/(.+) b\/(.+)$/', $lines[0], $matches)) {
            return null;
        }
        $path = $matches[2];

        $is_new_file = strpos($block, 'new file mode') !== false;
        $is_deleted = strpos($block, 'deleted file mode') !== false;

        if ($is_deleted) {
            return [
                'tool' => 'delete_file',
                'arguments' => ['path' => $path],
            ];
        }

        if ($is_new_file) {
            $content = $this->extract_new_file_content($lines);
            return [
                'tool' => 'write_file',
                'arguments' => [
                    'path' => $path,
                    'content' => $content,
                ],
            ];
        }

        // Modified file - need to apply hunks
        $full_path = WP_CONTENT_DIR . '/' . $path;
        if (!file_exists($full_path)) {
            return null;
        }

        $original = file_get_contents($full_path);
        $new_content = $this->apply_hunks($original, $lines);

        if ($new_content === null) {
            return null;
        }

        return [
            'tool' => 'write_file',
            'arguments' => [
                'path' => $path,
                'content' => $new_content,
            ],
        ];
    }

    private function extract_new_file_content(array $lines): string {
        $content_lines = [];
        $in_content = false;

        foreach ($lines as $line) {
            if (strpos($line, '@@') === 0) {
                $in_content = true;
                continue;
            }
            if ($in_content && isset($line[0]) && $line[0] === '+') {
                $content_lines[] = substr($line, 1);
            }
        }

        return implode("\n", $content_lines);
    }

    private function apply_hunks(string $original, array $diff_lines): ?string {
        $original_lines = explode("\n", $original);
        $result = $original_lines;
        $offset = 0;

        $hunks = $this->extract_hunks($diff_lines);

        foreach ($hunks as $hunk) {
            $start_line = $hunk['old_start'] - 1 + $offset;
            $old_count = $hunk['old_count'];

            // Remove old lines and insert new ones
            array_splice($result, $start_line, $old_count, $hunk['new_lines']);

            // Adjust offset for subsequent hunks
            $offset += count($hunk['new_lines']) - $old_count;
        }

        return implode("\n", $result);
    }

    private function extract_hunks(array $lines): array {
        $hunks = [];
        $current_hunk = null;

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
                if ($current_hunk) {
                    $hunks[] = $current_hunk;
                }
                $current_hunk = [
                    'old_start' => (int) $matches[1],
                    'old_count' => isset($matches[2]) ? (int) $matches[2] : 1,
                    'new_start' => (int) $matches[3],
                    'new_count' => isset($matches[4]) ? (int) $matches[4] : 1,
                    'new_lines' => [],
                ];
                continue;
            }

            if ($current_hunk === null) {
                continue;
            }

            if (isset($line[0])) {
                if ($line[0] === '+') {
                    $current_hunk['new_lines'][] = substr($line, 1);
                } elseif ($line[0] === ' ') {
                    $current_hunk['new_lines'][] = substr($line, 1);
                }
                // Lines starting with '-' are removed (not added to new_lines)
            }
        }

        if ($current_hunk) {
            $hunks[] = $current_hunk;
        }

        return $hunks;
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
