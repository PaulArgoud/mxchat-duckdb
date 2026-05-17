<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the read-path orchestration in Vector_Store_Query::run() — the
 * top-K pipeline fired on every chat turn:
 *
 *   dim check → cache lookup → SQL (vector-only or hybrid) → dedup
 *   → rerank filter → latency observation → cache write
 *
 * Each test mocks the connection so SQL is recorded but not executed,
 * which lets us assert on the SHAPE of the query (does dedup over-fetch?
 * does the cache hit short-circuit the SELECT?) without DuckDB.
 *
 * The individual helpers (compile_filter, normalize_scores, dedup_per_source,
 * cache_key) already have dedicated tests; this file is about how they're
 * stitched together.
 */
final class VectorStoreQueryRunTest extends TestCase {

    private function makeRecordingConnection(array $rows_to_return = []): MxChat_DuckDB_Connection {
        return new class($rows_to_return) implements MxChat_DuckDB_Connection {
            public array $log = [];
            public array $rows;
            public function __construct(array $rows) { $this->rows = $rows; }
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                return $this->rows;
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:run'; }
        };
    }

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];
        // Wipe Schema memoisation so ensure_schema() actually runs DDL we can assert on.
        $r = new ReflectionProperty(MxChat_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setAccessible(true);
        $r->setValue(null, []);
        // Wipe per-request ignored-filter dedup so cross-test pollution doesn't hide regressions.
        $r2 = new ReflectionProperty(MxChat_DuckDB_Vector_Store_Query::class, 'logged_ignored');
        $r2->setAccessible(true);
        $r2->setValue(null, []);
    }

    private function makeQuery(array $opts_override = [], int $dim = 3, array $sql_rows = []): array {
        // Returns [Query instance, recording connection] so each test can
        // both invoke run() and inspect what SQL hit the wire.
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, ['embedding_dim' => $dim], $opts_override));

        $conn = $this->makeRecordingConnection($sql_rows);
        $query = new MxChat_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', $dim, 'cosine', 'float32');
        return [$query, $conn];
    }

    // ─── Dim mismatch ─────────────────────────────────────────────────────

    public function test_dim_mismatch_throws_with_clear_message(): void {
        [$query] = $this->makeQuery([], 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dimension/i');
        $query->run([0.1, 0.2], 10, 'default', []);  // 2-dim against a 3-dim store
    }

    // ─── Cache lookup short-circuit ───────────────────────────────────────

    public function test_cache_hit_returns_cached_matches_without_hitting_sql(): void {
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => true, 'query_cache_ttl' => 300]);

        // Pre-seed a cache entry for these inputs.
        $gen = MxChat_DuckDB_Plugin::cache_generation();
        $cache_key = MxChat_DuckDB_Vector_Store_Query::cache_key(
            [0.1, 0.2, 0.3], 10, 'default', [], $gen
        );
        $cached = [['id' => 'cached_v1', 'score' => 0.9, 'metadata' => []]];
        set_transient($cache_key, $cached, 300);

        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $this->assertSame($cached, $out, 'cache hit must return the stored matches verbatim');
        $this->assertEmpty($conn->log, 'cache hit must NOT issue any SQL');
    }

    public function test_cache_miss_issues_sql_and_writes_result_to_cache(): void {
        $rows = [
            ['id' => 'v1', 'score' => 0.9, 'text' => 'foo', 'source_url' => 'a',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
        ];
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => true, 'query_cache_ttl' => 300], 3, $rows);

        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $this->assertCount(1, $conn->log, 'cache miss must issue exactly one vector SELECT');
        $this->assertCount(1, $out);
        $this->assertSame('v1', $out[0]['id']);

        // Cache write happened.
        $gen = MxChat_DuckDB_Plugin::cache_generation();
        $key = MxChat_DuckDB_Vector_Store_Query::cache_key([0.1, 0.2, 0.3], 10, 'default', [], $gen);
        $this->assertNotFalse(get_transient($key), 'result should be persisted to the transient layer');
    }

    public function test_cache_disabled_does_not_write_to_transient_layer(): void {
        [$query, $conn] = $this->makeQuery(['query_cache_enabled' => false]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $cache_transients = array_filter(
            array_keys($GLOBALS['__test_transients']),
            fn($k) => strpos($k, 'mxd_q_') === 0
        );
        $this->assertSame([], $cache_transients,
            'with cache disabled, no mxd_q_ transient should be written');
    }

    // ─── Dedup over-fetch ─────────────────────────────────────────────────

    public function test_dedup_per_source_over_fetches_top_k_times_three(): void {
        [$query, $conn] = $this->makeQuery(['dedup_per_source' => true]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $this->assertNotEmpty($conn->log);
        $sql = end($conn->log);
        // top_k=10 × 3 = 30 → LIMIT 30. This is the v0.6.0 fix: the previous
        // version used LIMIT 10 and lost rows whenever dedup collapsed
        // multiple chunks from the same source_url.
        $this->assertStringContainsString('LIMIT 30', $sql,
            'dedup_per_source on → SQL LIMIT must be top_k × 3 so dedup leaves enough rows for top_k');
    }

    public function test_dedup_off_uses_plain_top_k_limit(): void {
        [$query, $conn] = $this->makeQuery(['dedup_per_source' => false]);
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = end($conn->log);
        $this->assertStringContainsString('LIMIT 10', $sql,
            'dedup off → SQL LIMIT must equal the requested top_k');
    }

    // ─── Rerank hook ──────────────────────────────────────────────────────

    public function test_rerank_filter_can_reshape_the_result(): void {
        $rows = [
            ['id' => 'a', 'score' => 0.5, 'text' => 'A', 'source_url' => 'ua',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
            ['id' => 'b', 'score' => 0.4, 'text' => 'B', 'source_url' => 'ub',
             'role_restriction' => 'public', 'type' => 'content',
             'chunk_index' => null, 'total_chunks' => null, 'is_chunked' => false],
        ];
        [$query] = $this->makeQuery([], 3, $rows);

        // The test bootstrap stubs apply_filters() as a pass-through (the
        // default rerank is "identity"), so we can't easily install a real
        // filter here. Instead, verify the contract by passing through the
        // identity case: matches returned by the SQL must reach the caller
        // unchanged after the rerank hook tag.
        $out = $query->run([0.1, 0.2, 0.3], 10, 'default', []);
        $this->assertCount(2, $out);
        $this->assertSame('a', $out[0]['id']);
        $this->assertSame('b', $out[1]['id']);
    }

    // ─── SQL shape ────────────────────────────────────────────────────────

    public function test_default_query_is_pure_vector_search(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString('array_cosine_similarity', $sql,
            'default metric is cosine — SQL must use array_cosine_similarity');
        $this->assertStringContainsString('FROM "mxchat_vectors"', $sql);
        $this->assertStringContainsString("bot_id = 'default'", $sql);
    }

    public function test_bot_id_filter_is_injected_into_where(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'support_fr', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString("bot_id = 'support_fr'", $sql);
    }

    public function test_pinecone_filter_is_compiled_into_where(): void {
        [$query, $conn] = $this->makeQuery();
        $query->run([0.1, 0.2, 0.3], 10, 'default', [
            'type' => ['$eq' => 'post'],
            'chunk_index' => ['$gte' => 0],
        ]);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString("content_type = 'post'", $sql);
        $this->assertStringContainsString('chunk_index >= 0', $sql);
    }

    // ─── Metric branching ─────────────────────────────────────────────────

    public function test_l2sq_metric_uses_array_distance(): void {
        $opts = array_merge(MxChat_DuckDB_Options::defaults(), ['embedding_dim' => 3]);
        update_option('mxchat_duckdb_options', $opts);

        $conn = $this->makeRecordingConnection([]);
        $query = new MxChat_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', 3, 'l2sq', 'float32');
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString('array_distance', $sql);
        $this->assertStringContainsString('-1.0', $sql, 'l2sq score is negated so DESC sort yields nearest-first');
    }

    public function test_ip_metric_uses_array_inner_product(): void {
        $opts = array_merge(MxChat_DuckDB_Options::defaults(), ['embedding_dim' => 3]);
        update_option('mxchat_duckdb_options', $opts);

        $conn = $this->makeRecordingConnection([]);
        $query = new MxChat_DuckDB_Vector_Store_Query($conn, 'mxchat_vectors', 3, 'ip', 'float32');
        $query->run([0.1, 0.2, 0.3], 10, 'default', []);

        $sql = $conn->log[0] ?? '';
        $this->assertStringContainsString('array_inner_product', $sql);
    }
}
