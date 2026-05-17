<?php
/**
 * Admin UI: settings page + AJAX endpoints for connection test and sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Admin {

    private static ?self $instance = null;

    const MENU_SLUG = 'mxchat-duckdb';
    const NONCE_ACTION = 'mxchat_duckdb_admin';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_mxchat_duckdb_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_mxchat_duckdb_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_mxchat_duckdb_stats', [$this, 'ajax_stats']);
        add_action('wp_ajax_mxchat_duckdb_reprocess_batch', [$this, 'ajax_reprocess_batch']);
    }

    public function register_menu(): void {
        // Add as a submenu under the main mxchat menu if it exists, else top-level.
        $parent = $this->detect_mxchat_parent_slug();
        if ($parent) {
            add_submenu_page(
                $parent,
                __('DuckDB / MotherDuck', 'mxchat-duckdb'),
                __('DuckDB / MotherDuck', 'mxchat-duckdb'),
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_page']
            );
        } else {
            add_menu_page(
                __('MxChat DuckDB', 'mxchat-duckdb'),
                __('MxChat DuckDB', 'mxchat-duckdb'),
                'manage_options',
                self::MENU_SLUG,
                [$this, 'render_page'],
                'dashicons-database'
            );
        }
    }

    public function register_settings(): void {
        register_setting(self::MENU_SLUG, MXCHAT_DUCKDB_OPTION_KEY, [
            'sanitize_callback' => [MxChat_DuckDB_Options::class, 'sanitize_for_save'],
        ]);
    }

    public function enqueue_assets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false) return;

        wp_enqueue_script(
            'mxchat-duckdb-admin',
            MXCHAT_DUCKDB_URL . 'assets/admin.js',
            ['jquery'],
            MXCHAT_DUCKDB_VERSION,
            true
        );

        wp_localize_script('mxchat-duckdb-admin', 'mxchatDuckDB', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'i18n'    => [
                'testing'           => __('Testing…', 'mxchat-duckdb'),
                'syncing'           => __('Syncing…', 'mxchat-duckdb'),
                'ok'                => __('OK', 'mxchat-duckdb'),
                'error'             => __('Error', 'mxchat-duckdb'),
                'syncComplete'      => __('Sync complete', 'mxchat-duckdb'),
                /* translators: short suffix shown after a vector count in admin status messages */
                'vectorsSuffix'     => __('vectors', 'mxchat-duckdb'),
                /* translators: 1: done count, 2: total count */
                'reprocessing'      => __('Reprocessing %1$d / %2$d…', 'mxchat-duckdb'),
                /* translators: 1: processed count, 2: failed count */
                'reprocessComplete' => __('Reprocess complete: %1$d processed, %2$d failed.', 'mxchat-duckdb'),
                'confirmReprocess'  => __('This will call the embedding API for every post (potential cost). Continue?', 'mxchat-duckdb'),
            ],
        ]);
    }

    public function render_page(): void {
        $opts = MxChat_DuckDB_Options::get();
        $proxy_token = MxChat_DuckDB_Pinecone_Proxy::get_or_create_token();
        $proxy_host = MxChat_DuckDB_Pinecone_Proxy::pinecone_host();
        $detected_dim = MxChat_DuckDB_Options::detect_embedding_dim();

        $view = MXCHAT_DUCKDB_DIR . 'admin/views/settings.php';
        if (file_exists($view)) {
            include $view;
        }
    }

    public function ajax_test_connection(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-duckdb')], 403);
        }

        try {
            $conn = MxChat_DuckDB_Connection_Factory::current();
            $ok = $conn->ping();
            if (!$ok) {
                wp_send_json_error(['message' => __('Ping failed.', 'mxchat-duckdb')]);
            }

            $store = new MxChat_DuckDB_Vector_Store($conn);
            $store->ensure_schema();
            $count = $store->count();

            wp_send_json_success([
                'backend' => $conn->identifier(),
                'count'   => $count,
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_sync_now(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-duckdb')], 403);
        }

        try {
            $count = MxChat_DuckDB_Sync::instance()->full_sync();
            wp_send_json_success([
                'synced' => $count,
                'at'     => time(),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_reprocess_batch(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-duckdb')], 403);
        }

        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $batch_size = isset($_POST['batch_size']) ? max(1, min(50, (int) $_POST['batch_size'])) : 10;
        $post_types_raw = isset($_POST['post_types']) ? (string) wp_unslash($_POST['post_types']) : 'post,page';
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];

        try {
            $result = MxChat_DuckDB_Sync::instance()->reprocess_posts($post_types, $batch_size, $offset);
            wp_send_json_success($result);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_stats(): void {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'mxchat-duckdb')], 403);
        }

        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $count = $store->count();
            $opts = MxChat_DuckDB_Options::get();
            wp_send_json_success([
                'count'         => $count,
                'last_sync_at'  => $opts['last_sync_at'],
                'last_error'    => $opts['last_error'],
                'embedding_dim' => $opts['embedding_dim'],
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function detect_mxchat_parent_slug(): ?string {
        // mxchat-basic registers its top-level page under "mxchat" or similar.
        // Detection is best-effort — we look at $GLOBALS['admin_page_hooks'].
        global $admin_page_hooks;
        if (is_array($admin_page_hooks)) {
            foreach ($admin_page_hooks as $slug => $hook) {
                if (stripos($slug, 'mxchat') === 0) {
                    return $slug;
                }
            }
        }
        return null;
    }
}
