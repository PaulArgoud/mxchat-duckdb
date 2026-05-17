<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the public façade of Vector_Store: the write path (upsert, the
 * two delete variants), the read-side lookup helpers (count, list_ids,
 * fetch_by_ids), Parquet I/O, and the cache-flush coupling that makes
 * O(1) cache invalidation work after a mutation.
 *
 * The read pipeline (Vector_Store_Query::run) and schema lifecycle are
 * covered in their own tests; this file is about the surface every other
 * subsystem (REST proxy, sync, compactor, CLI) actually calls.
 */
final class VectorStoreFacadeTest extends TestCase {

    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];

        // Reset Schema memoisation + Vector_Store singleton cache.
        $r1 = new ReflectionProperty(MxChat_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r1->setAccessible(true);
        $r1->setValue(null, []);

        $r2 = new ReflectionProperty(MxChat_DuckDB_Vector_Store::class, 'current');
        $r2->setAccessible(true);
        $r2->setValue(null, null);

        // Reset the Plugin stub's cache generation counter so each test
        // starts from a known baseline.
        MxChat_DuckDB_Plugin::$cache_gen = 1;
        MxChat_DuckDB_Plugin::$flushed = [];

        $this->mock_conn = new class implements MxChat_DuckDB_Connection {
            public array $log = [];
            public array $responses = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'schema_meta') !== false && stripos($sql, 'SELECT value') !== false) {
                    return [['value' => '3']]; // schema already at target
                }
                foreach ($this->responses as $needle => $rows) {
                    if (stripos($sql, $needle) !== false) return $rows;
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:facade'; }
        };
    }

    private function store(array $opts_override = [], int $dim = 3): MxChat_DuckDB_Vector_Store {
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, ['embedding_dim' => $dim], $opts_override));
        return new MxChat_DuckDB_Vector_Store($this->mock_conn);
    }

    // ─── upsert ───────────────────────────────────────────────────────────

    public function test_upsert_empty_array_is_a_noop_and_does_not_flush_cache(): void {
        $store = $this->store();
        $this->assertSame(0, $store->upsert([]));
        $this->assertEmpty(MxChat_DuckDB_Plugin::$flushed,
            'no rows → no cache invalidation needed');
    }

    public function test_upsert_rejects_dim_mismatch_with_a_clear_message(): void {
        $store = $this->store([], 3);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dimension mismatch/i');
        $store->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2]], // 2-dim against a 3-dim store
        ]);
    }

    public function test_upsert_silently_skips_rows_without_vector_id_or_embedding(): void {
        $store = $this->store();
        $count = $store->upsert([
            ['vector_id' => '',  'embedding' => [0.1, 0.2, 0.3]], // empty id
            ['vector_id' => 'v', 'embedding' => null],            // null embedding
            ['vector_id' => 'v2'],                                // missing embedding key
        ]);
        $this->assertSame(0, $count, 'all three malformed rows should be skipped');
    }

    public function test_upsert_emits_one_batched_insert_or_replace_for_valid_rows(): void {
        $store = $this->store();
        $count = $store->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2, 0.3], 'content' => 'foo', 'source_url' => 'a'],
            ['vector_id' => 'v2', 'embedding' => [0.4, 0.5, 0.6], 'content' => 'bar', 'source_url' => 'b'],
        ]);

        $this->assertSame(2, $count);
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false);
        $this->assertCount(1, $inserts, 'a single batched INSERT, not one per row');
        $sql = array_values($inserts)[0];
        $this->assertStringContainsString("'v1'", $sql);
        $this->assertStringContainsString("'v2'", $sql);
        $this->assertStringContainsString("[0.1,0.2,0.3]", $sql);
        $this->assertStringContainsString("[0.4,0.5,0.6]", $sql);
    }

    public function test_upsert_bumps_cache_generation_after_successful_write(): void {
        $before = MxChat_DuckDB_Plugin::cache_generation();
        $this->store()->upsert([
            ['vector_id' => 'v1', 'embedding' => [0.1, 0.2, 0.3]],
        ]);
        $this->assertGreaterThan($before, MxChat_DuckDB_Plugin::cache_generation(),
            'a successful upsert must bump the cache generation so stale top-K go unreachable');
    }

    public function test_upsert_quotes_single_quotes_in_text_to_prevent_sql_injection(): void {
        $this->store()->upsert([
            ['vector_id' => "id'with'quotes", 'embedding' => [0.1, 0.2, 0.3],
             'content' => "It's a 'quoted' string"],
        ]);
        $sql = end($this->mock_conn->log);
        // SQL standard escapes ' as ''.
        $this->assertStringContainsString("'id''with''quotes'", $sql);
        $this->assertStringContainsString("'It''s a ''quoted'' string'", $sql);
    }

    public function test_upsert_chunk_size_can_be_overridden_via_filter(): void {
        // Default for embedded is 250; we cap at 2 via the filter to verify
        // chunking actually happens.
        $GLOBALS['__test_filter_overrides']['mxchat_duckdb_upsert_chunk_size'] = 2;
        try {
            $rows = [];
            for ($i = 0; $i < 5; $i++) {
                $rows[] = ['vector_id' => 'v' . $i, 'embedding' => [0.1, 0.2, 0.3]];
            }
            $this->store()->upsert($rows);
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE') !== false);
        $this->assertCount(3, $inserts,
            '5 rows at chunk_size=2 → ceil(5/2) = 3 batched INSERTs');
    }

    // ─── delete_by_ids ────────────────────────────────────────────────────

    public function test_delete_by_ids_empty_array_is_a_noop(): void {
        $this->assertSame(0, $this->store()->delete_by_ids([], 'default'));
        $this->assertEmpty($this->mock_conn->log);
        $this->assertEmpty(MxChat_DuckDB_Plugin::$flushed);
    }

    public function test_delete_by_ids_emits_delete_and_bumps_cache(): void {
        $before = MxChat_DuckDB_Plugin::cache_generation();
        $this->store()->delete_by_ids(['v1', 'v2', 'v3'], 'support_fr');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString("bot_id = 'support_fr'", $sql);
        $this->assertStringContainsString("vector_id IN ('v1','v2','v3')", $sql);
        $this->assertGreaterThan($before, MxChat_DuckDB_Plugin::cache_generation());
    }

    public function test_delete_by_source_url_scopes_to_bot_id(): void {
        $this->store()->delete_by_source_url('https://example.com/post-42', 'sales_en');
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString("bot_id = 'sales_en'", $sql);
        $this->assertStringContainsString("source_url = 'https://example.com/post-42'", $sql);
    }

    // ─── count / list_ids / fetch_by_ids ──────────────────────────────────

    public function test_count_returns_zero_when_table_is_empty(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [];
        $this->assertSame(0, $this->store()->count());
    }

    public function test_count_returns_value_for_bot_id_scope(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 8421]];
        $count = $this->store()->count('support_fr');
        $this->assertSame(8421, $count);
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString("bot_id = 'support_fr'", $sql);
    }

    public function test_list_ids_returns_string_array_of_vector_ids(): void {
        $this->mock_conn->responses['SELECT vector_id FROM'] = [
            ['vector_id' => 'v_alpha'],
            ['vector_id' => 'v_beta'],
        ];
        $ids = $this->store()->list_ids('default', 50, 0);
        $this->assertSame(['v_alpha', 'v_beta'], $ids);
        $sql = end($this->mock_conn->log);
        $this->assertStringContainsString('LIMIT 50', $sql);
        $this->assertStringContainsString('OFFSET 0', $sql);
        // Latest-first ordering for the admin UI list.
        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    public function test_fetch_by_ids_returns_pinecone_shaped_metadata_map(): void {
        $this->mock_conn->responses['SELECT vector_id AS id'] = [
            ['id' => 'v1', 'text' => 'hello', 'source_url' => 'a', 'role_restriction' => 'public',
             'type' => 'post', 'chunk_index' => 2, 'total_chunks' => 5, 'is_chunked' => true],
        ];
        $out = $this->store()->fetch_by_ids(['v1', 'v2'], 'default');

        $this->assertArrayHasKey('v1', $out);
        $this->assertSame('v1', $out['v1']['id']);
        $this->assertSame('hello', $out['v1']['metadata']['text']);
        $this->assertSame('a', $out['v1']['metadata']['source_url']);
        $this->assertSame('post', $out['v1']['metadata']['type']);
        $this->assertSame(2, $out['v1']['metadata']['chunk_index']);
        $this->assertSame(5, $out['v1']['metadata']['total_chunks']);
        $this->assertTrue($out['v1']['metadata']['is_chunked']);
    }

    public function test_fetch_by_ids_empty_input_is_a_noop(): void {
        $out = $this->store()->fetch_by_ids([], 'default');
        $this->assertSame([], $out);
        $this->assertEmpty($this->mock_conn->log);
    }

    // ─── Parquet I/O ──────────────────────────────────────────────────────

    public function test_export_parquet_emits_copy_to_with_zstd_compression(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 100]];
        $n = $this->store()->export_parquet('/var/backups/dump.parquet');
        $this->assertSame(100, $n);
        $copy_sql = self::firstMatching($this->mock_conn->log, 'COPY');
        $this->assertNotNull($copy_sql);
        $this->assertStringContainsString("TO '/var/backups/dump.parquet'", $copy_sql);
        $this->assertStringContainsString('FORMAT PARQUET', $copy_sql);
        $this->assertStringContainsString('COMPRESSION ZSTD', $copy_sql);
    }

    public function test_export_parquet_escapes_single_quotes_in_path(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 0]];
        $this->store()->export_parquet("/tmp/o'reilly.parquet");
        $copy_sql = self::firstMatching($this->mock_conn->log, 'COPY');
        // PHP "''" in the SQL is the SQL standard escape for a single quote.
        $this->assertStringContainsString("'/tmp/o''reilly.parquet'", $copy_sql);
    }

    public function test_import_parquet_creates_temp_view_then_insert_or_replace_then_drops(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 250]];
        $n = $this->store()->import_parquet('/tmp/restore.parquet');
        $this->assertSame(250, $n);

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("CREATE OR REPLACE TEMP VIEW", $log);
        $this->assertStringContainsString("read_parquet('/tmp/restore.parquet')", $log);
        $this->assertStringContainsString("INSERT OR REPLACE INTO \"mxchat_vectors\"", $log);
        $this->assertStringContainsString("DROP VIEW IF EXISTS", $log);
    }

    public function test_import_parquet_zero_rows_does_not_attempt_insert(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 0]];
        $n = $this->store()->import_parquet('/tmp/empty.parquet');
        $this->assertSame(0, $n);
        $inserts = array_filter($this->mock_conn->log,
            fn($sql) => stripos($sql, 'INSERT OR REPLACE INTO "mxchat_vectors"') !== false);
        $this->assertEmpty($inserts, 'empty Parquet → no INSERT statement');
    }

    public function test_import_parquet_bumps_cache_generation(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 5]];
        $before = MxChat_DuckDB_Plugin::cache_generation();
        $this->store()->import_parquet('/tmp/x.parquet');
        $this->assertGreaterThan($before, MxChat_DuckDB_Plugin::cache_generation());
    }

    // ─── storage_estimate ────────────────────────────────────────────────

    public function test_storage_estimate_uses_4_bytes_per_value_for_float32(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 1000]];
        $store = $this->store(['embedding_storage' => 'float32'], 1536);
        $est = $store->storage_estimate();
        // 1000 rows × (1536 floats × 4 bytes + ~200 metadata fudge).
        $expected = 1000 * (1536 * 4 + 200);
        $this->assertSame($expected, $est['bytes_estimate']);
        $this->assertSame('float32', $est['storage']);
        $this->assertSame(1536, $est['dim']);
    }

    public function test_storage_estimate_uses_1_byte_per_value_for_int8(): void {
        $this->mock_conn->responses['SELECT COUNT(*)'] = [['c' => 1000]];
        $store = $this->store(['embedding_storage' => 'int8'], 1536);
        $est = $store->storage_estimate();
        $expected = 1000 * (1536 * 1 + 200);
        $this->assertSame($expected, $est['bytes_estimate']);
        $this->assertSame('int8', $est['storage']);
    }

    // ─── current() singleton ──────────────────────────────────────────────

    public function test_current_caches_per_request_and_reset_clears(): void {
        update_option('mxchat_duckdb_options', array_merge(
            MxChat_DuckDB_Options::defaults(),
            ['embedding_dim' => 1536]
        ));
        // Vector_Store::current() does `new self()` with no args — the
        // constructor falls back to Connection_Factory. Pre-populate the
        // factory's cache with our mock so no real backend is spawned.
        MxChat_DuckDB_Connection_Factory::reset_cache();
        $r = new ReflectionProperty(MxChat_DuckDB_Connection_Factory::class, 'cache');
        $r->setAccessible(true);
        $rk = new ReflectionMethod(MxChat_DuckDB_Connection_Factory::class, 'cache_key');
        $rk->setAccessible(true);
        $key = $rk->invoke(null, MxChat_DuckDB_Options::get());
        $r->setValue(null, [$key => $this->mock_conn]);

        $a = MxChat_DuckDB_Vector_Store::current();
        $b = MxChat_DuckDB_Vector_Store::current();
        $this->assertSame($a, $b, 'current() must return the same instance within a request');
        MxChat_DuckDB_Vector_Store::reset_current();
        $c = MxChat_DuckDB_Vector_Store::current();
        $this->assertNotSame($a, $c, 'reset_current() must drop the singleton');
    }

    // ─── helper ───────────────────────────────────────────────────────────

    private static function firstMatching(array $log, string $needle): ?string {
        foreach ($log as $sql) {
            if (stripos($sql, $needle) !== false) return $sql;
        }
        return null;
    }
}
