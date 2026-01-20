<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Git-compatible change tracker.
 *
 * Replaces CPT-based tracking with actual git structure.
 * Original files are stored as blobs in the index, working directory has modifications.
 * Download the wp-content folder, run `git diff` to see AI changes.
 */
class Git_Tracker {

    private string $git_dir;
    private string $work_tree;

    public function __construct() {
        $this->work_tree = WP_CONTENT_DIR;
        $this->git_dir = WP_CONTENT_DIR . '/.git';
    }

    /**
     * Track a file change by storing the original in git format.
     */
    public function track_change(string $path, string $change_type, ?string $original_content = null, string $reason = ''): bool {
        // Convert absolute path to relative
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return false;
        }

        // Skip if already tracking this file (we only need the first original)
        $entries = $this->read_index();
        $already_tracked = isset($entries[$relative_path]) || in_array($relative_path, $this->get_created_files());

        $this->ensure_git_structure();

        if ($change_type === 'created') {
            if (!$already_tracked) {
                $this->add_created_file($relative_path);
            }
            // Update ai-changes branch with current file content
            $this->update_ai_changes_branch($reason);
            return true;
        }

        if ($original_content === null) {
            return false;
        }

        if (!$already_tracked) {
            // Store original content as blob and add to index (main branch)
            $blob_sha = $this->write_blob($original_content);
            $this->update_index($relative_path, $blob_sha, strlen($original_content));
        }

        // Update ai-changes branch with current file content
        $this->update_ai_changes_branch($reason);

