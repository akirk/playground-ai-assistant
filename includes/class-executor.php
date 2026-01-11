<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tool Executor - Handles execution of AI tools
 */
class Executor {

    private $tools;
    private $wp_content_path;

    public function __construct(Tools $tools) {
        $this->tools = $tools;
        $this->wp_content_path = WP_CONTENT_DIR;
    }

    /**
     * Execute a tool
     *
     * @param string $tool_name Tool name
     * @param array $arguments Tool arguments
     * @param string $permission User permission level
     * @return mixed Tool result
     */
    public function execute_tool(string $tool_name, array $arguments, string $permission = 'full') {
        // Validate permission
        $read_only_tools = ['read_file', 'list_directory', 'search_files', 'search_content', 'db_query', 'get_plugins', 'get_themes'];

        if ($permission === 'read_only' && !in_array($tool_name, $read_only_tools)) {
            throw new \Exception("Tool '$tool_name' requires full access permission");
        }

        if ($permission === 'chat_only') {
            throw new \Exception("Tool execution not allowed with chat-only permission");
        }

        // Execute the tool
        switch ($tool_name) {
            // File operations
            case 'read_file':
                return $this->read_file($this->get_string_arg($arguments, 'path', $tool_name));
            case 'write_file':
                $path = $this->get_string_arg($arguments, 'path', $tool_name);
                $content = $this->get_content_arg($arguments, 'content', $tool_name);
                return $this->write_file($path, $content);
            case 'edit_file':
                $path = $this->get_string_arg($arguments, 'path', $tool_name);
                $edits = $this->get_array_arg($arguments, 'edits', $tool_name);
                return $this->edit_file($path, $edits);
            case 'delete_file':
                return $this->delete_file($this->get_string_arg($arguments, 'path', $tool_name));
            case 'list_directory':
                return $this->list_directory($this->get_string_arg($arguments, 'path', $tool_name));
            case 'search_files':
                return $this->search_files($this->get_string_arg($arguments, 'pattern', $tool_name));
            case 'search_content':
                return $this->search_content(
                    $this->get_string_arg($arguments, 'needle', $tool_name),
                    $this->get_string_arg($arguments, 'directory', $tool_name, ''),
                    $this->get_string_arg($arguments, 'file_pattern', $tool_name, '*.php')
                );

            // Database operations
            case 'db_query':
                return $this->db_query($this->get_string_arg($arguments, 'sql', $tool_name));

            // WordPress operations
            case 'get_plugins':
                return $this->get_plugins();
            case 'get_themes':
                return $this->get_themes();
            case 'run_php':
                return $this->run_php($this->get_string_arg($arguments, 'code', $tool_name));

            default:
                throw new \Exception("Unknown tool: $tool_name");
        }
    }

    private function get_string_arg(array $args, string $name, string $tool, ?string $default = null): string {
        if (!isset($args[$name])) {
            if ($default !== null) {
                return $default;
            }
            throw new \Exception("$tool requires '$name' argument");
        }
        $value = $args[$name];
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    private function get_content_arg(array $args, string $name, string $tool): string {
        if (!isset($args[$name])) {
            throw new \Exception("$tool requires '$name' argument");
        }
        $value = $args[$name];
        if (!is_string($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        return $value;
    }

    private function get_array_arg(array $args, string $name, string $tool): array {
        if (!isset($args[$name])) {
            throw new \Exception("$tool requires '$name' argument");
        }
        $value = $args[$name];
        if (!is_array($value)) {
            throw new \Exception("$tool '$name' must be an array");
        }
        return $value;
    }

    // ===== FILE OPERATIONS =====

    /**
     * Validate and resolve a path within wp-content
     */
    private function resolve_path(string $relative_path): string {
        // Clean the path
        $relative_path = ltrim($relative_path, '/\\');

        if (empty($relative_path)) {
            throw new \Exception("Path cannot be empty");
        }

        // Build full path
        $full_path = $this->wp_content_path . '/' . $relative_path;

        error_log('[AI Assistant] resolve_path: relative=' . $relative_path . ', full=' . $full_path);

        // Resolve real path (handles ../ etc)
        $real_path = realpath(dirname($full_path));
        if ($real_path === false) {
            // Directory doesn't exist yet, check parent
            $parent = dirname($full_path);
            while (!file_exists($parent) && $parent !== dirname($parent)) {
                $parent = dirname($parent);
            }
            $real_path = realpath($parent);
        }

        $wp_content_real = realpath($this->wp_content_path);
        error_log('[AI Assistant] resolve_path: real_path=' . ($real_path ?: 'false') . ', wp_content=' . $wp_content_real);

        // Security check: ensure path is within wp-content
        if ($real_path === false) {
            throw new \Exception("Access denied: Cannot resolve path '$relative_path' (directory may not exist)");
        }

        if ($wp_content_real === false) {
            throw new \Exception("Server error: wp-content directory not found at " . $this->wp_content_path);
        }

        if (strpos($real_path, $wp_content_real) !== 0) {
            throw new \Exception("Access denied: Path '$relative_path' is outside wp-content directory");
        }

        return $full_path;
    }

    private function read_file(string $path): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        if (!is_readable($full_path)) {
            throw new \Exception("File not readable: $path");
        }

        $content = file_get_contents($full_path);
        if ($content === false) {
            throw new \Exception("Failed to read file: $path");
        }

        return [
            'path' => $path,
            'content' => $content,
            'size' => filesize($full_path),
            'modified' => date('Y-m-d H:i:s', filemtime($full_path)),
        ];
    }

    private function write_file(string $path, string $content): array {
        $full_path = $this->resolve_path($path);

        // Create directory if needed
        $dir = dirname($full_path);
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new \Exception("Failed to create directory: " . dirname($path));
            }
        }

        $existed = file_exists($full_path);
        $old_content = $existed ? file_get_contents($full_path) : null;

        if (file_put_contents($full_path, $content) === false) {
            throw new \Exception("Failed to write file: $path");
        }

        // Track if this is a plugin modification
        $this->track_plugin_modification($path);

        return [
            'path' => $path,
            'action' => $existed ? 'updated' : 'created',
            'size' => strlen($content),
            'previous_size' => $old_content !== null ? strlen($old_content) : null,
        ];
    }

