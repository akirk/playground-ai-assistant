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
    public function track_change(string $path, string $change_type, ?string $original_content = null): bool {
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
            $this->update_ai_changes_branch();
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
        $this->update_ai_changes_branch();

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
            error_log("Git_Tracker: Skipping invalid path: " . bin2hex($path));
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

            error_log("Git_Tracker::generate_diff - Processing: {$path}");

            if (in_array($path, $created)) {
                // New file
                if (file_exists($full_path)) {
                    $current = file_get_contents($full_path);
                    $output[] = $this->format_diff($path, '', $current, 'created');
                } else {
                    error_log("Git_Tracker::generate_diff - Created file not found: {$full_path}");
                }
            } elseif (isset($entries[$path])) {
                // Modified or deleted
                $sha = $entries[$path]['sha'];
                error_log("Git_Tracker::generate_diff - Reading blob: {$sha}");
                $original = $this->read_blob($sha);
                if ($original === null) {
                    error_log("Git_Tracker::generate_diff - Blob not found for: {$path} (sha: {$sha})");
                    continue;
                }
                $current = file_exists($full_path) ? file_get_contents($full_path) : '';
                $type = file_exists($full_path) ? 'modified' : 'deleted';
                error_log("Git_Tracker::generate_diff - Type: {$type}, original len: " . strlen($original) . ", current len: " . strlen($current));

                if ($original !== $current) {
                    $output[] = $this->format_diff($path, $original, $current, $type);
                }
            } else {
                error_log("Git_Tracker::generate_diff - Path not in entries or created: {$path}");
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
        file_put_contents($this->git_dir . '/config', "[core]\n\trepositoryformatversion = 0\n\tfilemode = true\n\tbare = false\n");

        $this->write_index([]);
        $this->write_created_files([]);
        $this->create_initial_commit();
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

            if ($offset + 62 > strlen($data)) {
                break;
            }

            // Skip ctime, mtime, dev, ino (32 bytes)
            $offset += 32;

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

            error_log("Git_Tracker::read_index - Read entry: '{$name}' (len=" . strlen($name) . ", name_len_from_flags={$name_len}), sha={$sha}");

            if (!empty($name)) {
                $entries[$name] = [
                    'sha' => $sha,
                    'size' => $meta['size'],
                    'mode' => $meta['mode'],
                ];
            }
        }

        error_log("Git_Tracker::read_index - Total entries: " . count($entries));
        return $entries;
    }

    private function write_index(array $entries): void {
        ksort($entries);

        error_log("Git_Tracker::write_index - Writing " . count($entries) . " entries");

        $body = '';
        $now = time();

        foreach ($entries as $path => $info) {
            error_log("Git_Tracker::write_index - Entry: '{$path}' (len=" . strlen($path) . "), sha=" . $info['sha']);

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
    private function update_ai_changes_branch(): void {
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

        // Get current ai-changes commit as parent (if exists)
        $parent = null;
        $ref_path = $this->git_dir . '/refs/heads/ai-changes';
        if (file_exists($ref_path)) {
            $parent = trim(file_get_contents($ref_path));
        }

        $commit_sha = $this->write_commit($tree_sha, $parent, "AI modifications");

        // Update ref
        file_put_contents($ref_path, $commit_sha . "\n");
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

    private function create_initial_commit(): void {
        $empty_tree = $this->write_tree([]);
        $commit_sha = $this->write_commit($empty_tree, null, "Initial commit");
        file_put_contents($this->git_dir . '/refs/heads/main', $commit_sha . "\n");
    }

    private function update_commit(): void {
        $entries = $this->read_index();

        $files = [];
        foreach ($entries as $path => $info) {
            $files[$path] = $info['sha'];
        }

        $tree_sha = $this->build_tree($files);
        $parent = trim(@file_get_contents($this->git_dir . '/refs/heads/main'));
        $commit_sha = $this->write_commit($tree_sha, $parent ?: null, "Track AI changes");

        file_put_contents($this->git_dir . '/refs/heads/main', $commit_sha . "\n");
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
