<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages Git_Tracker instances for multiple plugins/themes.
 *
 * Each plugin/theme gets its own .git directory. This class determines
 * which tracker to use based on file paths and aggregates data across
 * all trackers for the UI.
 */
class Git_Tracker_Manager {

    private array $trackers = [];

    /**
     * Get the Git_Tracker for a given file path.
     * Creates a new tracker if one doesn't exist yet.
     *
     * @param string $path Path relative to wp-content (e.g., "plugins/my-plugin/file.php")
     * @return Git_Tracker|null Returns null if path is not within a plugin/theme
     */
    public function get_tracker_for_path(string $path): ?Git_Tracker {
        $root = $this->get_root_for_path($path);
        if ($root === null) {
            return null;
        }

        return $this->get_or_create_tracker($root);
    }

    /**
     * Get the plugin/theme root directory for a path.
     *
     * @param string $path Path relative to wp-content
     * @return string|null Absolute path to plugin/theme root, or null if not in a plugin/theme
     */
    public function get_root_for_path(string $path): ?string {
        $path = ltrim($path, '/');
        $parts = explode('/', $path);

        if (count($parts) < 2) {
            return null;
        }

        $type = $parts[0];
        $name = $parts[1];

        // Return the root path based on path structure
        // Don't require directory to exist - it may have just been created
        if ($type === 'plugins') {
            return WP_PLUGIN_DIR . '/' . $name;
        } elseif ($type === 'themes') {
            return get_theme_root() . '/' . $name;
        }

        return null;
    }

    /**
     * Get or create a tracker for a specific root directory.
     *
     * @param string $root Absolute path to plugin/theme directory
     * @return Git_Tracker
     */
    public function get_or_create_tracker(string $root): Git_Tracker {
        $root = rtrim($root, '/');

        if (!isset($this->trackers[$root])) {
            $this->trackers[$root] = new Git_Tracker($root);
        }

        return $this->trackers[$root];
    }

    /**
     * Get all active trackers (those with .git directories).
     *
     * @return Git_Tracker[]
     */
    /**
     * Get trackers that have AI changes (ai-changes branch exists).
     * This excludes plugins with .git from Playground that haven't been modified.
     */
    public function get_active_trackers(): array {
        $this->discover_trackers();

        $active = [];
        foreach ($this->trackers as $root => $tracker) {
            if ($tracker->has_ai_changes()) {
                $active[$root] = $tracker;
            }
        }

        return $active;
    }

    /**
     * Discover all plugins/themes that have .git directories.
     */
    private function discover_trackers(): void {
        // Check all plugins
        $plugin_dirs = glob(WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR);
        if ($plugin_dirs) {
            foreach ($plugin_dirs as $dir) {
                if (is_dir($dir . '/.git')) {
                    $this->get_or_create_tracker($dir);
                }
            }
        }

        // Check all themes
        $theme_dirs = glob(get_theme_root() . '/*', GLOB_ONLYDIR);
        if ($theme_dirs) {
            foreach ($theme_dirs as $dir) {
                if (is_dir($dir . '/.git')) {
                    $this->get_or_create_tracker($dir);
                }
            }
        }
    }

    /**
     * Get all changes across all plugins/themes, grouped by plugin.
     *
     * @return array
     */
    public function get_all_changes_by_plugin(): array {
        $trackers = $this->get_active_trackers();
        $result = [];

        foreach ($trackers as $root => $tracker) {
            $info = $tracker->get_changes_info();
            if (!empty($info) && $info['file_count'] > 0) {
                $relative_root = $this->get_relative_path($root);

                // Prefix file paths with plugin path for wp-content-relative paths
                foreach ($info['files'] as &$file) {
                    $file['path'] = $relative_root . '/' . $file['path'];
                }
                unset($file);

                $info['path'] = $relative_root;
                $info['work_tree'] = $root;
                $result[$relative_root] = $info;
            }
        }

        return $result;
    }

    /**
     * Get all changes across all plugins/themes, grouped by directory.
     *
     * @return array
     */
    public function get_all_changes_by_directory(): array {
        $trackers = $this->get_active_trackers();
        $result = [];

        foreach ($trackers as $root => $tracker) {
            $relative_root = $this->get_relative_path($root);
            $dirs = $tracker->get_changes_by_directory();

            foreach ($dirs as $dir => $data) {
                $full_dir = $relative_root . ($dir ? '/' . $dir : '');
                $result[$full_dir] = $data;
                $result[$full_dir]['path'] = $full_dir;

                // Update file paths to be relative to wp-content
                foreach ($result[$full_dir]['files'] as &$file) {
                    $file['path'] = $relative_root . '/' . $file['path'];
                }
            }
        }

        return $result;
    }