    private function edit_file(string $path, array $edits): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        $content = file_get_contents($full_path);
        if ($content === false) {
            throw new \Exception("Failed to read file: $path");
        }

        $original_content = $content;
        $applied = [];
        $failed = [];

        foreach ($edits as $index => $edit) {
            $search = $edit['search'] ?? '';
            $replace = $edit['replace'] ?? '';

            if (empty($search)) {
                $failed[] = ['index' => $index, 'reason' => 'Empty search string'];
                continue;
            }

            // Count occurrences
            $count = substr_count($content, $search);

            if ($count === 0) {
                $failed[] = ['index' => $index, 'reason' => 'Search string not found', 'search' => substr($search, 0, 50)];
                continue;
            }

            if ($count > 1) {
                $failed[] = ['index' => $index, 'reason' => "Search string found $count times (must be unique)", 'search' => substr($search, 0, 50)];
                continue;
            }

            // Apply the edit
            $content = str_replace($search, $replace, $content);
            $applied[] = ['index' => $index, 'search_length' => strlen($search), 'replace_length' => strlen($replace)];
        }

        // Only write if at least one edit was applied
        if (count($applied) > 0) {
            if (file_put_contents($full_path, $content) === false) {
                throw new \Exception("Failed to write file: $path");
            }
            // Track if this is a plugin modification
            $this->track_plugin_modification($path);
        }

