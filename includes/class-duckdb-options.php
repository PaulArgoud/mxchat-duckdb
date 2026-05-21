<?php
/**
 * Options storage for MxChat DuckDB.
 *
 * Single WP option (mxchat_duckdb_options) holding all settings. Mirrors the
 * structure of mxchat_pinecone_addon_options for symmetry.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Options {

    public static function defaults(): array {
        return [
            'enabled'             => false,
            'mode'                => 'motherduck',         // 'motherduck' | 'embedded'
            'motherduck_token'    => '',
            'motherduck_database' => 'my_db',
            'embedded_path'       => '',                   // resolved at runtime if empty
            'embedded_binary'     => '',                   // path to duckdb CLI, empty = autodetect
            // Local mirror for MotherDuck installs: shadows the cloud
            // database to a local `.duckdb` file with HNSW so reads run
            // fast. Only meaningful when `mode === 'motherduck'`.
            'motherduck_mirror_enabled' => false,
            'motherduck_mirror_path'    => '',             // resolved to uploads/.../mirror.duckdb when empty
            // Option B reach: mxchat-basic reads its Pinecone config directly
            // from `mxchat_pinecone_addon_options` for the default bot
            // (without the multi-bot manager loaded), bypassing the
            // `mxchat_get_bot_pinecone_config` filter. Set this to true to
            // shortcircuit reads of that option with our proxy config, so the
            // companion plugin works on stock mxchat without applying the
            // upstream patch. Explicit opt-in: writes still go to the DB, so
            // sites already using real Pinecone don't see their settings
            // hijacked.
            'takeover_default_bot_pinecone' => false,
            'table_name'          => 'mxchat_vectors',
            'embedding_dim'       => 1536,
            'distance_metric'     => 'cosine',
            'hnsw_enabled'        => true,
            'top_k'               => 50,
            'embedding_storage'   => 'float32',            // 'float32' | 'int8' (experimental)
            'hybrid_enabled'      => false,                // enable BM25 + vector hybrid scoring
            'hybrid_alpha'        => 0.7,                  // weight on vector score (1.0 = pure vector)
            'query_cache_enabled' => true,                 // cache top-K results per (embedding, filter)
            'query_cache_ttl'     => 300,                  // seconds
            'dedup_per_source'    => false,                // collapse multiple chunks from the same URL
            'slow_query_ms'       => 500,                  // log queries slower than this
            'last_sync_at'        => 0,
            'last_sync_count'     => 0,
            'last_compact_at'     => 0,
            'last_error'          => '',
        ];
    }

    public static function get(): array {
        $stored = get_option(MXCHAT_DUCKDB_OPTION_KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge(self::defaults(), $stored);
    }

    public static function update(array $patch): void {
        $current = self::get();
        $new = array_merge($current, $patch);
        update_option(MXCHAT_DUCKDB_OPTION_KEY, $new, false);
    }

    public static function install_defaults(): void {
        if (get_option(MXCHAT_DUCKDB_OPTION_KEY, null) === null) {
            update_option(MXCHAT_DUCKDB_OPTION_KEY, self::defaults(), false);
        }
    }

    /**
     * Resolve the MotherDuck token, preferring a `MXCHAT_DUCKDB_MOTHERDUCK_TOKEN`
     * constant defined in `wp-config.php` over the value persisted in
     * wp_options. The constant path exists for installs whose compliance rules
     * forbid storing secrets in the database — set it once in wp-config.php
     * and the admin UI will detect the override and disable its own token
     * field. Returns the empty string when neither source has a value.
     *
     * @return string
     */
    public static function resolved_motherduck_token(): string {
        if (defined('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN')) {
            $constant_token = (string) constant('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN');
            if ($constant_token !== '') return $constant_token;
        }
        $opts = self::get();
        return (string) ($opts['motherduck_token'] ?? '');
    }

    /**
     * True iff the active MotherDuck token comes from the wp-config constant
     * rather than the option row. Admin UI checks this to mute its own token
     * field — editing it would have no effect while the constant override is
     * in place.
     */
    public static function motherduck_token_is_from_constant(): bool {
        if (!defined('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN')) return false;
        return ((string) constant('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN')) !== '';
    }

    public static function default_embedded_path(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'mxchat-duckdb-private/store.duckdb';
    }

    public static function resolved_embedded_path(): string {
        $opts = self::get();
        $path = !empty($opts['embedded_path']) ? $opts['embedded_path'] : self::default_embedded_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::write_directory_blockers($dir);
        return $path;
    }

    public static function default_mirror_path(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'mxchat-duckdb-private/mirror.duckdb';
    }

    /**
     * Resolves the local-mirror `.duckdb` path the same way as
     * `resolved_embedded_path()` — uses the configured value when set,
     * falls back to `<uploads>/mxchat-duckdb-private/mirror.duckdb`,
     * ensures the directory exists, and writes the HTTP blockers so
     * the shadow database isn't web-reachable.
     *
     * Note the default sits next to the embedded path: this means an
     * install that toggles between modes won't accumulate orphan
     * directories. Site owners on shared hosts who keep the default
     * pay one directory for everything we persist.
     */
    public static function resolved_mirror_path(): string {
        $opts = self::get();
        $path = !empty($opts['motherduck_mirror_path']) ? $opts['motherduck_mirror_path'] : self::default_mirror_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        self::write_directory_blockers($dir);
        return $path;
    }

    /**
     * Drop .htaccess + index.php + web.config in the data directory so the
     * .duckdb file (and any companion files) cannot be fetched over HTTP.
     * Idempotent. Failed writes are logged so a misconfigured docroot doesn't
     * silently expose the vector database — the surfacing path goes through
     * PHP's error_log so site owners running uploads-not-writable setups see
     * the warning without us touching last_error (reserved for runtime errors).
     */
    public static function write_directory_blockers(string $dir): void {
        if (!is_dir($dir)) return;
        if (!is_writable($dir)) {
            error_log('[mxchat-duckdb] data directory not writable, cannot place HTTP blockers: ' . $dir);
            return;
        }

        $blockers = [
            '.htaccess' => "# Generated by mxchat-duckdb — do not edit.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n",
            'index.php' => "<?php // Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration><system.webServer><authorization>"
                . "<deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($blockers as $name => $contents) {
            $path = trailingslashit($dir) . $name;
            if (file_exists($path)) continue;
            if (@file_put_contents($path, $contents) === false) {
                error_log('[mxchat-duckdb] failed to write HTTP blocker ' . $name . ' in ' . $dir);
            }
        }
    }

    public static function sanitize_for_save(array $input): array {
        $current = self::get();
        $out = self::defaults();

        $out['enabled']             = !empty($input['enabled']);
        $out['mode']                = in_array($input['mode'] ?? '', ['motherduck', 'embedded'], true) ? $input['mode'] : 'motherduck';
        $out['motherduck_token']    = isset($input['motherduck_token']) ? trim((string) $input['motherduck_token']) : '';
        $out['motherduck_database'] = isset($input['motherduck_database'])
            ? preg_replace('/[^a-zA-Z0-9_]/', '', (string) $input['motherduck_database'])
            : 'my_db';
        if (empty($out['motherduck_database'])) $out['motherduck_database'] = 'my_db';
        $out['embedded_path']       = isset($input['embedded_path']) ? sanitize_text_field($input['embedded_path']) : '';
        $out['embedded_binary']     = isset($input['embedded_binary']) ? sanitize_text_field($input['embedded_binary']) : '';

        // Mirror toggle — only meaningful when mode === 'motherduck'.
        // We accept the input and then silently drop the toggle when
        // it would have no effect, with a settings error so the admin
        // understands why their checkbox didn't stick. The mirror path
        // is sanitised regardless; switching back to MotherDuck later
        // will pick up the existing value.
        $out['motherduck_mirror_path']    = isset($input['motherduck_mirror_path']) ? sanitize_text_field($input['motherduck_mirror_path']) : '';
        // Opt-in shortcircuit of mxchat_pinecone_addon_options reads so
        // Option B works on stock mxchat without the upstream patch and
        // without multi-bot. Pure boolean — no shape to validate.
        $out['takeover_default_bot_pinecone'] = !empty($input['takeover_default_bot_pinecone']);
        $mirror_requested = !empty($input['motherduck_mirror_enabled']);
        if ($mirror_requested && $out['mode'] !== 'motherduck') {
            add_settings_error(
                MXCHAT_DUCKDB_OPTION_KEY,
                'mirror_requires_motherduck_mode',
                __('The MotherDuck local mirror only applies when the backend is set to MotherDuck. Toggle ignored.', 'mxchat-duckdb'),
                'warning'
            );
            $out['motherduck_mirror_enabled'] = false;
        } else {
            $out['motherduck_mirror_enabled'] = $mirror_requested;
        }

        // If the admin set a custom DuckDB CLI path, probe it. A non-duckdb
        // binary still gets saved (so a typo isn't blocked) but we surface a
        // warning so they don't discover the mistake via cryptic runtime errors.
        if (!empty($out['embedded_binary']) && class_exists('MxChat_DuckDB_Embedded_Connection')) {
            if (!MxChat_DuckDB_Embedded_Connection::looks_like_duckdb_binary($out['embedded_binary'])) {
                add_settings_error(
                    MXCHAT_DUCKDB_OPTION_KEY,
                    'embedded_binary_suspect',
                    sprintf(
                        /* translators: %s = configured binary path */
                        __('The configured DuckDB CLI path (%s) did not respond to a probe query. Double-check that it points to the duckdb binary.', 'mxchat-duckdb'),
                        $out['embedded_binary']
                    ),
                    'warning'
                );
            }
        }

        $out['table_name']          = isset($input['table_name']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $input['table_name']) : 'mxchat_vectors';
        if (empty($out['table_name'])) $out['table_name'] = 'mxchat_vectors';
        $requested_dim              = max(1, (int) ($input['embedding_dim'] ?? 1536));
        $out['distance_metric']     = in_array($input['distance_metric'] ?? '', ['cosine', 'l2sq', 'ip'], true) ? $input['distance_metric'] : 'cosine';
        $out['hnsw_enabled']        = !empty($input['hnsw_enabled']);
        $out['top_k']               = max(1, min(1000, (int) ($input['top_k'] ?? 50)));
        $out['embedding_storage']   = in_array($input['embedding_storage'] ?? '', ['float32', 'int8'], true)
            ? $input['embedding_storage']
            : 'float32';
        // Same guard as embedding_dim: changing storage layout silently corrupts
        // existing vectors. Block when the table already has rows.
        if ($out['embedding_storage'] !== ($current['embedding_storage'] ?? 'float32') && $current['enabled']) {
            try {
                $store_check = new MxChat_DuckDB_Vector_Store();
                $info = $store_check->table_info();
                if ($info && $info['count'] > 0) {
                    add_settings_error(
                        MXCHAT_DUCKDB_OPTION_KEY,
                        'storage_change_blocked',
                        sprintf(
                            /* translators: %d = row count */
                            __('Cannot change embedding storage: the table already contains %d vectors. Export, wipe, and re-import to switch storage layout.', 'mxchat-duckdb'),
                            (int) $info['count']
                        ),
                        'error'
                    );
                    $out['embedding_storage'] = $current['embedding_storage'] ?? 'float32';
                }
            } catch (\Throwable $e) {
                // Brand-new install, no table yet — accept the change.
            }
        }
        $out['hybrid_enabled']      = !empty($input['hybrid_enabled']);
        $alpha                       = (float) ($input['hybrid_alpha'] ?? 0.7);
        $out['hybrid_alpha']        = max(0.0, min(1.0, $alpha));
        $out['query_cache_enabled'] = !empty($input['query_cache_enabled']);
        $out['query_cache_ttl']     = max(0, min(3600, (int) ($input['query_cache_ttl'] ?? 300)));
        $out['dedup_per_source']    = !empty($input['dedup_per_source']);
        $out['slow_query_ms']       = max(0, (int) ($input['slow_query_ms'] ?? 500));

        // Block dimension change when the table already contains vectors —
        // the column type FLOAT[<dim>] is fixed at CREATE time and a mismatch
        // breaks every subsequent insert/search. User must wipe + re-sync.
        if ($requested_dim !== (int) $current['embedding_dim'] && $current['enabled']) {
            $blocked = false;
            try {
                $store = new MxChat_DuckDB_Vector_Store();
                $info = $store->table_info();
                if ($info && $info['count'] > 0) {
                    $blocked = true;
                    add_settings_error(
                        MXCHAT_DUCKDB_OPTION_KEY,
                        'dim_change_blocked',
                        sprintf(
                            /* translators: 1: row count, 2: current dim, 3: requested dim */
                            __('Cannot change the embedding dimension: the table already contains %1$d vectors at dimension %2$d. Wipe the table and re-sync to adopt %3$d.', 'mxchat-duckdb'),
                            (int) $info['count'],
                            (int) $current['embedding_dim'],
                            (int) $requested_dim
                        ),
                        'error'
                    );
                }
            } catch (\Throwable $e) {
                // If we can't even introspect, allow the change — it's likely
                // a brand-new install where the table doesn't exist yet.
            }
            $out['embedding_dim'] = $blocked ? (int) $current['embedding_dim'] : $requested_dim;
        } else {
            $out['embedding_dim'] = $requested_dim;
        }

        // Preserve runtime telemetry fields.
        $out['last_sync_at']    = $current['last_sync_at'];
        $out['last_sync_count'] = $current['last_sync_count'];
        $out['last_compact_at'] = $current['last_compact_at'] ?? 0;
        $out['last_error']      = $current['last_error'];

        // Drop the cached connection + Vector_Store so next request picks up
        // the new config without a process reload.
        if (class_exists('MxChat_DuckDB_Connection_Factory')) {
            MxChat_DuckDB_Connection_Factory::reset_cache();
        }
        if (class_exists('MxChat_DuckDB_Vector_Store')) {
            MxChat_DuckDB_Vector_Store::reset_current();
        }

        return $out;
    }

    /**
     * Returns the active embedding model's output dimension. Prefers
     * mxchat-basic's own MxChat_Utils::embedding_model_dimensions() when the
     * companion plugin is installed (single source of truth, won't drift as
     * mxchat adds models or tweaks dimensions). Falls back to a hard-coded
     * mirror of the registry at mxchat-basic v3.x for graceful degradation
     * when the function isn't available (e.g. unit tests).
     *
     * Fallback default is 1536 (ada-002 / TE3 Small).
     */
    public static function detect_embedding_dim(): int {
        $active = get_option('mxchat_active_embedding_model', '');
        if (empty($active)) {
            $mxopts = get_option('mxchat_options', []);
            $active = $mxopts['embedding_model'] ?? 'text-embedding-ada-002';
        }

        // Single source of truth lives in mxchat-basic. Use it when possible.
        // (is_callable rather than method_exists so PHPStan + the WP stubs
        // don't narrow the second check away in static analysis.)
        if (is_callable(['MxChat_Utils', 'embedding_model_dimensions'])) {
            $dim = (int) MxChat_Utils::embedding_model_dimensions($active);
            if ($dim > 0) return $dim;
        }

        // Fallback mirror of mxchat-basic's registry (class-mxchat-utils.php).
        // Voyage-3-Large explicitly requests output_dimension=2048 in mxchat's
        // request body, so the stored dim is 2048 (not Voyage's API default).
        $known = [
            'text-embedding-ada-002'     => 1536,
            'text-embedding-3-small'     => 1536,
            'text-embedding-3-large'     => 3072,
            'voyage-3-large'             => 2048,
            'gemini-embedding-001'       => 1536,
            // Legacy / auto-migrated entries kept for installs that haven't
            // yet been upgraded by mxchat-basic's option migration:
            'voyage-3'                   => 1024,
            'voyage-3-lite'              => 512,
            'gemini-embedding-exp-03-07' => 1536, // auto-migrated to gemini-embedding-001
        ];

        return $known[$active] ?? 1536;
    }
}
