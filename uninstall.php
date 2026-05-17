<?php
/**
 * Cleanup on plugin uninstall. Runs only when the user clicks "Delete" in the
 * Plugins screen — *not* on deactivation.
 *
 * Removes:
 *   - all plugin options (settings, proxy tokens, metrics)
 *   - scheduled cron hooks (incremental sync, daily compactor)
 *   - named + wildcard transients (search error, rate-limit window, query cache)
 *   - the embedded DuckDB data directory and any custom file path the user
 *     configured (opt-in via the constant or option below).
 *
 * Preserves by default:
 *   - the .duckdb data file (it may represent hours of embedding work the
 *     user wants to keep). Set MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL = true
 *     in wp-config.php before uninstalling — or set the
 *     `mxchat_duckdb_delete_data_on_uninstall` option to a truthy value — to
 *     wipe the data too.
 *   - tables on MotherDuck. This script never makes a network call. To remove
 *     remote data, open app.motherduck.com and run
 *     `DROP TABLE IF EXISTS mxchat_vectors; DROP TABLE IF EXISTS mxchat_duckdb_schema_meta;`
 *     against the configured database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Decide once whether the user opted into data wipe. Read before options are
 * deleted, otherwise the option-based opt-in is impossible to honour.
 */
function mxchat_duckdb_uninstall_delete_data_flag(): bool {
    if (defined('MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL') && MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL) {
        return true;
    }
    return (bool) get_option('mxchat_duckdb_delete_data_on_uninstall', false);
}

/**
 * Remove every file (including dotfiles) in $dir, then rmdir() it.
 * Idempotent and silent on failure — uninstall must not crash.
 */
function mxchat_duckdb_uninstall_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    $entries = @scandir($dir);
    if (!is_array($entries)) return;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path) && !is_link($path)) {
            mxchat_duckdb_uninstall_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * Per-site cleanup. Called once on single-site, once per blog on multisite.
 */
function mxchat_duckdb_uninstall_cleanup_site(bool $delete_data): void {
    global $wpdb;

    // ── Capture configured paths before deleting the option ─────────────
    $opts = get_option('mxchat_duckdb_options', []);
    $custom_path = is_array($opts) && !empty($opts['embedded_path'])
        ? (string) $opts['embedded_path']
        : '';

    // ── Options ─────────────────────────────────────────────────────────
    // Keep this list in sync with docs/CONFIGURATION.md → Sidecar options.
    // Anything the plugin writes via update_option() must be deleted here so
    // a reinstall starts from a truly clean slate.
    $sidecar_options = [
        'mxchat_duckdb_options',                    // main settings bundle
        'mxchat_duckdb_proxy_token',                // legacy global proxy token
        'mxchat_duckdb_proxy_token_map',            // per-namespace token map
        'mxchat_duckdb_metrics',                    // rolling latency histogram + counters
        'mxchat_duckdb_cache_gen',                  // O(1) cache-invalidation counter (v0.6.0+)
        'mxchat_duckdb_reprocess_state',            // Action Scheduler reprocess snapshot (v0.4.0+)
        'mxchat_duckdb_pinecone_migration_state',   // resumable Pinecone import token (v0.4.0+)
        'mxchat_duckdb_delete_data_on_uninstall',   // opt-in flag itself
    ];
    foreach ($sidecar_options as $opt) {
        delete_option($opt);
    }

    // ── Scheduled cron ──────────────────────────────────────────────────
    wp_clear_scheduled_hook('mxchat_duckdb_incremental_sync');
    wp_clear_scheduled_hook('mxchat_duckdb_compact');

    // ── Named transient ─────────────────────────────────────────────────
    delete_transient('mxchat_duckdb_search_error');

    // ── Wildcard transients (rate-limit window + query cache) ───────────
    // The transient row name pattern is _transient_<name> and _transient_timeout_<name>.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mxchat_duckdb_rl_%' ESCAPE '\\\\'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mxchat_duckdb_rl_%' ESCAPE '\\\\'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mxd\\_q\\_%' ESCAPE '\\\\'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mxd\\_q\\_%' ESCAPE '\\\\'");

    if (!$delete_data) return;

    // ── Data directory: default location under uploads/ ─────────────────
    $upload = wp_upload_dir();
    if (is_array($upload) && !empty($upload['basedir'])) {
        $default_dir = trailingslashit($upload['basedir']) . 'mxchat-duckdb-private';
        mxchat_duckdb_uninstall_rmtree($default_dir);
    }

    // ── Data directory: user-configured custom path (if any) ────────────
    // Only delete .duckdb-companion files within that file's parent dir, not
    // the whole parent (the user might have pointed us inside a shared dir).
    if ($custom_path !== '' && file_exists($custom_path)) {
        $custom_dir = dirname($custom_path);
        $base = basename($custom_path);
        @unlink($custom_path);
        // DuckDB writes <name>.wal and may leave <name>.tmp lock files.
        foreach (['.wal', '.tmp', '.lock'] as $suffix) {
            $companion = $custom_dir . DIRECTORY_SEPARATOR . $base . $suffix;
            if (file_exists($companion)) @unlink($companion);
        }
    }
}

// ───── Entrypoint ───────────────────────────────────────────────────────

$delete_data = mxchat_duckdb_uninstall_delete_data_flag();

if (is_multisite()) {
    // Iterate every subsite — options + cron + transients live per blog.
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    if (is_array($site_ids)) {
        foreach ($site_ids as $blog_id) {
            switch_to_blog((int) $blog_id);
            mxchat_duckdb_uninstall_cleanup_site($delete_data);
            restore_current_blog();
        }
    }
    // Network-wide options would go here if we ever add `add_site_option()`
    // calls. Today the plugin doesn't use any.
} else {
    mxchat_duckdb_uninstall_cleanup_site($delete_data);
}
