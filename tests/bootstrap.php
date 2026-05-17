<?php
/**
 * PHPUnit bootstrap: loads enough WP shims to let the tests target the
 * plugin's pure-PHP utility methods without booting an actual WordPress.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// ───── Minimal WP function shims ────────────────────────────────────────

if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['__test_options'][$name] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null) {
        $GLOBALS['__test_options'][$name] = $value;
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['__test_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0) {
        $GLOBALS['__test_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['__test_transients'][$key]);
        return true;
    }
}
if (!function_exists('add_action')) {
    function add_action(...$a) {}
}
if (!function_exists('add_filter')) {
    function add_filter(...$a) {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook(...$a) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(...$a) {}
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return dirname($file) . '/'; }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file) { return 'http://example.test/'; }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file) { return basename($file); }
}

// ───── Load the plugin classes we want to test ──────────────────────────
// We avoid loading mxchat-duckdb.php itself (it calls register_*_hook on
// constants that aren't defined under PHPUnit). Just require the classes.

define('MXCHAT_DUCKDB_VERSION', 'test');
define('MXCHAT_DUCKDB_DIR', dirname(__DIR__) . '/');
define('MXCHAT_DUCKDB_FILE', MXCHAT_DUCKDB_DIR . 'mxchat-duckdb.php');
define('MXCHAT_DUCKDB_URL', 'http://example.test/');
define('MXCHAT_DUCKDB_OPTION_KEY', 'mxchat_duckdb_options');

require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-options.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-metrics.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-embedded-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-sync.php';

// Stub the Plugin class so Vector_Store::upsert()/delete_*() can call
// MxChat_DuckDB_Plugin::flush_query_cache() without booting the full plugin.
if (!class_exists('MxChat_DuckDB_Plugin')) {
    class MxChat_DuckDB_Plugin {
        public static array $flushed = [];
        public static function flush_query_cache(): void {
            self::$flushed[] = microtime(true);
        }
    }
}
