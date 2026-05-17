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
    // Pass-through by default; tests can register a static override by
    // setting $GLOBALS['__test_filter_overrides'][$hook] = $value. Useful
    // for forcing specific filter return values (e.g. capping rate-limits
    // or max-deletes) without monkey-patching individual call sites.
    function apply_filters($hook, $value, ...$args) {
        if (isset($GLOBALS['__test_filter_overrides'][$hook])) {
            return $GLOBALS['__test_filter_overrides'][$hook];
        }
        return $value;
    }
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
if (!function_exists('delete_option')) {
    function delete_option($name) {
        unset($GLOBALS['__test_options'][$name]);
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
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return is_scalar($str) ? trim(strip_tags((string) $str)) : '';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('add_settings_error')) {
    function add_settings_error($setting, $code, $message, $type = 'error') {
        $GLOBALS['__test_settings_errors'][] = compact('setting', 'code', 'message', 'type');
    }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'http://example.test/wp-json/' . ltrim((string) $path, '/');
    }
}
if (!function_exists('untrailingslashit')) {
    function untrailingslashit($string) {
        return rtrim((string) $string, '/\\');
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($string) {
        return rtrim((string) $string, '/\\') . '/';
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() {
        return ['basedir' => sys_get_temp_dir() . '/wp-uploads-test'];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($path) {
        return is_dir($path) || mkdir($path, 0755, true);
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return $GLOBALS['__test_current_user_can'] ?? true;
    }
}
if (!function_exists('register_rest_route')) {
    function register_rest_route($namespace, $route, $args = [], $override = false) {
        $key = $namespace . $route;
        if (!isset($GLOBALS['__test_rest_routes'])) {
            $GLOBALS['__test_rest_routes'] = [];
        }
        $GLOBALS['__test_rest_routes'][$key] = $args;
        return true;
    }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }
}

// Nonce shim — tests set $GLOBALS['__test_valid_nonces'] = ['nonce_value' => 'action_name'].
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action) {
        $valid = $GLOBALS['__test_valid_nonces'] ?? [];
        return isset($valid[$nonce]) && $valid[$nonce] === $action;
    }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action) {
        $n = 'nonce_' . md5($action);
        $GLOBALS['__test_valid_nonces'][$n] = $action;
        return $n;
    }
}

// Minimal $wpdb mock — records every SQL call and returns canned responses
// matched on substring patterns. Sufficient for the call surfaces our
// classes actually touch (get_var, get_results, get_col, query, prepare).
if (!class_exists('MxChat_Test_WPDB')) {
    class MxChat_Test_WPDB {
        public string $prefix  = 'wp_';
        public string $options = 'wp_options';
        /** @var string[] */
        public array $log = [];
        /** @var array<string, mixed> */
        public array $responses = [];

        public function set_response(string $sql_pattern, $value): void {
            $this->responses[$sql_pattern] = $value;
        }

        const NOT_FOUND = '__mxd_test_wpdb_not_found__';

        private function findResponse(string $sql) {
            foreach ($this->responses as $pattern => $value) {
                if (stripos($sql, $pattern) !== false) {
                    // Callable response = generator (e.g. paginate by call count).
                    return is_callable($value) ? $value($sql) : $value;
                }
            }
            return self::NOT_FOUND;
        }

        public function query(string $sql) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            return $r === self::NOT_FOUND ? 0 : ($r ?? 0);
        }

        public function get_var(string $sql) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            return $r === self::NOT_FOUND ? null : $r;
        }

        public function get_results(string $sql, $output = null) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            // No matching response → empty array (default WP behaviour for
            // "no rows"). Explicit null in the registry → null (the
            // "unreadable table" signal real $wpdb uses on errors).
            if ($r === self::NOT_FOUND) return [];
            return $r;
        }

        public function get_col(string $sql, $col_offset = 0) {
            $this->log[] = $sql;
            $r = $this->findResponse($sql);
            if ($r === self::NOT_FOUND) return [];
            return is_array($r) ? $r : [];
        }

        /** Minimal sprintf-shaped prepare — covers %s, %d, %f, %i. */
        public function prepare(string $sql, ...$args) {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback('/%[sdfiF]/', function ($m) use (&$i, $args) {
                $v = $args[$i++] ?? null;
                if (is_int($v) || is_float($v)) return (string) $v;
                if (is_null($v))                return 'NULL';
                return "'" . str_replace("'", "''", (string) $v) . "'";
            }, $sql);
        }
    }
}

// Minimal WP_REST_Request stub — covers the surface that Pinecone_Proxy::check_token
// and the handlers actually exercise.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params = [];
        private array $query = [];
        private ?array $json = null;

        public function set_header(string $name, ?string $value): void {
            $this->headers[strtolower($name)] = $value;
        }
        public function get_header(string $name): ?string {
            return $this->headers[strtolower($name)] ?? null;
        }
        public function set_param(string $name, $value): void {
            $this->params[$name] = $value;
        }
        public function get_param(string $name) {
            return $this->params[$name] ?? null;
        }
        public function set_json_params(?array $body): void {
            $this->json = $body;
        }
        public function get_json_params(): ?array {
            return $this->json;
        }
        public function set_query_params(array $params): void {
            $this->query = $params;
        }
        public function get_query_params(): array {
            return $this->query;
        }
    }
}

// Minimal WP_REST_Response stub.
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public int $status;
        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
    }
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
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-quantization.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-embedded-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/trait-duckdb-sql-helpers.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-schema.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-query.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mysql-sync.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-post-reprocessor.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-sync.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-search-adapter.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-pinecone-proxy.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-pinecone-migrator.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-compactor.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-health.php';

// Stub the Plugin class so Vector_Store::upsert()/delete_*() can call
// MxChat_DuckDB_Plugin::flush_query_cache() without booting the full plugin.
if (!class_exists('MxChat_DuckDB_Plugin')) {
    class MxChat_DuckDB_Plugin {
        public static array $flushed = [];
        public static int $cache_gen = 1;
        public static function flush_query_cache(): void {
            self::$flushed[] = microtime(true);
            self::bump_cache_generation();
        }
        public static function cache_generation(): int {
            return self::$cache_gen;
        }
        public static function bump_cache_generation(): void {
            self::$cache_gen++;
        }
    }
}
