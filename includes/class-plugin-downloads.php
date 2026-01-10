<?php
namespace AI_Assistant;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Downloads - Adds download ZIP links for AI-modified plugins
 */
class Plugin_Downloads {

    public function __construct() {
        add_filter('plugin_action_links', [$this, 'add_download_link'], 10, 4);
        add_action('admin_action_ai_assistant_download_plugin', [$this, 'handle_download']);
    }

    /**
     * Add download link to plugin action links
     */
    public function add_download_link(array $actions, string $plugin_file, array $plugin_data, string $context): array {
        // Get the plugin folder
        $plugin_folder = dirname($plugin_file);

        // For single-file plugins, the folder would be "."
        if ($plugin_folder === '.') {
            return $actions;
        }

        // Check if this plugin was modified by AI Assistant
        $tracked = get_option('ai_assistant_modified_plugins', []);

        if (!in_array($plugin_folder, $tracked)) {
            return $actions;
        }

        // Add download link
        $download_url = wp_nonce_url(
            admin_url('admin.php?action=ai_assistant_download_plugin&plugin=' . urlencode($plugin_folder)),
            'ai_assistant_download_' . $plugin_folder
        );

        $actions['ai-download'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url($download_url),
            esc_attr__('Download this plugin as a ZIP file', 'ai-assistant'),
            esc_html__('Download ZIP', 'ai-assistant')
        );

        return $actions;
    }

    /**
     * Handle the plugin download request
     */
    public function handle_download(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download plugins.', 'ai-assistant'));
        }

        $plugin_folder = isset($_GET['plugin']) ? sanitize_file_name($_GET['plugin']) : '';

        if (empty($plugin_folder)) {
            wp_die(__('No plugin specified.', 'ai-assistant'));
        }

        // Verify nonce
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'ai_assistant_download_' . $plugin_folder)) {
            wp_die(__('Security check failed.', 'ai-assistant'));
        }

        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_folder;

        if (!is_dir($plugin_path)) {
            wp_die(__('Plugin not found.', 'ai-assistant'));
        }

        // Security: ensure the path is within plugins directory
        $real_plugin_path = realpath($plugin_path);
        $real_plugins_dir = realpath(WP_PLUGIN_DIR);

        if ($real_plugin_path === false || strpos($real_plugin_path, $real_plugins_dir) !== 0) {
            wp_die(__('Invalid plugin path.', 'ai-assistant'));
        }

        $this->send_zip($plugin_folder, $plugin_path);
    }

    /**
     * Create and send ZIP file
     */
    private function send_zip(string $plugin_folder, string $plugin_path): void {
        if (!class_exists('ZipArchive')) {
            wp_die(__('ZipArchive is not available on this server.', 'ai-assistant'));
        }

        $zip_filename = $plugin_folder . '.zip';
        $temp_file = wp_tempnam($zip_filename);

        $zip = new \ZipArchive();

        if ($zip->open($temp_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_die(__('Failed to create ZIP file.', 'ai-assistant'));
        }

        $this->add_directory_to_zip($zip, $plugin_path, $plugin_folder);
        $zip->close();

        // Send the file
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
