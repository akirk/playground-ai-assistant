<?php
/**
 * PHPUnit bootstrap file
 *
 * Sets up WordPress stubs and loads the plugin classes for testing.
 * No Composer autoloader required - classes are loaded manually.
 */

// Define WordPress constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-content-test-' . getmypid());
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

// Create test directory structure
if (!is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0755, true);
}
if (!is_dir(WP_PLUGIN_DIR)) {
    mkdir(WP_PLUGIN_DIR, 0755, true);
}

// WordPress function stubs for testing
if (!function_exists('get_option')) {
    $GLOBALS['wp_test_options'] = [];
    function get_option($option, $default = false) {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        $GLOBALS['wp_test_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('get_theme_root')) {
    function get_theme_root() {
        return WP_CONTENT_DIR . '/themes';
    }
}

// Create themes directory
if (!is_dir(WP_CONTENT_DIR . '/themes')) {
    mkdir(WP_CONTENT_DIR . '/themes', 0755, true);
}

// Manual class loading (no Composer autoloader)
$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/includes/class-tools.php';
require_once $plugin_dir . '/includes/class-executor.php';
require_once $plugin_dir . '/includes/class-git-tracker.php';
require_once $plugin_dir . '/includes/class-git-tracker-manager.php';
