<?php
/**
 * PHPUnit bootstrap — orchestrates the shim files + production class
 * requires so the unit suite can run without booting an actual WordPress.
 *
 * Shims live under tests/shims/, grouped by responsibility:
 *
 *   wp-functions.php    — i18n, options, transients, sanitisation, plugin paths
 *   wp-classes.php      — WP_Post, WP_Query, WP_REST_Request, WP_Error
 *   ajax.php            — wp_send_json_*, check_ajax_referer, nonces, add_settings_error
 *   wpdb.php            — MxChat_Test_WPDB ($wpdb pattern-match mock)
 *   wp-cli.php          — WP_CLI + namespaced WP_CLI\Utils helpers
 *   action-scheduler.php — as_* functions backed by a fake queue
 *   mxchat.php          — MxChat_Utils + MxChat_DuckDB_Plugin stubs
 *
 * Test helpers (Connection_Factory injection, RecordingConnection,
 * memoisation resets) live under tests/helpers/test-helpers.php.
 */

if (!defined('ABSPATH'))         define('ABSPATH', __DIR__ . '/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

// ───── Plugin constants the production code expects ─────────────────────
define('MXCHAT_DUCKDB_VERSION',    'test');
define('MXCHAT_DUCKDB_DIR',        dirname(__DIR__) . '/');
define('MXCHAT_DUCKDB_FILE',       MXCHAT_DUCKDB_DIR . 'mxchat-duckdb.php');
define('MXCHAT_DUCKDB_URL',        'http://example.test/');
define('MXCHAT_DUCKDB_OPTION_KEY', 'mxchat_duckdb_options');

// ───── WordPress / Action Scheduler / WP-CLI / MxChat shims ─────────────
require_once __DIR__ . '/shims/wp-functions.php';
require_once __DIR__ . '/shims/wp-classes.php';
require_once __DIR__ . '/shims/ajax.php';
require_once __DIR__ . '/shims/wpdb.php';
require_once __DIR__ . '/shims/wp-cli.php';
require_once __DIR__ . '/shims/action-scheduler.php';
require_once __DIR__ . '/shims/mxchat.php';

// ───── Production classes ───────────────────────────────────────────────
// Avoid loading mxchat-duckdb.php itself — it calls register_*_hook on
// constants that aren't defined under PHPUnit. The classes are sufficient.
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-options.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-metrics.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-quantization.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-embedded-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-motherduck-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirrored-connection.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/trait-duckdb-sql-helpers.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-schema.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store-query.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-vector-store.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirror-bootstrap.php';
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-mirror-drain.php';
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
require_once MXCHAT_DUCKDB_DIR . 'includes/class-duckdb-cli.php';

// ───── Shared test helpers ──────────────────────────────────────────────
require_once __DIR__ . '/helpers/test-helpers.php';