    /**
     * Check if any plugin/theme has tracked changes.
     *
     * @return bool
     */
    public function has_changes(): bool {
        $trackers = $this->get_active_trackers();

        foreach ($trackers as $tracker) {
            if ($tracker->has_changes()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate diff for files across all trackers.
     *
     * @param array $file_paths Paths relative to wp-content
     * @return string
     */
    public function generate_diff(array $file_paths): string {
        // Group files by their tracker
        $by_tracker = [];
        foreach ($file_paths as $path) {
            $root = $this->get_root_for_path($path);
            if ($root === null) {
                continue;
            }

            if (!isset($by_tracker[$root])) {
                $by_tracker[$root] = [];
            }

            // Convert to path relative to plugin root
            $relative = $this->path_relative_to_root($path, $root);
            $by_tracker[$root][] = $relative;
        }

        $diffs = [];
        foreach ($by_tracker as $root => $paths) {
            $tracker = $this->get_or_create_tracker($root);
            $diff = $tracker->generate_diff($paths);
            if ($diff) {
                $diffs[] = $diff;
            }
        }

        return implode("\n", $diffs);
    }

    /**
     * Get original content of a file.
     *
     * @param string $path Path relative to wp-content
     * @return string|null
     */
    public function get_original_content(string $path): ?string {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return null;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->get_original_content($relative);
    }

    /**
     * Revert a file to its original state.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function revert_file(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->revert_file($relative);
    }

    /**
     * Reapply changes to a file.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function reapply_file(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->reapply_file($relative);
    }

    /**
     * Check if a file is reverted.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function is_reverted(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->is_reverted($relative);
    }

    /**
     * Check if a file is tracked.
     *
     * @param string $path Path relative to wp-content
     * @return bool
     */
    public function is_tracked(string $path): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->is_tracked($relative);
    }

    /**
     * Clear all tracked changes.
     *
     * @return int Number of files cleared
     */
    public function clear_all(): int {
        $trackers = $this->get_active_trackers();
        $count = 0;

        foreach ($trackers as $tracker) {
            $count += $tracker->clear_all();
        }

        return $count;
    }

    /**
     * Clear specific files from tracking.
     *
     * @param array $paths Paths relative to wp-content
     * @return int
     */
    public function clear_files(array $paths): int {
        $by_tracker = [];
        foreach ($paths as $path) {
            $root = $this->get_root_for_path($path);
            if ($root === null) {
                continue;
            }

            if (!isset($by_tracker[$root])) {
                $by_tracker[$root] = [];
            }

            $relative = $this->path_relative_to_root($path, $root);
            $by_tracker[$root][] = $relative;
        }

        $count = 0;
        foreach ($by_tracker as $root => $tracker_paths) {
            $tracker = $this->get_or_create_tracker($root);
            $count += $tracker->clear_files($tracker_paths);
        }

        return $count;
    }

    /**
     * Get commit log from a specific plugin/theme.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_commit_log(string $plugin_path, int $limit = 20, int $offset = 0): array {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null) {
            return ['commits' => [], 'has_more' => false];
        }

        $tracker = $this->get_or_create_tracker($root);
        $commits = $tracker->get_recent_commits($limit + 1);

        // Skip offset
        $commits = array_slice($commits, $offset);

        $has_more = count($commits) > $limit;
        if ($has_more) {
            array_pop($commits);
        }

        return ['commits' => $commits, 'has_more' => $has_more];
    }

    /**
     * Get diff for a specific commit.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $sha Commit SHA
     * @return string
     */
    public function get_commit_diff(string $plugin_path, string $sha): string {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null) {
            return '';
        }

        $tracker = $this->get_or_create_tracker($root);
        return $tracker->get_commit_diff($sha);
    }

    /**
     * Revert all files to a specific commit.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $sha Commit SHA
     * @return array
     */
    public function revert_to_commit(string $plugin_path, string $sha): array {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null) {
            return ['success' => false, 'errors' => ['Invalid plugin path']];
        }

        $tracker = $this->get_or_create_tracker($root);
        return $tracker->revert_to_commit($sha);
    }

    /**
     * Build standalone git for a plugin/theme for ZIP export.
     *
     * @param string $plugin_path Path like "plugins/my-plugin"
     * @param string $target_dir Directory to create .git in
     * @return bool
     */
    public function build_standalone_git(string $plugin_path, string $target_dir): bool {
        $root = $this->get_root_for_path($plugin_path . '/dummy');
        if ($root === null) {
            return false;
        }

        $tracker = $this->get_or_create_tracker($root);
        return $tracker->build_standalone_git($target_dir);
    }

    /**
     * Track a file change.
     *
     * @param string $path Path relative to wp-content
     * @param string $change_type 'created', 'modified', or 'deleted'
     * @param string|null $original_content Original content (for modified/deleted)
     * @param string $reason Reason for the change
     * @param int|null $conversation_id Conversation ID
     * @return bool
     */
    public function track_change(string $path, string $change_type, ?string $original_content = null, string $reason = '', ?int $conversation_id = null): bool {
        $tracker = $this->get_tracker_for_path($path);
        if ($tracker === null) {
            return false;
        }

        $relative = $this->path_relative_to_tracker($path, $tracker);
        return $tracker->track_change($relative, $change_type, $original_content, $reason, $conversation_id);
    }

    /**
     * Get wp-content relative path for a root directory.
     *
     * @param string $root Absolute path
     * @return string Path like "plugins/my-plugin"
     */
    private function get_relative_path(string $root): string {
        if (strpos($root, WP_PLUGIN_DIR) === 0) {
            return 'plugins/' . basename($root);
        }
        if (strpos($root, get_theme_root()) === 0) {
            return 'themes/' . basename($root);
        }
        return basename($root);
    }

    /**
     * Convert a wp-content relative path to a tracker-relative path.
     *
     * @param string $path Path relative to wp-content
     * @param Git_Tracker $tracker
     * @return string Path relative to tracker's work_tree
     */
    private function path_relative_to_tracker(string $path, Git_Tracker $tracker): string {
        $root = $tracker->get_work_tree();
        return $this->path_relative_to_root($path, $root);
    }

    /**
     * Convert a wp-content relative path to a root-relative path.
     *
     * @param string $path Path like "plugins/my-plugin/includes/foo.php"
     * @param string $root Absolute root path
     * @return string Path like "includes/foo.php"
     */
    private function path_relative_to_root(string $path, string $root): string {
        $path = ltrim($path, '/');
        $parts = explode('/', $path);

        // Remove the first two parts (type/name)
        if (count($parts) > 2) {
            return implode('/', array_slice($parts, 2));
        }

        return '';
    }
}
