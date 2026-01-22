<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

class Changes_Admin {

    private $git_tracker;
    private $executor;

    public function __construct(Git_Tracker $git_tracker) {
        $this->git_tracker = $git_tracker;
        $this->executor = new Executor(new Tools(), $git_tracker);
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('load-tools_page_ai-changes', [$this, 'add_help_tabs']);
        add_action('wp_ajax_ai_assistant_get_changes', [$this, 'ajax_get_changes']);
        add_action('wp_ajax_ai_assistant_get_changes_by_plugin', [$this, 'ajax_get_changes_by_plugin']);
        add_action('wp_ajax_ai_assistant_generate_diff', [$this, 'ajax_generate_diff']);
        add_action('wp_ajax_ai_assistant_clear_changes', [$this, 'ajax_clear_changes']);
        add_action('wp_ajax_ai_assistant_apply_patch', [$this, 'ajax_apply_patch']);
        add_action('wp_ajax_ai_assistant_revert_file', [$this, 'ajax_revert_file']);
        add_action('wp_ajax_ai_assistant_reapply_file', [$this, 'ajax_reapply_file']);
        add_action('wp_ajax_ai_assistant_revert_files', [$this, 'ajax_revert_files']);
        add_action('wp_ajax_ai_assistant_lint_php', [$this, 'ajax_lint_php']);
        add_action('wp_ajax_ai_assistant_get_commit_log', [$this, 'ajax_get_commit_log']);
        add_action('wp_ajax_ai_assistant_get_commit_diff', [$this, 'ajax_get_commit_diff']);
        add_action('wp_ajax_ai_assistant_revert_to_commit', [$this, 'ajax_revert_to_commit']);
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

    private function format_time_ago(?int $timestamp): string {
        if (!$timestamp) {
            return '';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return __('just now', 'ai-assistant');
        }
        if ($diff < 3600) {
            $mins = (int) floor($diff / 60);
            return sprintf(_n('%d min ago', '%d mins ago', $mins, 'ai-assistant'), $mins);
        }
        if ($diff < 86400) {
            $hours = (int) floor($diff / 3600);
            return sprintf(_n('%d hour ago', '%d hours ago', $hours, 'ai-assistant'), $hours);
        }
        if ($diff < 604800) {
            $days = (int) floor($diff / 86400);
            return sprintf(_n('%d day ago', '%d days ago', $days, 'ai-assistant'), $days);
        }

        return date_i18n('M j', $timestamp);
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
                'confirmRevert' => __('Are you sure you want to revert this file to its original state?', 'ai-assistant'),
                'reverting' => __('...', 'ai-assistant'),
                'revertError' => __('Failed to revert file.', 'ai-assistant'),
                'confirmReapply' => __('Are you sure you want to reapply the changes to this file?', 'ai-assistant'),
                'reapplyError' => __('Failed to reapply changes.', 'ai-assistant'),
                'revert' => __('Revert', 'ai-assistant'),
                'reapply' => __('Reapply', 'ai-assistant'),
                'revertTitle' => __('Revert this change', 'ai-assistant'),
                'reapplyTitle' => __('Reapply this change', 'ai-assistant'),
                'confirmRevertDir' => __('Are you sure you want to revert %d file(s) in this directory?', 'ai-assistant'),
                'confirmRevertPlugin' => __('Are you sure you want to revert %d file(s) in this plugin?', 'ai-assistant'),
                'nothingToRevert' => __('No files to revert.', 'ai-assistant'),
                'syntaxError' => __('Syntax Error', 'ai-assistant'),
                'syntaxOk' => __('Syntax OK', 'ai-assistant'),
                'confirmRevertToCommit' => __('Are you sure you want to revert all files to this commit? This will restore files to how they were at that point.', 'ai-assistant'),
                'revertingToCommit' => __('Reverting...', 'ai-assistant'),
                'revertToCommitError' => __('Failed to revert to commit.', 'ai-assistant'),
                'loading' => __('Loading...', 'ai-assistant'),
                'loadMore' => __('Load more', 'ai-assistant'),
                'current' => __('(current)', 'ai-assistant'),
                'revertToHere' => __('Revert to here', 'ai-assistant'),
                'revertToCommitTitle' => __('Revert files to this commit', 'ai-assistant'),
                'justNow' => __('just now', 'ai-assistant'),
                'noCommits' => __('No commits yet', 'ai-assistant'),
                'viewConversation' => __('View conversation', 'ai-assistant'),
            ],
        ]);
    }

    public function add_help_tabs(): void {
        $screen = get_current_screen();

        $screen->add_help_tab([
            'id'      => 'ai-changes-overview',
            'title'   => __('Overview', 'ai-assistant'),
            'content' => '<p>' . __('The AI Changes page tracks all file modifications made by the AI Assistant. This allows you to review, export, revert, or reapply changes.', 'ai-assistant') . '</p>'
                       . '<p>' . __('Changes are organized by directory and show the type of modification (created, modified, or deleted).', 'ai-assistant') . '</p>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-changes-actions',
            'title'   => __('Actions', 'ai-assistant'),
            'content' => '<p>' . __('Available actions:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li><strong>' . __('Import Patch', 'ai-assistant') . '</strong> - ' . __('Upload a .patch, .diff, or .txt file to apply changes to your files.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Download .patch', 'ai-assistant') . '</strong> - ' . __('Select files and download a unified diff patch file.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Revert', 'ai-assistant') . '</strong> - ' . __('Restore a file to its original state before AI modification.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Reapply', 'ai-assistant') . '</strong> - ' . __('Re-apply previously reverted changes.', 'ai-assistant') . '</li>'
                       . '<li><strong>' . __('Clear History', 'ai-assistant') . '</strong> - ' . __('Remove all tracked changes from the list.', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->add_help_tab([
            'id'      => 'ai-changes-diff',
            'title'   => __('Diff Preview', 'ai-assistant'),
            'content' => '<p>' . __('Click the arrow (▶) next to any file to preview its diff inline.', 'ai-assistant') . '</p>'
                       . '<p>' . __('Select multiple files using the checkboxes to see a combined diff in the preview panel at the bottom.', 'ai-assistant') . '</p>'
                       . '<p>' . __('The diff shows:', 'ai-assistant') . '</p>'
                       . '<ul>'
                       . '<li><span style="color: #22863a;">+ Green lines</span> - ' . __('Added content', 'ai-assistant') . '</li>'
                       . '<li><span style="color: #cb2431;">- Red lines</span> - ' . __('Removed content', 'ai-assistant') . '</li>'
                       . '</ul>',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', 'ai-assistant') . '</strong></p>'
            . '<p><a href="' . esc_url(admin_url('tools.php?page=ai-conversations')) . '">' . __('AI Conversations', 'ai-assistant') . '</a></p>'
            . '<p><a href="' . esc_url(admin_url('options-general.php?page=ai-assistant-settings')) . '">' . __('Plugin Settings', 'ai-assistant') . '</a></p>'
        );
    }

    public function render_page(): void {
        $plugins = $this->git_tracker->get_changes_by_plugin();
        $has_changes = !empty($plugins);
        ?>
        <div class="wrap ai-changes-wrap">
            <h1><?php esc_html_e('AI Changes', 'ai-assistant'); ?></h1>

            <p class="description">
                <?php esc_html_e('Track and export changes made by the AI assistant. Changes are grouped by plugin/theme with commit history and conversation references.', 'ai-assistant'); ?>
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

            <div class="ai-changes-plugins">
                <?php foreach ($plugins as $plugin_path => $plugin): ?>
                <div class="ai-plugin-card" data-plugin="<?php echo esc_attr($plugin_path); ?>">
                    <div class="ai-plugin-header">
                        <label class="ai-plugin-header-label">
                            <input type="checkbox" class="ai-plugin-checkbox" data-plugin="<?php echo esc_attr($plugin_path); ?>">
                            <span class="ai-plugin-toggle">▶</span>
                            <span class="ai-plugin-name"><?php echo esc_html($plugin['name']); ?></span>
                            <span class="ai-plugin-path"><?php echo esc_html($plugin_path); ?>/</span>
                        </label>
                        <span class="ai-plugin-stats">
                            <?php echo esc_html($plugin['file_count']); ?> <?php echo $plugin['file_count'] === 1 ? 'file' : 'files'; ?>,
                            <?php echo esc_html($plugin['commit_count']); ?> <?php echo $plugin['commit_count'] === 1 ? 'commit' : 'commits'; ?>
                        </span>
                        <?php
                        $download_url = wp_nonce_url(
                            admin_url('admin.php?action=ai_assistant_download_plugin&path=' . urlencode($plugin_path)),
                            'ai_assistant_download_' . $plugin_path
                        );
                        ?>
                        <a href="<?php echo esc_url($download_url); ?>" class="button button-small" title="<?php esc_attr_e('Download as ZIP with git history', 'ai-assistant'); ?>">
                            <?php esc_html_e('Download ZIP', 'ai-assistant'); ?>
                        </a>
                        <button type="button" class="button button-small ai-revert-plugin" data-plugin="<?php echo esc_attr($plugin_path); ?>" title="<?php esc_attr_e('Revert all files in this plugin', 'ai-assistant'); ?>">
                            <?php esc_html_e('Revert All', 'ai-assistant'); ?>
                        </button>
                    </div>
                    <div class="ai-plugin-content" style="display: none;">
                        <?php if (!empty($plugin['commits'])): ?>
                        <div class="ai-plugin-commits">
                            <div class="ai-plugin-section-header"><?php esc_html_e('Recent Commits', 'ai-assistant'); ?></div>
                            <?php foreach ($plugin['commits'] as $index => $commit): ?>
                            <div class="ai-commit-entry" data-sha="<?php echo esc_attr($commit['sha']); ?>">
                                <div class="ai-commit-row<?php echo $index === 0 ? ' ai-commit-current' : ''; ?>">
                                    <div class="ai-commit-row-top">
                                        <button type="button" class="ai-commit-diff-toggle" data-sha="<?php echo esc_attr($commit['sha']); ?>" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>">▶</button>
                                        <span class="ai-commit-sha"><?php echo esc_html($commit['short_sha']); ?></span>
                                        <span class="ai-commit-message"><?php echo esc_html($commit['message']); ?></span>
                                        <?php if (!empty($commit['conversation_id'])): ?>
                                        <a href="<?php echo esc_url(admin_url('tools.php?page=ai-conversations&conversation_id=' . $commit['conversation_id'])); ?>"
                                           class="ai-commit-conversation"
                                           data-id="<?php echo esc_attr($commit['conversation_id']); ?>"
                                           title="<?php esc_attr_e('View conversation', 'ai-assistant'); ?>">
                                            Conv #<?php echo esc_html($commit['conversation_id']); ?>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ai-commit-row-bottom">
                                        <span class="ai-commit-date" title="<?php echo esc_attr($commit['date']); ?>"><?php echo esc_html($this->format_time_ago($commit['timestamp'])); ?></span>
                                        <?php if ($index === 0): ?>
                                        <span class="ai-commit-label"><?php esc_html_e('(current)', 'ai-assistant'); ?></span>
                                        <?php else: ?>
                                        <button type="button" class="button button-small ai-revert-to-commit" data-sha="<?php echo esc_attr($commit['sha']); ?>" title="<?php esc_attr_e('Revert files to this commit', 'ai-assistant'); ?>">
                                            <?php esc_html_e('Revert to here', 'ai-assistant'); ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="ai-commit-diff-preview" data-sha="<?php echo esc_attr($commit['sha']); ?>" style="display: none;">
                                    <pre><code></code></pre>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <div class="ai-plugin-files">
                            <div class="ai-plugin-section-header"><?php esc_html_e('Changed Files', 'ai-assistant'); ?></div>
                            <?php foreach ($plugin['files'] as $file): ?>
                            <div class="ai-changes-file">
                                <div class="ai-changes-file-row">
                                    <button type="button" class="ai-file-preview-toggle" data-path="<?php echo esc_attr($file['path']); ?>" title="<?php esc_attr_e('Preview diff', 'ai-assistant'); ?>">▶</button>
                                    <label>
                                        <input type="checkbox" class="ai-file-checkbox"
                                               data-path="<?php echo esc_attr($file['path']); ?>"
                                               data-plugin="<?php echo esc_attr($plugin_path); ?>">
                                        <span class="ai-changes-file-path"><?php echo esc_html($file['relative_path'] ?: basename($file['path'])); ?></span>
                                        <span class="ai-changes-type ai-changes-type-<?php echo esc_attr($file['change_type']); ?>">
                                            <?php echo esc_html(ucfirst($file['change_type'])); ?>
                                        </span>
                                        <?php if (!empty($file['is_reverted'])): ?>
                                        <span class="ai-changes-type ai-changes-type-reverted">
                                            <?php esc_html_e('Reverted', 'ai-assistant'); ?>
                                        </span>
                                        <?php endif; ?>
                                        <span class="ai-lint-status" data-path="<?php echo esc_attr($file['path']); ?>"></span>
                                    </label>
                                    <?php if (!empty($file['is_reverted'])): ?>
                                    <button type="button" class="button button-small ai-reapply-file" data-path="<?php echo esc_attr($file['path']); ?>" title="<?php esc_attr_e('Reapply this change', 'ai-assistant'); ?>">
                                        <?php esc_html_e('Reapply', 'ai-assistant'); ?>
                                    </button>
                                    <?php else: ?>
                                    <button type="button" class="button button-small ai-revert-file" data-path="<?php echo esc_attr($file['path']); ?>" title="<?php esc_attr_e('Revert this change', 'ai-assistant'); ?>">
                                        <?php esc_html_e('Revert', 'ai-assistant'); ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div class="ai-file-inline-preview" data-path="<?php echo esc_attr($file['path']); ?>" style="display: none;">
                                    <pre><code></code></pre>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
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
            <?php endif; ?>

            <div class="ai-import-section">
                <h2><?php esc_html_e('Import Patch', 'ai-assistant'); ?></h2>
                <p class="description">
                    <?php esc_html_e('Apply a patch file to modify files in your wp-content directory. Supports unified diff format (.patch, .diff, or .txt files).', 'ai-assistant'); ?>
                </p>
                <input type="file" id="ai-patch-file" accept=".patch,.diff,.txt" style="display:none;">
                <button type="button" class="button" id="ai-import-patch">
                    <?php esc_html_e('Choose Patch File...', 'ai-assistant'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    public function ajax_get_changes(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $directories = $this->git_tracker->get_changes_by_directory();
        wp_send_json_success(['directories' => $directories]);
    }

    public function ajax_get_changes_by_plugin(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $plugins = $this->git_tracker->get_changes_by_plugin();
        wp_send_json_success(['plugins' => $plugins]);
    }

    public function ajax_generate_diff(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_paths = isset($_POST['file_paths']) ? array_map('sanitize_text_field', (array) $_POST['file_paths']) : [];

        if (empty($file_paths)) {
            wp_send_json_error(['message' => 'No files selected']);
        }

        $diff = $this->git_tracker->generate_diff($file_paths);
        wp_send_json_success(['diff' => $diff]);
    }

    public function ajax_clear_changes(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_paths = isset($_POST['file_paths']) ? array_map('sanitize_text_field', (array) $_POST['file_paths']) : [];

        if (empty($file_paths)) {
            $deleted = $this->git_tracker->clear_all();
        } else {
            $deleted = $this->git_tracker->clear_files($file_paths);
        }

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

    public function ajax_revert_file(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if ($this->git_tracker->is_reverted($file_path)) {
            wp_send_json_error(['message' => 'File already reverted']);
        }

        try {
            if ($this->git_tracker->revert_file($file_path)) {
                wp_send_json_success([
                    'message' => __('File reverted successfully.', 'ai-assistant'),
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to revert file']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_reapply_file(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if (!$this->git_tracker->is_reverted($file_path)) {
            wp_send_json_error(['message' => 'File is not reverted']);
        }

        try {
            if ($this->git_tracker->reapply_file($file_path)) {
                wp_send_json_success([
                    'message' => __('File changes reapplied successfully.', 'ai-assistant'),
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to reapply changes']);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_revert_files(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_paths = isset($_POST['file_paths']) ? array_map('sanitize_text_field', (array) $_POST['file_paths']) : [];

        if (empty($file_paths)) {
            wp_send_json_error(['message' => 'No files specified']);
        }

        $reverted = [];
        $errors = [];

        foreach ($file_paths as $file_path) {
            if ($this->git_tracker->is_reverted($file_path)) {
                continue;
            }

            try {
                if ($this->git_tracker->revert_file($file_path)) {
                    $reverted[] = $file_path;
                } else {
                    $errors[] = sprintf(__('Failed to revert: %s', 'ai-assistant'), $file_path);
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        wp_send_json_success([
            'reverted' => $reverted,
            'errors' => $errors,
            'message' => sprintf(__('%d file(s) reverted.', 'ai-assistant'), count($reverted)),
        ]);
    }

    public function ajax_lint_php(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $file_path = isset($_POST['file_path']) ? sanitize_text_field($_POST['file_path']) : '';

        if (empty($file_path)) {
            wp_send_json_error(['message' => 'No file specified']);
        }

        if (!preg_match('/\.php$/i', $file_path)) {
            wp_send_json_success(['valid' => true, 'is_php' => false]);
        }

        $full_path = WP_CONTENT_DIR . '/' . $file_path;

        if (!file_exists($full_path)) {
            wp_send_json_success(['valid' => true, 'is_php' => true, 'message' => 'File does not exist']);
        }

        $content = file_get_contents($full_path);
        $result = $this->lint_php_content($content);

        wp_send_json_success([
            'valid' => $result['valid'],
            'is_php' => true,
            'error' => $result['error'] ?? null,
            'line' => $result['line'] ?? null,
        ]);
    }

    public function lint_php_content(string $content): array {
        $previous_error_reporting = error_reporting(0);

        set_error_handler(function($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            token_get_all($content, TOKEN_PARSE);
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return ['valid' => true];
        } catch (\ParseError $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        } catch (\ErrorException $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        } catch (\Throwable $e) {
            restore_error_handler();
            error_reporting($previous_error_reporting);
            return [
                'valid' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ];
        }
    }

    public function ajax_get_commit_log(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 20;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

        $result = $this->git_tracker->get_commit_log($limit, $offset);
        wp_send_json_success($result);
    }

    public function ajax_get_commit_diff(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $sha = isset($_POST['sha']) ? sanitize_text_field($_POST['sha']) : '';

        if (empty($sha)) {
            wp_send_json_error(['message' => 'No commit SHA specified']);
        }

        $diff = $this->git_tracker->get_commit_diff($sha);
        wp_send_json_success(['diff' => $diff]);
    }

    public function ajax_revert_to_commit(): void {
        check_ajax_referer('ai_assistant_changes', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $sha = isset($_POST['sha']) ? sanitize_text_field($_POST['sha']) : '';

        if (empty($sha)) {
            wp_send_json_error(['message' => 'No commit SHA specified']);
        }

        $result = $this->git_tracker->revert_to_commit($sha);

        if ($result['success']) {
            wp_send_json_success([
                'reverted' => $result['reverted'],
                'message' => sprintf(__('%d file(s) reverted to commit state.', 'ai-assistant'), count($result['reverted'])),
            ]);
        } else {
            wp_send_json_error([
                'message' => implode(', ', $result['errors']),
            ]);
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

        $file_paths = isset($_GET['file_paths']) ? array_map('sanitize_text_field', explode(',', $_GET['file_paths'])) : [];

        if (empty($file_paths)) {
            wp_die(__('No files selected.', 'ai-assistant'));
        }

        $diff = $this->git_tracker->generate_diff($file_paths);
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