        return [
            'path' => $path,
            'edits_applied' => count($applied),
            'edits_failed' => count($failed),
            'applied' => $applied,
            'failed' => $failed,
            'original_size' => strlen($original_content),
            'new_size' => strlen($content),
        ];
    }

    private function delete_file(string $path): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        if (is_dir($full_path)) {
            // Recursively delete directory
            $this->delete_directory_recursive($full_path);
        } else {
            if (!unlink($full_path)) {
                throw new \Exception("Failed to delete file: $path");
            }
        }

        return [
            'path' => $path,
            'action' => 'deleted',
        ];
    }

    private function delete_directory_recursive(string $dir): void {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->delete_directory_recursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function list_directory(string $path): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("Directory not found: $path");
        }

        if (!is_dir($full_path)) {
            throw new \Exception("Not a directory: $path");
        }

        $items = [];
        $entries = scandir($full_path);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entry_path = $full_path . '/' . $entry;
            $items[] = [
                'name' => $entry,
                'type' => is_dir($entry_path) ? 'directory' : 'file',
                'size' => is_file($entry_path) ? filesize($entry_path) : null,
                'modified' => date('Y-m-d H:i:s', filemtime($entry_path)),
            ];
        }

        return [
            'path' => $path,
            'items' => $items,
            'count' => count($items),
        ];
    }

    private function search_files(string $pattern): array {
        $full_pattern = $this->wp_content_path . '/' . ltrim($pattern, '/');

        $files = glob($full_pattern, GLOB_BRACE);
        $results = [];

        foreach ($files as $file) {
            $relative = str_replace($this->wp_content_path . '/', '', $file);
            $results[] = [
                'path' => $relative,
                'type' => is_dir($file) ? 'directory' : 'file',
                'size' => is_file($file) ? filesize($file) : null,
            ];
        }

        return [
            'pattern' => $pattern,
            'matches' => $results,
            'count' => count($results),
        ];
    }

    private function search_content(string $needle, string $directory = '', string $file_pattern = '*.php'): array {
        $search_path = $this->wp_content_path;
        if (!empty($directory)) {
            $search_path = $this->resolve_path($directory);
        }

        $results = [];
        $this->search_content_recursive($search_path, $needle, $file_pattern, $results);

        return [
            'needle' => $needle,
            'directory' => $directory ?: 'wp-content',
            'matches' => $results,
            'count' => count($results),
        ];
    }

    private function search_content_recursive(string $dir, string $needle, string $pattern, array &$results, int $limit = 50): void {
        if (count($results) >= $limit) {
            return;
        }

        $files = glob($dir . '/' . $pattern);
        foreach ($files as $file) {
            if (count($results) >= $limit) {
                return;
            }

            if (is_file($file)) {
                $content = file_get_contents($file);
                if ($content !== false && stripos($content, $needle) !== false) {
                    // Find line numbers
                    $lines = explode("\n", $content);
                    $matching_lines = [];
                    foreach ($lines as $line_num => $line) {
                        if (stripos($line, $needle) !== false) {
                            $matching_lines[] = [
                                'line' => $line_num + 1,
                                'content' => trim(substr($line, 0, 200)),
                            ];
                        }
                    }

                    $results[] = [
                        'path' => str_replace($this->wp_content_path . '/', '', $file),
                        'matches' => array_slice($matching_lines, 0, 5),
                    ];
                }
            }
        }

        // Search subdirectories
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $subdir) {
            if (count($results) >= $limit) {
                return;
            }
            // Skip vendor and node_modules
            $basename = basename($subdir);
            if ($basename === 'vendor' || $basename === 'node_modules') {
                continue;
            }
            $this->search_content_recursive($subdir, $needle, $pattern, $results, $limit);
        }
    }

    // ===== DATABASE OPERATIONS =====

    private function db_query(string $sql): array {
        global $wpdb;

        // Security: Only allow SELECT queries
        $sql = trim($sql);
        if (stripos($sql, 'SELECT') !== 0) {
            throw new \Exception("Only SELECT queries are allowed with db_query. Use db_insert, db_update, or db_delete for modifications.");
        }

        // Replace {prefix} placeholder
        $sql = str_replace('{prefix}', $wpdb->prefix, $sql);

        $results = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            throw new \Exception("Database error: " . $wpdb->last_error);
        }

        return [
            'query' => $sql,
            'rows' => $results,
            'count' => count($results),
        ];
    }

    // ===== WORDPRESS OPERATIONS =====

    private function get_plugins(): array {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);

        $plugins = [];
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            $plugins[] = [
                'file' => $plugin_file,
                'name' => $plugin_data['Name'],
                'version' => $plugin_data['Version'],
                'description' => $plugin_data['Description'],
                'author' => $plugin_data['Author'],
                'active' => in_array($plugin_file, $active_plugins),
            ];
        }

        return [
            'plugins' => $plugins,
            'total' => count($plugins),
            'active_count' => count($active_plugins),
        ];
    }

    private function get_themes(): array {
        $all_themes = wp_get_themes();
        $active_theme = get_stylesheet();

        $themes = [];
        foreach ($all_themes as $theme_slug => $theme) {
            $themes[] = [
                'slug' => $theme_slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'author' => $theme->get('Author'),
                'active' => $theme_slug === $active_theme,
            ];
        }

        return [
            'themes' => $themes,
            'total' => count($themes),
            'active' => $active_theme,
        ];
    }

    private function run_php(string $code): array {
        ob_start();
        $error = null;
        $result = null;

        try {
            $result = eval($code);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $output = ob_get_clean();

        if ($error !== null) {
            throw new \Exception("PHP error: $error");
        }

        return [
            'result' => $result,
            'output' => $output,
        ];
    }

    /**
     * Track plugin modifications for download functionality
     */
    private function track_plugin_modification(string $path): void {
        // Check if path is within plugins directory
        if (strpos($path, 'plugins/') !== 0) {
            return;
        }

        // Extract plugin folder name (e.g., "plugins/my-plugin/file.php" -> "my-plugin")
        $parts = explode('/', $path);
        if (count($parts) < 2) {
            return;
        }
        $plugin_folder = $parts[1];

        // Get current tracked plugins
        $tracked = get_option('ai_assistant_modified_plugins', []);

        // Add this plugin if not already tracked
        if (!in_array($plugin_folder, $tracked)) {
            $tracked[] = $plugin_folder;
            update_option('ai_assistant_modified_plugins', $tracked);
        }
    }
}