        return true;
    }

    /**
     * Get all tracked changes grouped by directory.
     */
    public function get_changes_by_directory(): array {
        if (!$this->is_active()) {
            return [];
        }

        $entries = $this->read_index();
        $created = $this->get_created_files();
        $directories = [];

        // Process modified/deleted files from index
        foreach ($entries as $path => $info) {
            $full_path = $this->work_tree . '/' . $path;
            $exists = file_exists($full_path);

            $change_type = $exists ? 'modified' : 'deleted';
            $this->add_to_directory_list($directories, $path, $change_type, $info);
        }

        // Process created files
        foreach ($created as $path) {
            $this->add_to_directory_list($directories, $path, 'created', ['sha' => null, 'size' => 0]);
        }

        return $directories;
    }

    private function add_to_directory_list(array &$directories, string $path, string $change_type, array $info): void {
        // Skip invalid/corrupted paths
        if (empty($path) || strlen($path) < 3 || strpos($path, "\0") !== false) {
            return;
        }

        $parts = explode('/', $path);
        $dir = count($parts) >= 2 ? $parts[0] . '/' . $parts[1] : $parts[0];

        if (!isset($directories[$dir])) {
            $directories[$dir] = [
                'path' => $dir,
                'files' => [],
                'count' => 0,
            ];
        }

        $directories[$dir]['files'][] = [
            'id' => md5($path), // Use hash as ID
            'path' => $path,
            'relative_path' => substr($path, strlen($dir) + 1),
            'change_type' => $change_type,
            'sha' => $info['sha'] ?? null,
            'is_reverted' => $this->is_reverted($path),
        ];
        $directories[$dir]['count']++;
    }

    /**
     * Generate unified diff for specified files.
     */
    public function generate_diff(array $file_paths = []): string {
        if (!$this->is_active()) {
            return '';
        }

        $entries = $this->read_index();
        $created = $this->get_created_files();
        $output = [];

        // If no specific files, diff everything
        if (empty($file_paths)) {
            $file_paths = array_merge(array_keys($entries), $created);
        }

        foreach ($file_paths as $path) {
            $full_path = $this->work_tree . '/' . $path;

            if (in_array($path, $created)) {
                // New file
                if (file_exists($full_path)) {
                    $current = file_get_contents($full_path);
                    $output[] = $this->format_diff($path, '', $current, 'created');
                }
            } elseif (isset($entries[$path])) {
                // Modified or deleted
                $sha = $entries[$path]['sha'];
                $original = $this->read_blob($sha);
                if ($original === null) {
                    continue;
                }
                $current = file_exists($full_path) ? file_get_contents($full_path) : '';
                $type = file_exists($full_path) ? 'modified' : 'deleted';

                if ($original !== $current) {
                    $output[] = $this->format_diff($path, $original, $current, $type);
                }
            }
        }

        return implode("\n", $output);
    }

    /**
     * Get original content of a file.
     */
    public function get_original_content(string $path): ?string {
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return null;
        }

        $entries = $this->read_index();
        if (!isset($entries[$relative_path])) {
            return null;
        }

        return $this->read_blob($entries[$relative_path]['sha']);
    }

    /**
     * Revert a file to its original state.
     * The modified content is preserved in the ai-changes branch for reapply.
     */
    public function revert_file(string $path): bool {
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return false;
        }

        $full_path = $this->work_tree . '/' . $relative_path;

        // Ensure ai-changes branch has current state before reverting
        $this->update_ai_changes_branch();

        // Check if it's a created file
        $created = $this->get_created_files();
        if (in_array($relative_path, $created)) {
            // Delete the created file (ai-changes branch still has it)
            if (file_exists($full_path)) {
                unlink($full_path);
            }
            return true;
        }

        // Get original from index (main branch)
        $entries = $this->read_index();
        if (!isset($entries[$relative_path])) {
            return false;
        }

        $original = $this->read_blob($entries[$relative_path]['sha']);
        if ($original === null) {
            return false;
        }

        // Restore original content
        $dir = dirname($full_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full_path, $original);

        return true;
    }

    /**
     * Reapply AI changes to a file from the ai-changes branch.
     */
    public function reapply_file(string $path): bool {
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return false;
        }

        // Get content from ai-changes branch
        $modified = $this->get_file_from_ai_changes($relative_path);
        if ($modified === null) {
            return false;
        }

        $full_path = $this->work_tree . '/' . $relative_path;

        // Restore modified content
        $dir = dirname($full_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($full_path, $modified);

        return true;
    }

    /**
     * Check if a file is currently reverted.
     * A file is reverted if working dir matches original but ai-changes has different content.
     */
    public function is_reverted(string $path): bool {
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return false;
        }

        $full_path = $this->work_tree . '/' . $relative_path;
        $current = file_exists($full_path) ? file_get_contents($full_path) : null;

        // Check if it's a created file
        $created = $this->get_created_files();
        if (in_array($relative_path, $created)) {
            // Created file is reverted if it doesn't exist but ai-changes has it
            $ai_content = $this->get_file_from_ai_changes($relative_path);
            return $current === null && $ai_content !== null;
        }

        // For modified files: reverted if current matches original
        $entries = $this->read_index();
        if (!isset($entries[$relative_path])) {
            return false;
        }

        $original = $this->read_blob($entries[$relative_path]['sha']);
        $ai_content = $this->get_file_from_ai_changes($relative_path);

        // Reverted = matches original AND ai-changes has something different
        return $current === $original && $ai_content !== null && $ai_content !== $original;
    }

    /**
     * Check if a file is tracked.
     */
    public function is_tracked(string $path): bool {
        $relative_path = $this->to_relative_path($path);
        if (!$relative_path) {
            return false;
        }

        $entries = $this->read_index();
        $created = $this->get_created_files();

        return isset($entries[$relative_path]) || in_array($relative_path, $created);
    }

    /**
     * Clear all tracking.
     */
    public function clear_all(): int {
        if (!is_dir($this->git_dir)) {
            return 0;
        }

        $entries = $this->read_index();
        $created = $this->get_created_files();
        $count = count($entries) + count($created);

        $this->recursive_delete($this->git_dir);

        return $count;
    }

    /**
     * Clear specific files from tracking.
     */
    public function clear_files(array $paths): int {
        $count = 0;
        foreach ($paths as $path) {
            $relative_path = $this->to_relative_path($path);
            if ($relative_path && $this->remove_from_index($relative_path)) {
                $count++;
            }
            if ($relative_path && $this->remove_created_file($relative_path)) {
                $count++;
            }
        }

        // Clean up if nothing left
        $entries = $this->read_index();
        $created = $this->get_created_files();
        if (empty($entries) && empty($created)) {
            $this->recursive_delete($this->git_dir);
        }

        return $count;
    }

    /**
     * Check if tracking is active.
     */
    public function is_active(): bool {
        return is_dir($this->git_dir);
    }

    /**
     * Build a standalone .git directory for a subset of files (e.g., a single plugin).
     * The returned .git can be included in a ZIP so users can run `git diff main` after extraction.
     *
     * Structure mirrors the main tracker:
     * - main branch: original files (before AI modifications)
     * - ai-changes branch: current files (after AI modifications)
     * - HEAD points to ai-changes
     *
     * @param string $path_prefix Files to include (e.g., "plugins/my-plugin/")
     * @param string $target_dir  Directory to create .git in (will create $target_dir/.git)
     * @return bool True if .git was created with tracked changes
     */
    public function build_standalone_git(string $path_prefix, string $target_dir): bool {
        if (!$this->is_active()) {
            return false;
        }

        $path_prefix = rtrim($path_prefix, '/') . '/';
        $entries = $this->read_index();
        $created = $this->get_created_files();

        $plugin_files = [];
        foreach ($entries as $path => $info) {
            if (strpos($path, $path_prefix) === 0) {
                $relative = substr($path, strlen($path_prefix));
                $plugin_files[$relative] = [
                    'sha' => $info['sha'],
                    'type' => 'modified',
                ];
            }
        }

        foreach ($created as $path) {
            if (strpos($path, $path_prefix) === 0) {
                $relative = substr($path, strlen($path_prefix));
                $plugin_files[$relative] = [
                    'sha' => null,
                    'type' => 'created',
                ];
            }
        }

        if (empty($plugin_files)) {
            return false;
        }

        $git_dir = rtrim($target_dir, '/') . '/.git';
        $plugin_path = WP_PLUGIN_DIR . '/' . rtrim(substr($path_prefix, strlen('plugins/')), '/');

        mkdir($git_dir, 0755, true);
        mkdir($git_dir . '/objects', 0755);
        mkdir($git_dir . '/refs/heads', 0755, true);

        file_put_contents($git_dir . '/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = false\n\tbare = false\n");

        // Build main branch with originals
        $original_tree_files = [];
        foreach ($plugin_files as $relative_path => $info) {
            if ($info['type'] === 'created') {
                // Created files don't exist in main branch
                continue;
            }

            $original_content = $this->read_blob($info['sha']);
            if ($original_content === null) {
                continue;
            }

            $blob_sha = $this->write_blob_to_dir($git_dir, $original_content);
            $original_tree_files[$relative_path] = $blob_sha;
        }

        $main_tree_sha = $this->build_tree_to_dir($git_dir, $original_tree_files);
        $main_commit_sha = $this->write_commit_to_dir($git_dir, $main_tree_sha, null, "Original state before AI modifications");
        file_put_contents($git_dir . '/refs/heads/main', $main_commit_sha . "\n");

        // Recreate commit history from ai-changes branch for this plugin
        $commits = $this->get_commits_for_prefix($path_prefix);

        if (empty($commits)) {
            // Fallback: single commit with current content
            $current_tree_files = [];
            foreach ($plugin_files as $relative_path => $info) {
                $full_path = $plugin_path . '/' . $relative_path;
                if (file_exists($full_path)) {
                    $content = file_get_contents($full_path);
                    $current_tree_files[$relative_path] = $this->write_blob_to_dir($git_dir, $content);
                }
            }
            $ai_tree_sha = $this->build_tree_to_dir($git_dir, $current_tree_files);
            $ai_commit_sha = $this->write_commit_to_dir($git_dir, $ai_tree_sha, $main_commit_sha, "AI modifications");
        } else {
            // Replay each commit
            $parent_sha = $main_commit_sha;
            $tree_state = $original_tree_files; // Start from original state
            $ai_commit_sha = $main_commit_sha;

            foreach ($commits as $commit) {
                // Update tree state with files from this commit
                foreach ($commit['files'] as $relative_path => $content) {
                    if ($content === null) {
                        // File deleted
                        unset($tree_state[$relative_path]);
                    } else {
                        $tree_state[$relative_path] = $this->write_blob_to_dir($git_dir, $content);
                    }
                }

                $tree_sha = $this->build_tree_to_dir($git_dir, $tree_state);
                $ai_commit_sha = $this->write_commit_to_dir($git_dir, $tree_sha, $parent_sha, $commit['message']);
                $parent_sha = $ai_commit_sha;
            }
        }

        file_put_contents($git_dir . '/refs/heads/ai-changes', $ai_commit_sha . "\n");

        // HEAD points to ai-changes, index matches final state
        file_put_contents($git_dir . '/HEAD', "ref: refs/heads/ai-changes\n");

        // Build index from current working directory
        $index_entries = [];
        foreach ($plugin_files as $relative_path => $info) {
            $full_path = $plugin_path . '/' . $relative_path;
            if (file_exists($full_path)) {
                $content = file_get_contents($full_path);
                $index_entries[$relative_path] = [
                    'sha' => $this->write_blob_to_dir($git_dir, $content),
                    'size' => strlen($content),
                    'mode' => 0x81A4,
                ];
            }
        }
        $this->write_index_to_dir($git_dir, $index_entries);

        return true;
    }

    private function write_blob_to_dir(string $git_dir, string $content): string {
        $header = "blob " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    private function write_index_to_dir(string $git_dir, array $entries): void {
        ksort($entries);

        $body = '';
        $now = time();

        foreach ($entries as $path => $info) {
            $body .= pack('NN', $now, 0);
            $body .= pack('NN', $now, 0);
            $body .= pack('NN', 0, 0);
            $body .= pack('N', 0x81A4);
            $body .= pack('NN', 0, 0);
            $body .= pack('N', $info['size']);
            $body .= hex2bin($info['sha']);
            $body .= pack('n', min(strlen($path), 0x0FFF));
            $body .= $path . "\0";
            $entry_len = 62 + strlen($path) + 1;
            $padding = (8 - ($entry_len % 8)) % 8;
            $body .= str_repeat("\0", $padding);
        }

        $header = pack('a4NN', 'DIRC', 2, count($entries));
        $content = $header . $body;

        file_put_contents($git_dir . '/index', $content . sha1($content, true));
    }

    private function build_tree_to_dir(string $git_dir, array $files): string {
        if (empty($files)) {
            return $this->write_tree_to_dir($git_dir, []);
        }

        $tree = [];
        foreach ($files as $path => $sha) {
            $parts = explode('/', $path);
            $this->nest_in_tree($tree, $parts, $sha);
        }

        return $this->write_tree_recursive_to_dir($git_dir, $tree);
    }

    private function write_tree_recursive_to_dir(string $git_dir, array $tree): string {
        $entries = [];
        foreach ($tree as $name => $item) {
            if ($item['type'] === 'blob') {
                $entries[] = ['mode' => '100644', 'name' => $name, 'sha' => $item['sha']];
            } else {
                $subtree_sha = $this->write_tree_recursive_to_dir($git_dir, $item['children']);
                $entries[] = ['mode' => '40000', 'name' => $name, 'sha' => $subtree_sha];
            }
        }
        return $this->write_tree_to_dir($git_dir, $entries);
    }

    private function write_tree_to_dir(string $git_dir, array $entries): string {
        usort($entries, fn($a, $b) => strcmp($a['name'], $b['name']));

        $content = '';
        foreach ($entries as $entry) {
            $content .= $entry['mode'] . ' ' . $entry['name'] . "\0" . hex2bin($entry['sha']);
        }

        $header = "tree " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    private function write_commit_to_dir(string $git_dir, string $tree_sha, ?string $parent, string $message): string {
        $ts = time();
        $author = "AI Assistant <ai@local> {$ts} +0000";

        $content = "tree {$tree_sha}\n";
        if ($parent) {
            $content .= "parent {$parent}\n";
        }
        $content .= "author {$author}\ncommitter {$author}\n\n{$message}\n";

        $header = "commit " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    /**
     * Check if there are any tracked changes.
     */
    public function has_changes(): bool {
        if (!$this->is_active()) {
            return false;
        }
        $entries = $this->read_index();
        $created = $this->get_created_files();
        return !empty($entries) || !empty($created);
    }

    /**
     * Get paginated commit log from ai-changes branch.
     */
    public function get_commit_log(int $limit = 20, int $offset = 0): array {
        if (!$this->is_active()) {
            return ['commits' => [], 'has_more' => false];
        }

        $ref_path = $this->git_dir . '/refs/heads/ai-changes';
        if (!file_exists($ref_path)) {
            return ['commits' => [], 'has_more' => false];
        }

        $commits = [];
        $sha = trim(file_get_contents($ref_path));
        $skipped = 0;

        while ($sha && count($commits) < $limit + 1) {
            $commit_data = $this->read_object($sha);
            if ($commit_data === null || $commit_data['type'] !== 'commit') {
                break;
            }

            $content = $commit_data['content'];

            $tree = null;
            $parent = null;
            $timestamp = null;
            $message = '';

            $lines = explode("\n", $content);
            $in_message = false;

            foreach ($lines as $line) {
                if ($in_message) {
                    $message .= ($message ? "\n" : '') . $line;
                } elseif ($line === '') {
                    $in_message = true;
                } elseif (strpos($line, 'tree ') === 0) {
                    $tree = substr($line, 5);
                } elseif (strpos($line, 'parent ') === 0) {
                    $parent = substr($line, 7);
                } elseif (strpos($line, 'author ') === 0) {
                    if (preg_match('/(\d+)\s+[+-]\d{4}$/', $line, $m)) {
                        $timestamp = (int) $m[1];
                    }
                }
            }

            if ($skipped < $offset) {
                $skipped++;
                $sha = $parent;
                continue;
            }

            $commits[] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'tree' => $tree,
                'parent' => $parent,
                'message' => trim($message),
                'timestamp' => $timestamp,
                'date' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
            ];

            $sha = $parent;
        }

        $has_more = count($commits) > $limit;
        if ($has_more) {
            array_pop($commits);
        }

        return ['commits' => $commits, 'has_more' => $has_more];
    }

    /**
     * Get the diff for a specific commit compared to its parent.
     */
    public function get_commit_diff(string $sha): string {
        if (!$this->is_active()) {
            return '';
        }

        $commit_data = $this->read_object($sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return '';
        }

        $tree_sha = null;
        $parent_sha = null;

        if (preg_match('/^tree ([a-f0-9]{40})/m', $commit_data['content'], $m)) {
            $tree_sha = $m[1];
        }
        if (preg_match('/^parent ([a-f0-9]{40})/m', $commit_data['content'], $m)) {
            $parent_sha = $m[1];
        }

        if (!$tree_sha) {
            return '';
        }

        $current_files = $this->get_tree_files($tree_sha, '');

        $parent_files = [];
        if ($parent_sha) {
            $parent_data = $this->read_object($parent_sha);
            if ($parent_data && preg_match('/^tree ([a-f0-9]{40})/m', $parent_data['content'], $m)) {
                $parent_files = $this->get_tree_files($m[1], '');
            }
        }

        $all_paths = array_unique(array_merge(array_keys($current_files), array_keys($parent_files)));
        $diffs = [];

        foreach ($all_paths as $path) {
            $old_sha = $parent_files[$path] ?? null;
            $new_sha = $current_files[$path] ?? null;

            if ($old_sha === $new_sha) {
                continue;
            }

            $old_content = $old_sha ? $this->read_blob($old_sha) : null;
            $new_content = $new_sha ? $this->read_blob($new_sha) : null;

            if ($old_content === null && $new_content !== null) {
                $diffs[] = $this->format_diff($path, '', $new_content, 'created');
            } elseif ($old_content !== null && $new_content === null) {
                $diffs[] = $this->format_diff($path, $old_content, '', 'deleted');
            } else {
                $diffs[] = $this->format_diff($path, $old_content ?? '', $new_content ?? '', 'modified');
            }
        }

        return implode("\n", $diffs);
    }

    /**
     * Revert all files to the state at a specific commit.
     */
    public function revert_to_commit(string $target_sha): array {
        if (!$this->is_active()) {
            return ['success' => false, 'errors' => ['Tracking not active']];
        }

        $commit_data = $this->read_object($target_sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return ['success' => false, 'errors' => ['Invalid commit SHA']];
        }

        if (!preg_match('/^tree ([a-f0-9]{40})/m', $commit_data['content'], $matches)) {
            return ['success' => false, 'errors' => ['Could not parse commit tree']];
        }
        $target_tree = $matches[1];

        $target_files = $this->get_tree_files($target_tree, '');

        $entries = $this->read_index();
        $created = $this->get_created_files();
        $all_tracked = array_merge(array_keys($entries), $created);

        $reverted = [];
        $errors = [];

        foreach ($all_tracked as $path) {
            $full_path = $this->work_tree . '/' . $path;

            if (isset($target_files[$path])) {
                $content = $this->read_blob($target_files[$path]);
                if ($content !== null) {
                    $dir = dirname($full_path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($full_path, $content);
                    $reverted[] = $path;
                } else {
                    $errors[] = "Could not read content for: $path";
                }
            } else {
                if (in_array($path, $created)) {
                    if (file_exists($full_path)) {
                        unlink($full_path);
                        $reverted[] = $path;
                    }
                } elseif (isset($entries[$path])) {
                    $original = $this->read_blob($entries[$path]['sha']);
                    if ($original !== null) {
                        file_put_contents($full_path, $original);
                        $reverted[] = $path;
                    }
                }
            }
        }

        return [
            'success' => empty($errors),
            'reverted' => $reverted,
            'errors' => $errors,
        ];
    }

    /**
     * Recursively get all files from a tree object (path => blob SHA).
     */
    private function get_tree_files(string $tree_sha, string $prefix): array {
        $tree_data = $this->read_object($tree_sha);
        if ($tree_data === null || $tree_data['type'] !== 'tree') {
            return [];
        }

        $files = [];
        $entries = $this->parse_tree($tree_data['content']);

        foreach ($entries as $entry) {
            $path = $prefix ? $prefix . '/' . $entry['name'] : $entry['name'];
            if ($entry['mode'] === '40000') {
                $files = array_merge($files, $this->get_tree_files($entry['sha'], $path));
            } else {
                $files[$path] = $entry['sha'];
            }
        }

        return $files;
    }

    // -------------------------------------------------------------------------
    // Private: Git structure management
    // -------------------------------------------------------------------------

    private function ensure_git_structure(): void {
        if (is_dir($this->git_dir)) {
            return;
        }

        mkdir($this->git_dir, 0755, true);
        mkdir($this->git_dir . '/objects', 0755, true);
        mkdir($this->git_dir . '/refs/heads', 0755, true);

        file_put_contents($this->git_dir . '/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->git_dir . '/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = false\n\tbare = false\n");

        $this->write_index([]);
        $this->write_created_files([]);
        // Don't create an empty initial commit - the first update_commit() will create it with actual content
    }

    private function to_relative_path(string $path): ?string {
        // Already relative
        if (strpos($path, '/') !== 0 && strpos($path, WP_CONTENT_DIR) !== 0) {
            return $path;
        }

        // Convert absolute to relative
        if (strpos($path, WP_CONTENT_DIR) === 0) {
            return ltrim(substr($path, strlen(WP_CONTENT_DIR)), '/');
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private: Blob operations
    // -------------------------------------------------------------------------

    private function write_blob(string $content): string {
        $header = "blob " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $this->git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    private function read_blob(string $sha): ?string {
        $path = $this->git_dir . '/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            return null;
        }

        $compressed = file_get_contents($path);
        $store = gzuncompress($compressed);

        // Parse "blob <size>\0<content>"
        $null_pos = strpos($store, "\0");
        if ($null_pos === false) {
            return null;
        }

        return substr($store, $null_pos + 1);
    }

    // -------------------------------------------------------------------------
    // Private: Index operations
    // -------------------------------------------------------------------------

    private function read_index(): array {
        $index_path = $this->git_dir . '/index';
        if (!file_exists($index_path)) {
            return [];
        }

        $data = file_get_contents($index_path);
        if (strlen($data) < 12) {
            return [];
        }

        $header = unpack('a4sig/Nversion/Nentries', $data);
        if ($header['sig'] !== 'DIRC') {
            return [];
        }

        $entries = [];
        $offset = 12;

        for ($i = 0; $i < $header['entries']; $i++) {
            $entry_start = $offset;

            // Fixed portion: 24 (ctime/mtime/dev/ino) + 16 (mode/uid/gid/size) + 20 (sha) + 2 (flags) = 62 bytes
            if ($offset + 62 > strlen($data)) {
                break;
            }

            // Skip ctime (8), mtime (8), dev (4), ino (4) = 24 bytes
            $offset += 24;

            $meta = unpack('Nmode/Nuid/Ngid/Nsize', substr($data, $offset, 16));
            $offset += 16;

            $sha = bin2hex(substr($data, $offset, 20));
            $offset += 20;

            $flags = unpack('n', substr($data, $offset, 2))[1];
            $name_len = $flags & 0x0FFF;
            $offset += 2;

            // Read name - find NUL terminator for safety
            $name = substr($data, $offset, $name_len);
            $nul_pos = strpos($name, "\0");
            if ($nul_pos !== false) {
                $name = substr($name, 0, $nul_pos);
            }

            // Calculate padding: entry padded to multiple of 8 bytes
            // Entry = 62 bytes (fixed) + name length + 1 (NUL) + padding
            $entry_len = 62 + strlen($name) + 1;
            $padded_len = (int)(ceil($entry_len / 8) * 8);
            $offset = $entry_start + $padded_len;

            if (!empty($name)) {
                $entries[$name] = [
                    'sha' => $sha,
                    'size' => $meta['size'],
                    'mode' => $meta['mode'],
                ];
            }
        }

        return $entries;
    }

    private function write_index(array $entries): void {
        ksort($entries);

        $body = '';
        $now = time();

        foreach ($entries as $path => $info) {
            // ctime, mtime (16 bytes)
            $body .= pack('NN', $now, 0);
            $body .= pack('NN', $now, 0);
            // dev, ino (8 bytes)
            $body .= pack('NN', 0, 0);
            // mode (4 bytes) - 100644
            $body .= pack('N', 0x81A4);
            // uid, gid (8 bytes)
            $body .= pack('NN', 0, 0);
            // size (4 bytes)
            $body .= pack('N', $info['size']);
            // SHA (20 bytes)
            $body .= hex2bin($info['sha']);
            // flags (2 bytes) - name length capped at 0xFFF
            $body .= pack('n', min(strlen($path), 0x0FFF));
            // name + NUL terminator
            $body .= $path . "\0";
            // Padding to 8-byte boundary (entry = 62 + name + 1 NUL)
            $entry_len = 62 + strlen($path) + 1;
            $padding = (8 - ($entry_len % 8)) % 8;
            $body .= str_repeat("\0", $padding);
        }

        $header = pack('a4NN', 'DIRC', 2, count($entries));
        $content = $header . $body;

        file_put_contents($this->git_dir . '/index', $content . sha1($content, true));
    }

    private function update_index(string $path, string $sha, int $size): void {
        $entries = $this->read_index();
        $entries[$path] = ['sha' => $sha, 'size' => $size, 'mode' => 0x81A4];
        $this->write_index($entries);
        $this->update_commit();
    }

    private function remove_from_index(string $path): bool {
        $entries = $this->read_index();
        if (!isset($entries[$path])) {
            return false;
        }
        unset($entries[$path]);
        $this->write_index($entries);
        $this->update_commit();
        return true;
    }

    // -------------------------------------------------------------------------
    // Private: Created files tracking (stored separately, not in index)
    // -------------------------------------------------------------------------

    private function get_created_files(): array {
        $path = $this->git_dir . '/ai-created';
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        return $content ? array_filter(explode("\n", $content)) : [];
    }

    private function write_created_files(array $files): void {
        file_put_contents($this->git_dir . '/ai-created', implode("\n", $files));
    }

    private function add_created_file(string $path): void {
        $files = $this->get_created_files();
        if (!in_array($path, $files)) {
            $files[] = $path;
            $this->write_created_files($files);
        }
    }

    private function remove_created_file(string $path): bool {
        $files = $this->get_created_files();
        $key = array_search($path, $files);
        if ($key === false) {
            return false;
        }
        unset($files[$key]);
        $this->write_created_files(array_values($files));
        return true;
    }

    // -------------------------------------------------------------------------
    // Private: ai-changes branch operations
    // -------------------------------------------------------------------------

    /**
     * Update the ai-changes branch with current working directory state of tracked files.
     */
    private function update_ai_changes_branch(string $reason = ''): void {
        $entries = $this->read_index();
        $created = $this->get_created_files();

        $files = [];

        // Add modified/deleted files (get current content from working dir)
        foreach ($entries as $path => $info) {
            $full_path = $this->work_tree . '/' . $path;
            if (file_exists($full_path)) {
                $content = file_get_contents($full_path);
                $sha = $this->write_blob($content);
                $files[$path] = $sha;
            }
            // If file doesn't exist (deleted), don't include in ai-changes tree
        }

        // Add created files
        foreach ($created as $path) {
            $full_path = $this->work_tree . '/' . $path;
            if (file_exists($full_path)) {
                $content = file_get_contents($full_path);
                $sha = $this->write_blob($content);
                $files[$path] = $sha;
            }
        }

        if (empty($files)) {
            return;
        }

        // Build tree and create commit
        $tree_sha = $this->build_tree($files);

        // Get parent commit: use ai-changes if it exists, otherwise use main
        $ref_path = $this->git_dir . '/refs/heads/ai-changes';
        if (file_exists($ref_path)) {
            $parent = trim(file_get_contents($ref_path));
        } else {
            $main_ref = $this->git_dir . '/refs/heads/main';
            $parent = file_exists($main_ref) ? trim(file_get_contents($main_ref)) : null;
        }

        $message = $reason ?: 'AI modification';
        $commit_sha = $this->write_commit($tree_sha, $parent, $message);

        // Update ref and HEAD
        file_put_contents($ref_path, $commit_sha . "\n");
        file_put_contents($this->git_dir . '/HEAD', "ref: refs/heads/ai-changes\n");
    }

    /**
     * Get commits from ai-changes branch that are relevant to a path prefix.
     * Returns commits with their messages and file contents.
     */
    private function get_commits_for_prefix(string $path_prefix): array {
        $ref_path = $this->git_dir . '/refs/heads/ai-changes';
        if (!file_exists($ref_path)) {
            return [];
        }

        $commits = [];
        $commit_sha = trim(file_get_contents($ref_path));

        // Walk commit history
        while ($commit_sha) {
            $commit_data = $this->read_object($commit_sha);
            if ($commit_data === null || $commit_data['type'] !== 'commit') {
                break;
            }

            // Extract message (after double newline)
            $parts = explode("\n\n", $commit_data['content'], 2);
            $message = isset($parts[1]) ? trim($parts[1]) : '';

            // Get tree SHA from commit
            if (!preg_match('/^tree ([a-f0-9]{40})/m', $commit_data['content'], $matches)) {
                break;
            }
            $tree_sha = $matches[1];

            // Get parent commit SHA
            $parent_sha = null;
            if (preg_match('/^parent ([a-f0-9]{40})/m', $commit_data['content'], $matches)) {
                $parent_sha = $matches[1];
            }

            // Get files from this commit's tree that match our prefix
            $current_files = $this->get_files_from_tree_with_prefix($tree_sha, $path_prefix);

            // Get files from parent's tree to compare
            $parent_files = [];
            if ($parent_sha) {
                $parent_data = $this->read_object($parent_sha);
                if ($parent_data && preg_match('/^tree ([a-f0-9]{40})/m', $parent_data['content'], $matches)) {
                    $parent_files = $this->get_files_from_tree_with_prefix($matches[1], $path_prefix);
                }
            }

            // Detect changes in files matching our prefix
            $changed_files = [];
            foreach ($current_files as $path => $content) {
                if (!isset($parent_files[$path]) || $parent_files[$path] !== $content) {
                    $changed_files[$path] = $content;
                }
            }
            // Detect deletions
            foreach ($parent_files as $path => $content) {
                if (!isset($current_files[$path])) {
                    $changed_files[$path] = null;
                }
            }

            // If any files in our prefix changed, include this commit
            if (!empty($changed_files) && $message) {
                $commits[] = [
                    'message' => $message,
                    'files' => $current_files,
                ];
            }

            // Move to parent
            $commit_sha = $parent_sha;
        }

        return array_reverse($commits); // Chronological order
    }

    /**
     * Get all files from a tree that match a path prefix, with their contents.
     */
    private function get_files_from_tree_with_prefix(string $tree_sha, string $path_prefix): array {
        $all_files = $this->get_all_files_from_tree($tree_sha, '');
        $result = [];

        foreach ($all_files as $path => $sha) {
            if (strpos($path, $path_prefix) === 0) {
                $relative = substr($path, strlen($path_prefix));
                $content = $this->read_blob($sha);
                if ($content !== null) {
                    $result[$relative] = $content;
                }
            }
        }

        return $result;
    }

    /**
     * Recursively get all files from a tree.
     */
    private function get_all_files_from_tree(string $tree_sha, string $prefix): array {
        $tree_data = $this->read_object($tree_sha);
        if ($tree_data === null || $tree_data['type'] !== 'tree') {
            return [];
        }

        $files = [];
        $entries = $this->parse_tree($tree_data['content']);

        foreach ($entries as $entry) {
            $path = $prefix ? $prefix . '/' . $entry['name'] : $entry['name'];

            if ($entry['mode'] === '40000') {
                // Directory - recurse
                $files = array_merge($files, $this->get_all_files_from_tree($entry['sha'], $path));
            } else {
                // File
                $files[$path] = $entry['sha'];
            }
        }

        return $files;
    }

    /**
     * Get file content from the ai-changes branch.
     */
    private function get_file_from_ai_changes(string $relative_path): ?string {
        $ref_path = $this->git_dir . '/refs/heads/ai-changes';
        if (!file_exists($ref_path)) {
            return null;
        }

        $commit_sha = trim(file_get_contents($ref_path));
        return $this->get_file_from_commit($commit_sha, $relative_path);
    }

    /**
     * Get file content from a specific commit.
     */
    private function get_file_from_commit(string $commit_sha, string $path): ?string {
        // Read commit object to get tree SHA
        $commit_data = $this->read_object($commit_sha);
        if ($commit_data === null || $commit_data['type'] !== 'commit') {
            return null;
        }

        // Parse tree SHA from commit
        if (!preg_match('/^tree ([a-f0-9]{40})/m', $commit_data['content'], $matches)) {
            return null;
        }
        $tree_sha = $matches[1];

        // Navigate tree to find file
        return $this->get_file_from_tree($tree_sha, explode('/', $path));
    }

    /**
     * Navigate tree structure to find a file.
     */
    private function get_file_from_tree(string $tree_sha, array $path_parts): ?string {
        $tree_data = $this->read_object($tree_sha);
        if ($tree_data === null || $tree_data['type'] !== 'tree') {
            return null;
        }

        $name = array_shift($path_parts);
        $entries = $this->parse_tree($tree_data['content']);

        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                if (empty($path_parts)) {
                    // This is the file
                    if ($entry['mode'] === '40000') {
                        return null; // It's a directory, not a file
                    }
                    return $this->read_blob($entry['sha']);
                } else {
                    // Navigate into subdirectory
                    return $this->get_file_from_tree($entry['sha'], $path_parts);
                }
            }
        }

        return null;
    }

    /**
     * Read and decompress a git object.
     */
    private function read_object(string $sha): ?array {
        $path = $this->git_dir . '/objects/' . substr($sha, 0, 2) . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            return null;
        }

        $compressed = file_get_contents($path);
        $data = gzuncompress($compressed);

        // Parse "type size\0content"
        $null_pos = strpos($data, "\0");
        if ($null_pos === false) {
            return null;
        }

        $header = substr($data, 0, $null_pos);
        $content = substr($data, $null_pos + 1);

        list($type, $size) = explode(' ', $header);

        return ['type' => $type, 'size' => (int)$size, 'content' => $content];
    }

    /**
     * Parse tree object content into entries.
     */
    private function parse_tree(string $content): array {
        $entries = [];
        $pos = 0;
        $len = strlen($content);

        while ($pos < $len) {
            // Find space (end of mode)
            $space_pos = strpos($content, ' ', $pos);
            if ($space_pos === false) break;

            $mode = substr($content, $pos, $space_pos - $pos);
            $pos = $space_pos + 1;

            // Find null (end of name)
            $null_pos = strpos($content, "\0", $pos);
            if ($null_pos === false) break;

            $name = substr($content, $pos, $null_pos - $pos);
            $pos = $null_pos + 1;

            // Read 20-byte SHA
            $sha = bin2hex(substr($content, $pos, 20));
            $pos += 20;

            $entries[] = ['mode' => $mode, 'name' => $name, 'sha' => $sha];
        }

        return $entries;
    }

    // -------------------------------------------------------------------------
    // Private: Commit operations
    // -------------------------------------------------------------------------

    private function update_commit(): void {
        $entries = $this->read_index();

        $files = [];
        foreach ($entries as $path => $info) {
            $files[$path] = $info['sha'];
        }

        $tree_sha = $this->build_tree($files);
        $ref_path = $this->git_dir . '/refs/heads/main';
        $parent = file_exists($ref_path) ? trim(file_get_contents($ref_path)) : null;
        $message = $parent ? "Track AI changes" : "Original state before AI modifications";
        $commit_sha = $this->write_commit($tree_sha, $parent, $message);

        file_put_contents($ref_path, $commit_sha . "\n");
    }

    private function write_tree(array $entries): string {
        usort($entries, fn($a, $b) => strcmp($a['name'], $b['name']));

        $content = '';
        foreach ($entries as $entry) {
            $content .= $entry['mode'] . ' ' . $entry['name'] . "\0" . hex2bin($entry['sha']);
        }

        $header = "tree " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $this->git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    private function build_tree(array $files): string {
        if (empty($files)) {
            return $this->write_tree([]);
        }

        $tree = [];
        foreach ($files as $path => $sha) {
            $parts = explode('/', $path);
            $this->nest_in_tree($tree, $parts, $sha);
        }

        return $this->write_tree_recursive($tree);
    }

    private function nest_in_tree(array &$tree, array $parts, string $sha): void {
        $name = array_shift($parts);
        if (empty($parts)) {
            $tree[$name] = ['type' => 'blob', 'sha' => $sha];
        } else {
            if (!isset($tree[$name])) {
                $tree[$name] = ['type' => 'tree', 'children' => []];
            }
            $this->nest_in_tree($tree[$name]['children'], $parts, $sha);
        }
    }

    private function write_tree_recursive(array $tree): string {
        $entries = [];
        foreach ($tree as $name => $item) {
            if ($item['type'] === 'blob') {
                $entries[] = ['mode' => '100644', 'name' => $name, 'sha' => $item['sha']];
            } else {
                $subtree_sha = $this->write_tree_recursive($item['children']);
                $entries[] = ['mode' => '40000', 'name' => $name, 'sha' => $subtree_sha];
            }
        }
        return $this->write_tree($entries);
    }

    private function write_commit(string $tree_sha, ?string $parent, string $message): string {
        $ts = time();
        $author = "AI Assistant <ai@local> {$ts} +0000";

        $content = "tree {$tree_sha}\n";
        if ($parent) {
            $content .= "parent {$parent}\n";
        }
        $content .= "author {$author}\ncommitter {$author}\n\n{$message}\n";

        $header = "commit " . strlen($content) . "\0";
        $store = $header . $content;
        $sha = sha1($store);

        $dir = $this->git_dir . '/objects/' . substr($sha, 0, 2);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir . '/' . substr($sha, 2);
        if (!file_exists($path)) {
            file_put_contents($path, gzcompress($store));
        }

        return $sha;
    }

    // -------------------------------------------------------------------------
    // Private: Diff formatting
    // -------------------------------------------------------------------------

    private function format_diff(string $path, string $original, string $current, string $type): string {
        $lines = ["diff --git a/{$path} b/{$path}"];

        if ($type === 'created') {
            $lines[] = "new file mode 100644";
            $lines[] = "--- /dev/null";
            $lines[] = "+++ b/{$path}";
            $current_lines = explode("\n", $current);
            $lines[] = "@@ -0,0 +1," . count($current_lines) . " @@";
            foreach ($current_lines as $l) {
                $lines[] = '+' . $l;
            }
        } elseif ($type === 'deleted') {
            $lines[] = "deleted file mode 100644";
            $lines[] = "--- a/{$path}";
            $lines[] = "+++ /dev/null";
            $orig_lines = explode("\n", $original);
            $lines[] = "@@ -1," . count($orig_lines) . " +0,0 @@";
            foreach ($orig_lines as $l) {
                $lines[] = '-' . $l;
            }
        } else {
            $lines[] = "--- a/{$path}";
            $lines[] = "+++ b/{$path}";
            $lines[] = $this->compute_hunks($original, $current);
        }

        $lines[] = "";
        return implode("\n", $lines);
    }

    private function compute_hunks(string $original, string $current): string {
        $old = explode("\n", $original);
        $new = explode("\n", $current);
        $diff = $this->lcs_diff($old, $new);

        $hunks = [];
        $hunk = [];
        $old_line = 0;
        $new_line = 0;
        $hunk_old_start = 0;
        $hunk_new_start = 0;
        $context = [];

        foreach ($diff as [$type, $line]) {
            if ($type === '=') {
                $old_line++;
                $new_line++;
                if (!empty($hunk)) {
                    $hunk[] = ' ' . $line;
                    $trailing = 0;
                    for ($i = count($hunk) - 1; $i >= 0 && $hunk[$i][0] === ' '; $i--) {
                        $trailing++;
                    }
                    if ($trailing >= 3) {
                        $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start, $hunk_new_start, $new_line - $hunk_new_start, $hunk);
                        $hunk = [];
                    }
                } else {
                    $context[] = ' ' . $line;
                    if (count($context) > 3) {
                        array_shift($context);
                    }
                }
            } else {
                if (empty($hunk)) {
                    $hunk_old_start = $old_line - count($context) + 1;
                    $hunk_new_start = $new_line - count($context) + 1;
                    $hunk = $context;
                    $context = [];
                }
                if ($type === '-') {
                    $old_line++;
                    $hunk[] = '-' . $line;
                } else {
                    $new_line++;
                    $hunk[] = '+' . $line;
                }
            }
        }

        if (!empty($hunk)) {
            $hunks[] = $this->format_hunk($hunk_old_start, $old_line - $hunk_old_start + 1, $hunk_new_start, $new_line - $hunk_new_start + 1, $hunk);
        }

        return implode("\n", $hunks);
    }

    private function format_hunk(int $os, int $oc, int $ns, int $nc, array $lines): string {
        return "@@ -{$os},{$oc} +{$ns},{$nc} @@\n" . implode("\n", $lines);
    }

    private function lcs_diff(array $old, array $new): array {
        $ol = count($old);
        $nl = count($new);
        $m = [];

        for ($i = 0; $i <= $ol; $i++) $m[$i][0] = 0;
        for ($j = 0; $j <= $nl; $j++) $m[0][$j] = 0;

        for ($i = 1; $i <= $ol; $i++) {
            for ($j = 1; $j <= $nl; $j++) {
                $m[$i][$j] = ($old[$i-1] === $new[$j-1])
                    ? $m[$i-1][$j-1] + 1
                    : max($m[$i-1][$j], $m[$i][$j-1]);
            }
        }

        $result = [];
        $i = $ol;
        $j = $nl;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i-1] === $new[$j-1]) {
                array_unshift($result, ['=', $old[$i-1]]);
                $i--; $j--;
            } elseif ($j > 0 && ($i === 0 || $m[$i][$j-1] >= $m[$i-1][$j])) {
                array_unshift($result, ['+', $new[$j-1]]);
                $j--;
            } else {
                array_unshift($result, ['-', $old[$i-1]]);
                $i--;
            }
        }

        return $result;
    }

    private function recursive_delete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursive_delete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
