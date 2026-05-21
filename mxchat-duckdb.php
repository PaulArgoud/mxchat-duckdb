<?php
/**
 * Plugin Name: MxChat DuckDB / MotherDuck
 * Plugin URI: https://github.com/paulargoud/mxchat-duckdb
 * Description: Adds DuckDB (embedded) and MotherDuck (cloud) as alternative vector stores for MxChat, replacing Pinecone with an open-source, SQL-native option.
 * Version: 0.10.1
 * Author: Paul Argoud
 * License: GPLv2 or later
 * Text Domain: mxchat-duckdb
 * Domain Path: /languages
 * Requires PHP: 8.0
 * Requires at least: 6.0
 *
 * Companion plugin to MxChat (https://mxchat.ai/). Provides two integration paths:
 *   - Option A (preferred): hooks the `mxchat_pre_vector_query` filter
 *     (WordPress-canonical `pre_*` short-circuit convention) to bypass the
 *     Pinecone HTTP call with native DuckDB SQL queries. The legacy
 *     `mxchat_pinecone_matches_override` filter is also hooked for installs
 *     that applied the older patch. Requires a small patch to mxchat-basic
 *     (see patches/ folder).
 *   - Option B (no patch needed): exposes a REST endpoint that emulates the
 *     Pinecone wire protocol; mxchat thinks it's talking to Pinecone.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MXCHAT_DUCKDB_VERSION', '0.10.1');
define('MXCHAT_DUCKDB_FILE', __FILE__);
define('MXCHAT_DUCKDB_DIR', plugin_dir_path(__FILE__));
define('MXCHAT_DUCKDB_URL', plugin_dir_url(__FILE__));
define('MXCHAT_DUCKDB_OPTION_KEY', 'mxchat_duckdb_options');

// Prefer Composer's autoloader when installed (`composer install`), otherwise
// fall back to manual require_once. The plugin runs identically either way.
if (file_exists(MXCHAT_DUCKDB_DIR . 'vendor/autoload.php')) {
    require_once MXCHAT_DUCKDB_DIR . 'vendor/autoload.php';
} else {
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-options.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-metrics.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-quantization.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-connection.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-motherduck-connection.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-embedded-connection.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirrored-connection.php';
    // Vector store: shared trait + schema/query split + orchestrator facade.
    require_once MXCHAT_DUCKDB_DIR . 'includes/trait-duckdb-sql-helpers.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-schema.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-query.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store.php';
    // Mirror bootstrap relies on the trait + Vector_Store_Schema, so it
    // has to load AFTER both. Sits next to the wrapper conceptually but
    // the dependency order matters at parse time.
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirror-bootstrap.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirror-drain.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirror-drift-check.php';
    // Ingestion pipelines: MySQL sync + WP post reprocessor + sync facade.
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mysql-sync.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-post-reprocessor.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-sync.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-async-reprocess.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-pinecone-migrator.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-compactor.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-pinecone-proxy.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-search-adapter.php';
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-health.php';

    if (defined('WP_CLI') && WP_CLI) {
        require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-cli.php';
    }
}

if (is_admin()) {
    require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-admin.php';
}

register_activation_hook(__FILE__, ['MxChat_DuckDB_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['MxChat_DuckDB_Plugin', 'deactivate']);

add_action('plugins_loaded', ['MxChat_DuckDB_Plugin', 'load_textdomain']);
add_action('plugins_loaded', ['MxChat_DuckDB_Plugin', 'init'], 20);

class MxChat_DuckDB_Plugin {

    public static function load_textdomain() {
        load_plugin_textdomain(
            'mxchat-duckdb',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    public static function init() {
        if (!self::mxchat_is_active()) {
            add_action('admin_notices', [__CLASS__, 'render_mxchat_missing_notice']);
            return;
        }

        // Search adapter: registers the Option A filter and the Option B Pinecone config filter.
        MxChat_DuckDB_Search_Adapter::instance()->register_hooks();

        // Pinecone proxy: registers REST routes (Option B fallback path).
        MxChat_DuckDB_Pinecone_Proxy::instance()->register_routes();

        // Health endpoint (read-only, public).
        MxChat_DuckDB_Health::instance()->register_routes();

        // Sync: incremental hooks on KB ingestion/deletion.
        MxChat_DuckDB_Sync::instance()->register_hooks();

        // Async reprocess: Action Scheduler worker hook.
        MxChat_DuckDB_Async_Reprocess::instance()->register_hooks();

        // Compactor: daily orphan-vector pruning.
        MxChat_DuckDB_Compactor::instance()->register_hooks();

        // Mirror bootstrap: Action Scheduler worker that walks the
        // MotherDuck → local copy in batches. Hook is registered
        // unconditionally; the worker itself short-circuits when the
        // mirror is disabled, so toggling on later doesn't need a
        // plugin reload.
        if (class_exists('MxChat_DuckDB_Mirror_Bootstrap')) {
            MxChat_DuckDB_Mirror_Bootstrap::instance()->register_hooks();

            // Mirror drain: 5-min recurring Action Scheduler tick that
            // replays failed local writes from `mirror_pending`. Always
            // registered; the worker is a no-op when the mirror is
            // disabled. The recurring schedule is created at most once
            // (AS dedupes via the hook+group key).
            if (class_exists('MxChat_DuckDB_Mirror_Drain')) {
                MxChat_DuckDB_Mirror_Drain::instance()->register_hooks();
            }

            // Mirror drift check: 24h recurring Action Scheduler tick
            // that compares (count, vector_id-set hash) per bot_id
            // between primary and local. Detection only in v1; admin
            // sees STATUS_DRIFTED + a CLI note when divergence is real.
            if (class_exists('MxChat_DuckDB_Mirror_Drift_Check')) {
                MxChat_DuckDB_Mirror_Drift_Check::instance()->register_hooks();
            }

            // Trigger a bootstrap when the admin transitions the
            // mirror toggle from off → on. Listening on update_option_*
            // (which fires AFTER the option save) means we observe
            // the post-sanitiser state, so a toggle that was rejected
            // by the sanitiser doesn't accidentally fire.
            add_action('update_option_' . MXCHAT_DUCKDB_OPTION_KEY, function ($old, $new) {
                $was = is_array($old) && !empty($old['motherduck_mirror_enabled']);
                $now = is_array($new) && !empty($new['motherduck_mirror_enabled']);
                if (!$was && $now) {
                    MxChat_DuckDB_Mirror_Bootstrap::start();
                }
            }, 10, 2);
        }

        if (is_admin()) {
            MxChat_DuckDB_Admin::instance()->register_hooks();
        }
    }

    const CACHE_GEN_OPTION = 'mxchat_duckdb_cache_gen';

    /**
     * Current cache generation. Read on the hot path by Vector_Store_Query to
     * compose the transient key; an upsert/delete bumps it via
     * bump_cache_generation() so existing transients become unreachable in
     * O(1) instead of a LIKE DELETE over wp_options. Orphans expire by TTL.
     */
    public static function cache_generation(): int {
        $g = (int) get_option(self::CACHE_GEN_OPTION, 1);
        return $g > 0 ? $g : 1;
    }

    public static function bump_cache_generation(): void {
        $next = self::cache_generation() + 1;
        update_option(self::CACHE_GEN_OPTION, $next, false);
    }

    /**
     * Back-compat alias kept so existing call-sites (Vector_Store writes,
     * tests) keep compiling. New code should call bump_cache_generation()
     * directly — the semantics are identical.
     */
    public static function flush_query_cache(): void {
        self::bump_cache_generation();
    }

    public static function activate() {
        MxChat_DuckDB_Options::install_defaults();

        // Ensure the proxy token exists at activation so Option B works from
        // the first page load (it was lazily generated when the admin page
        // was opened, which left a race window for mxchat-driven requests).
        MxChat_DuckDB_Pinecone_Proxy::get_or_create_token();

        // Try to provision the schema if a backend is already configured. Silent on failure —
        // user will see backend errors in the admin UI when they configure it.
        $opts = MxChat_DuckDB_Options::get();
        if (!empty($opts['enabled'])) {
            try {
                $store = new MxChat_DuckDB_Vector_Store();
                $store->ensure_schema();
            } catch (\Throwable $e) {
                // Swallow — activation must not fail.
            }
        }
    }

    public static function deactivate() {
        // Stop scheduled work so nothing keeps firing against a disabled plugin.
        wp_clear_scheduled_hook(MxChat_DuckDB_Sync::CRON_HOOK);
        wp_clear_scheduled_hook(MxChat_DuckDB_Compactor::CRON_HOOK);

        // Action-Scheduler-managed mirror work too. We catch the
        // function_exists guards to keep this safe when the plugin is
        // deactivated during a state where Action Scheduler isn't
        // loaded yet (rare but possible on multisite cleanups).
        if (function_exists('as_unschedule_all_actions') && class_exists('MxChat_DuckDB_Mirror_Drain')) {
            as_unschedule_all_actions(MxChat_DuckDB_Mirror_Drain::ACTION_HOOK, [], MxChat_DuckDB_Mirror_Drain::ACTION_GROUP);
        }
        if (function_exists('as_unschedule_all_actions') && class_exists('MxChat_DuckDB_Mirror_Bootstrap')) {
            as_unschedule_all_actions(MxChat_DuckDB_Mirror_Bootstrap::ACTION_HOOK, [], MxChat_DuckDB_Mirror_Bootstrap::ACTION_GROUP);
        }
        if (function_exists('as_unschedule_all_actions') && class_exists('MxChat_DuckDB_Mirror_Drift_Check')) {
            as_unschedule_all_actions(MxChat_DuckDB_Mirror_Drift_Check::ACTION_HOOK, [], MxChat_DuckDB_Mirror_Drift_Check::ACTION_GROUP);
        }
    }

    public static function mxchat_is_active() {
        // mxchat-basic exposes either a class or several functions; either signal is fine.
        return class_exists('MxChat_Integrator') || function_exists('mxchat_activate');
    }

    public static function render_mxchat_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>MxChat DuckDB</strong>: ';
        echo esc_html__('The MxChat plugin (mxchat-basic) must be installed and activated for this companion plugin to work.', 'mxchat-duckdb');
        echo '</p></div>';
    }
}
