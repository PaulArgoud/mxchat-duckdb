<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks MxChat_DuckDB_Options::sanitize_for_save — the gatekeeper between
 * the admin form and what actually lands in wp_options.
 *
 * The sanitiser has three jobs:
 *   1. Coerce + clamp every input (enum allowlists, regex strips, numeric
 *      bounds). A bad form value must never blow up the persistence layer.
 *   2. Preserve runtime telemetry the admin form doesn't render
 *      (last_sync_at, last_sync_count, last_error, last_compact_at).
 *   3. Refuse changes that would silently corrupt existing data
 *      (embedding_dim, embedding_storage when the table has rows).
 *
 * The destructive-change guards instantiate Vector_Store and read
 * table_info(); in unit-test land that call raises (no real connection),
 * and the sanitiser catches and accepts the change ("brand-new install"
 * fallback). That branch is fine to lean on here — testing the live-guard
 * branch would require an integration test against a real DuckDB.
 */
final class OptionsSanitizeTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options']         = [];
        $GLOBALS['__test_transients']      = [];
        $GLOBALS['__test_settings_errors'] = [];
    }

    private function sanitize(array $input): array {
        return MxChat_DuckDB_Options::sanitize_for_save($input);
    }

    // ─── Defaults & full empty submission ─────────────────────────────────

    public function test_empty_input_applies_safe_defaults(): void {
        $out = $this->sanitize([]);

        $this->assertFalse($out['enabled']);
        $this->assertSame('motherduck', $out['mode']);
        $this->assertSame('my_db', $out['motherduck_database']);
        $this->assertSame('mxchat_vectors', $out['table_name']);
        $this->assertSame(1536, $out['embedding_dim']);
        $this->assertSame('cosine', $out['distance_metric']);
        $this->assertSame('float32', $out['embedding_storage']);
        $this->assertSame(50, $out['top_k']);
        $this->assertEqualsWithDelta(0.7, $out['hybrid_alpha'], 1e-9);
        $this->assertSame(300, $out['query_cache_ttl']);
        $this->assertSame(500, $out['slow_query_ms']);
    }

    // ─── Enum allowlists ──────────────────────────────────────────────────

    public function test_mode_outside_allowlist_falls_back_to_motherduck(): void {
        $this->assertSame('motherduck', $this->sanitize(['mode' => 'redis'])['mode']);
        $this->assertSame('embedded',   $this->sanitize(['mode' => 'embedded'])['mode']);
        $this->assertSame('motherduck', $this->sanitize(['mode' => 'motherduck'])['mode']);
    }

    public function test_distance_metric_outside_allowlist_falls_back_to_cosine(): void {
        $this->assertSame('cosine', $this->sanitize(['distance_metric' => 'manhattan'])['distance_metric']);
        $this->assertSame('l2sq',   $this->sanitize(['distance_metric' => 'l2sq'])['distance_metric']);
        $this->assertSame('ip',     $this->sanitize(['distance_metric' => 'ip'])['distance_metric']);
    }

    public function test_embedding_storage_outside_allowlist_falls_back_to_float32(): void {
        $this->assertSame('float32', $this->sanitize(['embedding_storage' => 'float16'])['embedding_storage']);
        $this->assertSame('int8',    $this->sanitize(['embedding_storage' => 'int8'])['embedding_storage']);
    }

    // ─── Regex sanitisation (SQL-injection hardening) ─────────────────────

    public function test_table_name_strips_anything_not_alphanumeric_or_underscore(): void {
        // The table name is interpolated into SQL via quote_ident — the
        // sanitiser is the first line of defence even though quote_ident
        // also strips. Belt-and-braces.
        // The sanitiser strips disallowed chars (regex [^a-zA-Z0-9_]); it
        // does NOT translate them. "my-table" loses the dash and becomes
        // "mytable", not "my_table".
        $cases = [
            ['mxchat_vectors',                        'mxchat_vectors'],
            ['vectors; DROP TABLE users;--',           'vectorsDROPTABLEusers'],
            ['my-table',                                'mytable'],
            ['très_unicode',                            'trs_unicode'],
            ['',                                        'mxchat_vectors'], // empty → default
            ['___',                                     '___'],
        ];
        foreach ($cases as [$input, $expected]) {
            $this->assertSame($expected, $this->sanitize(['table_name' => $input])['table_name'],
                "input '$input' should sanitise to '$expected'");
        }
    }

    public function test_motherduck_database_strips_special_chars(): void {
        // motherduck_database is interpolated into the ATTACH literal. Same
        // care as table_name — strips, not replaces.
        $this->assertSame('my_db', $this->sanitize(['motherduck_database' => "my_db'; --"])['motherduck_database']);
        $this->assertSame('productionkb', $this->sanitize(['motherduck_database' => 'production-kb'])['motherduck_database']);
        // empty → default
        $this->assertSame('my_db', $this->sanitize(['motherduck_database' => ''])['motherduck_database']);
    }

    // ─── Numeric clamps ───────────────────────────────────────────────────

    public function test_top_k_clamped_to_1_1000(): void {
        $this->assertSame(1,    $this->sanitize(['top_k' => -50])['top_k']);
        $this->assertSame(1,    $this->sanitize(['top_k' => 0])['top_k']);
        $this->assertSame(50,   $this->sanitize(['top_k' => 50])['top_k']);
        $this->assertSame(1000, $this->sanitize(['top_k' => 9999])['top_k']);
    }

    public function test_hybrid_alpha_clamped_to_0_1(): void {
        $this->assertEqualsWithDelta(0.0, $this->sanitize(['hybrid_alpha' => -0.5])['hybrid_alpha'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->sanitize(['hybrid_alpha' => 1.5])['hybrid_alpha'], 1e-9);
        $this->assertEqualsWithDelta(0.42, $this->sanitize(['hybrid_alpha' => 0.42])['hybrid_alpha'], 1e-9);
    }

    public function test_query_cache_ttl_clamped_to_0_3600(): void {
        $this->assertSame(0,    $this->sanitize(['query_cache_ttl' => -1])['query_cache_ttl']);
        $this->assertSame(3600, $this->sanitize(['query_cache_ttl' => 999999])['query_cache_ttl']);
        $this->assertSame(120,  $this->sanitize(['query_cache_ttl' => 120])['query_cache_ttl']);
    }

    public function test_slow_query_ms_floored_at_zero(): void {
        $this->assertSame(0,   $this->sanitize(['slow_query_ms' => -50])['slow_query_ms']);
        $this->assertSame(500, $this->sanitize(['slow_query_ms' => 500])['slow_query_ms']);
        // No upper cap on purpose — operators may want to silence the log
        // entirely with a very high threshold.
        $this->assertSame(60000, $this->sanitize(['slow_query_ms' => 60000])['slow_query_ms']);
    }

    public function test_embedding_dim_floored_at_one(): void {
        $this->assertSame(1, $this->sanitize(['embedding_dim' => 0])['embedding_dim']);
        $this->assertSame(1, $this->sanitize(['embedding_dim' => -100])['embedding_dim']);
        $this->assertSame(3072, $this->sanitize(['embedding_dim' => 3072])['embedding_dim']);
    }

    // ─── Booleans ─────────────────────────────────────────────────────────

    public function test_booleans_coerce_truthy_inputs(): void {
        $bool_keys = ['enabled', 'hnsw_enabled', 'hybrid_enabled', 'query_cache_enabled', 'dedup_per_source'];
        foreach ($bool_keys as $k) {
            $this->assertTrue($this->sanitize([$k => '1'])[$k],     "$k should be true for '1'");
            $this->assertTrue($this->sanitize([$k => 'on'])[$k],    "$k should be true for 'on'");
            $this->assertTrue($this->sanitize([$k => true])[$k],    "$k should be true for true");
            $this->assertFalse($this->sanitize([$k => '0'])[$k],    "$k should be false for '0'");
            $this->assertFalse($this->sanitize([])[$k],             "$k missing should be false");
        }
    }

    // ─── Telemetry preservation ───────────────────────────────────────────

    public function test_runtime_telemetry_survives_admin_save(): void {
        // The admin form doesn't render these fields — saving the form must
        // NOT wipe them. Regressing this would clear the "last sync" UI clock
        // and the "last error" notice on every settings page save.
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            [
                'last_sync_at'    => 1700000000,
                'last_sync_count' => 8421,
                'last_compact_at' => 1700001000,
                'last_error'      => 'previous run hit a transient',
            ]
        ));

        $out = $this->sanitize(['mode' => 'embedded']); // arbitrary unrelated change

        $this->assertSame(1700000000, $out['last_sync_at']);
        $this->assertSame(8421, $out['last_sync_count']);
        $this->assertSame(1700001000, $out['last_compact_at']);
        $this->assertSame('previous run hit a transient', $out['last_error']);
    }

    // ─── Dimension / storage change guards (test the fallback branch) ─────

    public function test_changing_dim_on_disabled_install_is_accepted(): void {
        // Guard only fires when current['enabled'] is true. With it off, the
        // change goes through unchallenged.
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['enabled' => false, 'embedding_dim' => 1536]
        ));
        $out = $this->sanitize(['embedding_dim' => 3072]);
        $this->assertSame(3072, $out['embedding_dim']);
        $this->assertEmpty($GLOBALS['__test_settings_errors'], 'no warning when plugin is off');
    }

    public function test_changing_dim_on_enabled_install_with_no_table_is_accepted(): void {
        // current['enabled'] is true but introspecting the table fails in
        // tests (no real DuckDB). The "brand-new install" fallback in the
        // catch block accepts the change.
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['enabled' => true, 'embedding_dim' => 1536]
        ));
        $out = $this->sanitize(['enabled' => '1', 'embedding_dim' => 3072]);
        $this->assertSame(3072, $out['embedding_dim'], 'dim change accepted when no table to protect');
    }

    public function test_same_dim_input_skips_the_guard_branch_entirely(): void {
        // No actual change → no introspection → no settings_error regardless
        // of what state the plugin is in.
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['enabled' => true, 'embedding_dim' => 1536]
        ));
        $out = $this->sanitize(['enabled' => '1', 'embedding_dim' => 1536]);
        $this->assertSame(1536, $out['embedding_dim']);
        $this->assertEmpty($GLOBALS['__test_settings_errors']);
    }

    // ─── Mirror toggle ────────────────────────────────────────────────────

    public function test_mirror_toggle_accepted_when_mode_is_motherduck(): void {
        $out = $this->sanitize([
            'mode'                       => 'motherduck',
            'motherduck_mirror_enabled'  => '1',
            'motherduck_mirror_path'     => '/var/lib/mxchat/mirror.duckdb',
        ]);
        $this->assertTrue($out['motherduck_mirror_enabled']);
        $this->assertSame('/var/lib/mxchat/mirror.duckdb', $out['motherduck_mirror_path']);
        $this->assertEmpty($GLOBALS['__test_settings_errors']);
    }

    public function test_mirror_toggle_rejected_with_warning_when_mode_is_embedded(): void {
        // The mirror only makes sense for cloud mode — toggling it on
        // for the embedded backend would mean mirroring a local file
        // to itself. Silently drop with a visible settings_error.
        $out = $this->sanitize([
            'mode'                       => 'embedded',
            'motherduck_mirror_enabled'  => '1',
        ]);
        $this->assertFalse($out['motherduck_mirror_enabled'],
            'mirror toggle must be silently dropped on embedded mode');
        $this->assertNotEmpty($GLOBALS['__test_settings_errors'],
            'admin gets a settings_error so the dropped toggle is visible');
        $error_code = $GLOBALS['__test_settings_errors'][0]['code'] ?? '';
        $this->assertSame('mirror_requires_motherduck_mode', $error_code);
    }

    public function test_mirror_path_persists_even_when_toggle_is_dropped(): void {
        // If the user typed a path before switching to embedded mode,
        // we keep the path so switching back to motherduck doesn't
        // erase their input.
        $out = $this->sanitize([
            'mode'                       => 'embedded',
            'motherduck_mirror_enabled'  => '1',
            'motherduck_mirror_path'     => '/var/lib/mxchat/mirror.duckdb',
        ]);
        $this->assertFalse($out['motherduck_mirror_enabled']);
        $this->assertSame('/var/lib/mxchat/mirror.duckdb', $out['motherduck_mirror_path'],
            'path value persists across mode-driven toggle drops');
    }

    public function test_mirror_default_off(): void {
        // Empty input shouldn't enable the mirror — it's strictly opt-in.
        $out = $this->sanitize([]);
        $this->assertFalse($out['motherduck_mirror_enabled']);
        $this->assertSame('', $out['motherduck_mirror_path']);
    }

    public function test_takeover_default_bot_pinecone_defaults_off(): void {
        // The default-bot Option B shortcircuit is strictly opt-in. Sites
        // running real Pinecone alongside DuckDB on the default bot must
        // not see their settings hijacked by an empty form submission.
        $out = $this->sanitize([]);
        $this->assertFalse($out['takeover_default_bot_pinecone']);
    }

    public function test_takeover_default_bot_pinecone_coerces_truthy_values(): void {
        $this->assertTrue($this->sanitize(['takeover_default_bot_pinecone' => '1'])['takeover_default_bot_pinecone']);
        $this->assertTrue($this->sanitize(['takeover_default_bot_pinecone' => 'on'])['takeover_default_bot_pinecone']);
        $this->assertTrue($this->sanitize(['takeover_default_bot_pinecone' => 1])['takeover_default_bot_pinecone']);
        $this->assertFalse($this->sanitize(['takeover_default_bot_pinecone' => ''])['takeover_default_bot_pinecone']);
        $this->assertFalse($this->sanitize(['takeover_default_bot_pinecone' => '0'])['takeover_default_bot_pinecone']);
    }

    // ─── MotherDuck token resolution ──────────────────────────────────────

    public function test_resolved_motherduck_token_returns_option_when_no_constant(): void {
        // The constant defined at the test process level can't be unset, so
        // we only assert the option-fallback path when no override is
        // defined. The constant-wins path is exercised by the next test
        // through an in-process runkit-style shim (we set the override
        // directly via define() if not yet defined and assert it wins).
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['motherduck_token' => 'tok-from-option']
        ));

        if (!defined('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN')) {
            $this->assertSame('tok-from-option', MxChat_DuckDB_Options::resolved_motherduck_token());
            $this->assertFalse(MxChat_DuckDB_Options::motherduck_token_is_from_constant());
        } else {
            // The constant was already defined upstream in this PHP
            // process; verify it wins (this is the contract).
            $this->assertSame(
                (string) constant('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN'),
                MxChat_DuckDB_Options::resolved_motherduck_token()
            );
            $this->assertTrue(MxChat_DuckDB_Options::motherduck_token_is_from_constant());
        }
    }

    public function test_resolved_motherduck_token_constant_takes_precedence(): void {
        // Define the constant late so we can also exercise it. Once defined
        // in a PHP process it's permanent, so this test must run after the
        // previous one — phpunit's default alpha-by-method order satisfies
        // that ("constant_takes_precedence" > "returns_option_when_no_constant").
        if (!defined('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN')) {
            define('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN', 'tok-from-wpconfig');
        }

        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['motherduck_token' => 'tok-from-option-but-IGNORED']
        ));

        $this->assertSame(
            (string) constant('MXCHAT_DUCKDB_MOTHERDUCK_TOKEN'),
            MxChat_DuckDB_Options::resolved_motherduck_token(),
            'wp-config constant must win over the option row'
        );
        $this->assertTrue(MxChat_DuckDB_Options::motherduck_token_is_from_constant());
    }
}
