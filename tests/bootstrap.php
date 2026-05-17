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
if (!function_exists('wp_doing_cron')) {
    function wp_doing_cron() {
        return defined('DOING_CRON') && DOING_CRON;
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($string, $remove_breaks = false) {
        $string = (string) $string;
        $string = preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $string);
        $string = strip_tags($string);
        if ($remove_breaks) {
            $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
        }
        return trim($string);
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        return $GLOBALS['__test_posts'][(int) $id] ?? null;
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($id) {
        return $GLOBALS['__test_permalinks'][(int) $id] ?? false;
    }
}

// Minimal WP_Query stub — covers ->posts (array), ->found_posts (int).
if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];
        public int $found_posts = 0;
        public function __construct(array $args = []) {
            $matcher = $GLOBALS['__test_wp_query_matcher'] ?? null;
            if (is_callable($matcher)) {
                $result = $matcher($args);
                $this->posts       = $result['posts']       ?? [];
                $this->found_posts = $result['found_posts'] ?? count($this->posts);
            }
        }
    }
}

// WP_Post stub — used by post_reprocessor and other places that need a
// post object. Tests just construct via (object)['…' => …]; this class
// gives a real type hint to function signatures that require WP_Post.
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public function __construct(array $props = []) {
            foreach ($props as $k => $v) { $this->$k = $v; }
        }
    }
}

// Action Scheduler shims. Tests register a fake "queue" in
// $GLOBALS['__test_as_queue'] and assertions look at it.
if (!function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action($hook, $args = [], $group = '') {
        if (!isset($GLOBALS['__test_as_queue'])) $GLOBALS['__test_as_queue'] = [];
        $id = count($GLOBALS['__test_as_queue']) + 1;
        $GLOBALS['__test_as_queue'][$id] = compact('hook', 'args', 'group') + ['status' => 'pending'];
        return $id;
    }
}
if (!function_exists('as_get_scheduled_actions')) {
    function as_get_scheduled_actions(array $args = [], $return = '') {
        $queue = $GLOBALS['__test_as_queue'] ?? [];
        $filtered = array_filter($queue, function ($a) use ($args) {
            if (isset($args['hook'])   && $a['hook']   !== $args['hook'])   return false;
            if (isset($args['status']) && $a['status'] !== $args['status']) return false;
            if (isset($args['args'])   && $a['args']   !== $args['args'])   return false;
            return true;
        });
        if ($return === 'ids')   return array_keys($filtered);
        if ($return === 'count') return count($filtered);
        return $filtered;
    }
}
if (!function_exists('as_unschedule_all_actions')) {
    function as_unschedule_all_actions($hook = '', $args = [], $group = '') {
        $count = 0;
        $queue = $GLOBALS['__test_as_queue'] ?? [];
        foreach ($queue as $id => $a) {
            if (($hook === '' || $a['hook'] === $hook) && $a['status'] === 'pending') {
                $GLOBALS['__test_as_queue'][$id]['status'] = 'cancelled';
                $count++;
            }
        }
        return $count;
    }
}

// Minimal MxChat_Utils stub for post_reprocessor — production code calls
// submit_content_to_db() which we record into a log so the test can verify
// (and optionally fail via WP_Error).
if (!class_exists('MxChat_Utils')) {
    class MxChat_Utils {
        public static array $submit_calls = [];
        /** @var mixed Set to a WP_Error to force a failure path. */
        public static $submit_returns = true;
        public static function submit_content_to_db(
            $content, $source_url, $api_key, $vector_id = null,
            $bot_id = 'default', $content_type = 'content'
        ) {
            self::$submit_calls[] = compact('content', 'source_url', 'api_key', 'vector_id', 'bot_id', 'content_type');
            return self::$submit_returns;
        }
        public static function embedding_model_dimensions($model) {
            $known = [
                'text-embedding-ada-002' => 1536,
                'text-embedding-3-small' => 1536,
                'text-embedding-3-large' => 3072,
                'voyage-3-large'         => 2048,
                'gemini-embedding-001'   => 1536,
            ];
            return $known[$model] ?? 0;
        }
    }
}

// Minimal WP_Error stub — production code checks is_wp_error($result).
if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $message;
        public function __construct($code = '', string $message = '') {
            $this->message = $message;
        }
        public function get_error_message(): string { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return $thing instanceof WP_Error; }
}

// Minimal AJAX response helpers — production handlers call wp_send_json_*
// which would normally die(). For tests we capture the payload and throw a
// recognisable exception so the caller can assert.
if (!class_exists('MxChat_Test_AjaxResponseException')) {
    class MxChat_Test_AjaxResponseException extends Exception {
        public $payload;
        public bool $success;
        public ?int $status_code;
        public function __construct(bool $success, $payload, ?int $status_code = null) {
            // Use the payload's message field as the exception message when
            // available, so production code that re-throws via
            // `catch (\Throwable $e) { wp_send_json_error(['message' => $e->getMessage()]) }`
            // still surfaces the original message to the test.
            $msg = 'ajax response (' . ($success ? 'success' : 'error') . ')';
            if (is_array($payload) && isset($payload['message']) && is_scalar($payload['message'])) {
                $msg = (string) $payload['message'];
            }
            parent::__construct($msg);
            $this->success     = $success;
            $this->payload     = $payload;
            $this->status_code = $status_code;
        }
    }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) {
        // The first response wins. Production handlers wrap their work in
        // `try { …; wp_send_json_success() } catch (\Throwable $e) {
        //     wp_send_json_error($e->getMessage()) }`. Our shim's
        // exception is itself a Throwable and gets caught by that block,
        // which then "reports the error" with our success-shim exception's
        // message — wrecking the test. Stashing the first response in a
        // global lets the test see the original intent regardless of what
        // production does with the resulting exception.
        if (!isset($GLOBALS['__test_ajax_response'])) {
            $GLOBALS['__test_ajax_response'] = ['success' => true, 'payload' => $data, 'status' => $status_code];
        }
        throw new MxChat_Test_AjaxResponseException(true, $data, $status_code);
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) {
        if (!isset($GLOBALS['__test_ajax_response'])) {
            $GLOBALS['__test_ajax_response'] = ['success' => false, 'payload' => $data, 'status' => $status_code];
        }
        throw new MxChat_Test_AjaxResponseException(false, $data, $status_code);
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $query_arg = '_ajax_nonce', $die = true) {
        $nonce = $_POST[$query_arg] ?? $_REQUEST[$query_arg] ?? '';
        $valid = $GLOBALS['__test_valid_nonces'] ?? [];
        $ok = isset($valid[$nonce]) && $valid[$nonce] === $action;
        if (!$ok && $die) {
            // Mimic the wp_send_json_error shim's "first response wins"
            // behaviour so AdminAjaxTest::captureAjaxResponse() can see it.
            if (!isset($GLOBALS['__test_ajax_response'])) {
                $GLOBALS['__test_ajax_response'] = ['success' => false, 'payload' => ['code' => 'invalid_nonce'], 'status' => 403];
            }
            throw new MxChat_Test_AjaxResponseException(false, ['code' => 'invalid_nonce'], 403);
        }
        return $ok;
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
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-motherduck-connection.php';
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
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-async-reprocess.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-admin.php';

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
