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
        $read_only_tools = ['read_file', 'list_directory', 'file_exists', 'search_files', 'search_content', 'db_query', 'get_option', 'get_plugins', 'get_themes'];

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
                return $this->read_file($arguments['path']);
            case 'write_file':
                return $this->write_file($arguments['path'], $arguments['content']);
            case 'edit_file':
                return $this->edit_file($arguments['path'], $arguments['edits']);
            case 'append_file':
                return $this->append_file($arguments['path'], $arguments['content']);
            case 'delete_file':
                return $this->delete_file($arguments['path']);
            case 'list_directory':
                return $this->list_directory($arguments['path']);
            case 'create_directory':
                return $this->create_directory($arguments['path']);
            case 'file_exists':
                return $this->file_exists_check($arguments['path']);
            case 'search_files':
                return $this->search_files($arguments['pattern']);
            case 'search_content':
                return $this->search_content(
                    $arguments['needle'],
                    $arguments['directory'] ?? '',
                    $arguments['file_pattern'] ?? '*.php'
                );

            // Database operations
            case 'db_query':
                return $this->db_query($arguments['sql']);
            case 'db_insert':
                return $this->db_insert($arguments['table'], $arguments['data']);
            case 'db_update':
                return $this->db_update($arguments['table'], $arguments['data'], $arguments['where']);
            case 'db_delete':
                return $this->db_delete($arguments['table'], $arguments['where']);
            case 'get_option':
                return $this->get_wp_option($arguments['name']);
            case 'update_option':
                return $this->update_wp_option($arguments['name'], $arguments['value']);

            // WordPress operations
            case 'get_plugins':
                return $this->get_plugins();
            case 'activate_plugin':
                return $this->activate_plugin($arguments['plugin']);
            case 'deactivate_plugin':
                return $this->deactivate_plugin($arguments['plugin']);
            case 'get_themes':
                return $this->get_themes();
            case 'switch_theme':
                return $this->switch_theme($arguments['theme']);

            default:
                throw new \Exception("Unknown tool: $tool_name");
        }
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

    private function append_file(string $path, string $content): array {
        $full_path = $this->resolve_path($path);

        if (!file_exists($full_path)) {
            throw new \Exception("File not found: $path");
        }

        if (file_put_contents($full_path, $content, FILE_APPEND) === false) {
            throw new \Exception("Failed to append to file: $path");
        }

        return [
            'path' => $path,
            'action' => 'appended',
            'appended_size' => strlen($content),
            'new_size' => filesize($full_path),
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

    private function create_directory(string $path): array {
        $full_path = $this->resolve_path($path);

        if (file_exists($full_path)) {
            throw new \Exception("Path already exists: $path");
        }

        if (!mkdir($full_path, 0755, true)) {
            throw new \Exception("Failed to create directory: $path");
        }

        return [
            'path' => $path,
            'action' => 'created',
        ];
    }

    private function file_exists_check(string $path): array {
        $full_path = $this->resolve_path($path);

        return [
            'path' => $path,
            'exists' => file_exists($full_path),
            'type' => file_exists($full_path) ? (is_dir($full_path) ? 'directory' : 'file') : null,
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

    private function db_insert(string $table, array $data): array {
        global $wpdb;

        $table_name = $wpdb->prefix . $table;

        $result = $wpdb->insert($table_name, $data);

        if ($result === false) {
            throw new \Exception("Insert failed: " . $wpdb->last_error);
        }

        return [
            'table' => $table,
            'action' => 'inserted',
            'id' => $wpdb->insert_id,
        ];
    }

    private function db_update(string $table, array $data, array $where): array {
        global $wpdb;

        $table_name = $wpdb->prefix . $table;

        $result = $wpdb->update($table_name, $data, $where);

        if ($result === false) {
            throw new \Exception("Update failed: " . $wpdb->last_error);
        }

        return [
            'table' => $table,
            'action' => 'updated',
            'rows_affected' => $result,
        ];
    }

    private function db_delete(string $table, array $where): array {
        global $wpdb;

        $table_name = $wpdb->prefix . $table;

        $result = $wpdb->delete($table_name, $where);

        if ($result === false) {
            throw new \Exception("Delete failed: " . $wpdb->last_error);
        }

        return [
            'table' => $table,
            'action' => 'deleted',
            'rows_affected' => $result,
        ];
    }

    private function get_wp_option(string $name): array {
        $value = get_option($name, null);

        return [
            'name' => $name,
            'value' => $value,
            'exists' => $value !== null,
        ];
    }

    private function update_wp_option(string $name, $value): array {
        $old_value = get_option($name);
        $result = update_option($name, $value);

        return [
            'name' => $name,
            'action' => 'updated',
            'old_value' => $old_value,
            'new_value' => $value,
            'changed' => $result,
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

    private function activate_plugin(string $plugin): array {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin);

        if (is_wp_error($result)) {
            throw new \Exception("Failed to activate plugin: " . $result->get_error_message());
        }

        return [
            'plugin' => $plugin,
            'action' => 'activated',
        ];
    }

    private function deactivate_plugin(string $plugin): array {
        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        deactivate_plugins($plugin);

        return [
            'plugin' => $plugin,
            'action' => 'deactivated',
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

    private function switch_theme(string $theme): array {
        $all_themes = wp_get_themes();

        if (!isset($all_themes[$theme])) {
            throw new \Exception("Theme not found: $theme");
        }

        $old_theme = get_stylesheet();
        switch_theme($theme);

        return [
            'theme' => $theme,
            'action' => 'switched',
            'previous_theme' => $old_theme,
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
