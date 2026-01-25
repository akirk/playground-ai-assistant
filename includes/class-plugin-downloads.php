<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Downloads - Adds download ZIP links for AI-modified plugins
 */
class Plugin_Downloads {

    private $git_tracker_manager;

    public function __construct(Git_Tracker_Manager $git_tracker_manager) {
        $this->git_tracker_manager = $git_tracker_manager;
        add_filter('plugin_action_links', [$this, 'add_download_link'], 10, 4);
        add_action('admin_action_ai_assistant_download_plugin', [$this, 'handle_download']);
    }

    /**
     * Check if a plugin has been modified via git tracking
     */
    private function is_plugin_modified(string $plugin_folder): bool {
        $plugin_path = 'plugins/' . $plugin_folder;
        $plugins = $this->git_tracker_manager->get_all_changes_by_plugin();

        return isset($plugins[$plugin_path]) && $plugins[$plugin_path]['file_count'] > 0;
    }

    /**
     * Add download and AI Changes links to plugin action links
     */
    public function add_download_link(array $actions, string $plugin_file, array $plugin_data, string $context): array {
        $plugin_folder = dirname($plugin_file);

        if ($plugin_folder === '.') {
            return $actions;
        }

        if (!$this->is_plugin_modified($plugin_folder)) {
            return $actions;
        }

        $changes_url = admin_url('tools.php?page=ai-changes&plugin=' . urlencode('plugins/' . $plugin_folder));

        $actions['ai-changes'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($changes_url),
            esc_attr__('View AI changes for this plugin', 'ai-assistant'),
            esc_html__('AI Changes', 'ai-assistant')
        );

        $download_url = wp_nonce_url(
            admin_url('admin.php?action=ai_assistant_download_plugin&plugin=' . urlencode($plugin_folder)),
            'ai_assistant_download_' . $plugin_folder
        );

        $actions['ai-download'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($download_url),
            esc_attr__('Download this plugin as a ZIP file with git history', 'ai-assistant'),
            esc_html__('Download ZIP', 'ai-assistant')
        );

        return $actions;
    }

    /**
     * Handle the plugin/theme download request
     */
    public function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download plugins.', 'ai-assistant'));
        }

        // Support both old 'plugin' param and new 'path' param (e.g., 'plugins/my-plugin' or 'themes/my-theme')
        $path = isset($_GET['path']) ? sanitize_text_field($_GET['path']) : '';
        $plugin_folder = isset($_GET['plugin']) ? sanitize_file_name($_GET['plugin']) : '';

        if (!empty($path)) {
            // New format: path includes type prefix (plugins/foo or themes/bar)
            $nonce_key = $path;
            $parts = explode('/', $path);
            if (count($parts) < 2) {
                wp_die(__('Invalid path format.', 'ai-assistant'));
            }
            $type = $parts[0];
            $folder = $parts[1];
        } elseif (!empty($plugin_folder)) {
            // Legacy format: just the plugin folder name
            $nonce_key = $plugin_folder;
            $type = 'plugins';
            $folder = $plugin_folder;
            $path = 'plugins/' . $plugin_folder;
        } else {
            wp_die(__('No plugin or theme specified.', 'ai-assistant'));
        }

        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ai_assistant_download_' . $nonce_key)) {
            wp_die(__('Security check failed.', 'ai-assistant'));
        }

        if ($type === 'plugins') {
            $full_path = WP_PLUGIN_DIR . '/' . $folder;
            $allowed_dir = realpath(WP_PLUGIN_DIR);
        } elseif ($type === 'themes') {
            $full_path = get_theme_root() . '/' . $folder;
            $allowed_dir = realpath(get_theme_root());
        } else {
            wp_die(__('Invalid type. Must be plugins or themes.', 'ai-assistant'));
        }

        if (!is_dir($full_path)) {
            wp_die(__('Directory not found.', 'ai-assistant'));
        }

        $real_path = realpath($full_path);

        if ($real_path === false || strpos($real_path, $allowed_dir) !== 0) {
            wp_die(__('Invalid path.', 'ai-assistant'));
        }

        $this->send_zip($folder, $full_path, $path);
    }

    /**
     * Create and send ZIP file
     */
    private function send_zip(string $folder_name, string $full_path, string $git_path = ''): void {
        if (!class_exists('ZipArchive')) {
            wp_die(__('ZipArchive is not available on this server.', 'ai-assistant'));
        }

        $zip_filename = $folder_name . '.zip';
        $temp_file = wp_tempnam($zip_filename);
        $temp_git_dir = null;

        $zip = new \ZipArchive();

        if ($zip->open($temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_die(__('Failed to create ZIP file.', 'ai-assistant'));
        }

        $this->add_directory_to_zip($zip, $full_path, $folder_name);

        // Build standalone .git with original files so users can run `git diff`
        $temp_git_dir = sys_get_temp_dir() . '/ai-git-' . uniqid();
        mkdir($temp_git_dir, 0755, true);

        $tracker_path = $git_path ?: 'plugins/' . $folder_name;
        if ($this->git_tracker_manager->build_standalone_git($tracker_path, $temp_git_dir)) {
            $this->add_directory_to_zip($zip, $temp_git_dir . '/.git', $folder_name . '/.git');
        }

        $zip->close();

        // Clean up temp .git directory
        if ($temp_git_dir && is_dir($temp_git_dir)) {
            $this->recursive_delete($temp_git_dir);
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_file);
        unlink($temp_file);
        exit;
    }

    /**
     * Recursively delete a directory
     */
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

    /**
     * Recursively add directory contents to ZIP
     */
    private function add_directory_to_zip(\ZipArchive $zip, string $dir, string $base_path): void {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = $base_path . '/' . substr($file_path, strlen($dir) + 1);
                $zip->addFile($file_path, $relative_path);
            }
        }
    }
}
