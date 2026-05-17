<?php
/**
 * Vector store on top of a DuckDB connection.
 *
 * Schema (created lazily by ensure_schema()):
 *   CREATE TABLE mxchat_vectors (
 *       vector_id        VARCHAR PRIMARY KEY,
 *       bot_id           VARCHAR DEFAULT 'default',
 *       embedding        FLOAT[<dim>],
 *       content          TEXT,
 *       source_url       VARCHAR,
 *       role_restriction VARCHAR DEFAULT 'public',
 *       content_type     VARCHAR DEFAULT 'content',
 *       chunk_index      INTEGER,
 *       total_chunks     INTEGER,
 *       is_chunked       BOOLEAN DEFAULT FALSE,
 *       created_at       TIMESTAMP DEFAULT current_timestamp,
 *       updated_at       TIMESTAMP DEFAULT current_timestamp
 *   );
 *
 * Plus an HNSW index over the embedding column (VSS extension) when enabled.
 *
 * Note on HNSW + bot_id filtering: DuckDB's VSS HNSW does not push down
 * arbitrary WHERE clauses, so multi-tenant queries (bot_id <> 'default')
 * effectively fall back to brute-force scans. For single-tenant installs
 * the index is used as expected.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Vector_Store {

    /**
     * Bump when a migration is added below. The migration runner is idempotent
     * — applying v1 to an install that already has the v1 schema is a no-op
     * because CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS short-circuit.
     */
    const TARGET_SCHEMA_VERSION = 3;
    const META_TABLE = 'mxchat_duckdb_schema_meta';

    private MxChat_DuckDB_Connection $conn;
    private string $table;
    private int $dim;
    private string $metric;
    private bool $hnsw;

    /** Static cache: tracks which (backend, table) combos already had ensure_schema() run. */
    private static array $schema_ensured = [];

    /**
     * MotherDuck enforces an HTTP body size limit on each query; keep upsert
     * batches well under it (one float = ~10–15 chars in literal form, so
     * 50 rows × 1536 dims ≈ 1 MB of SQL).
     */
    const UPSERT_CHUNK_ROWS_HTTP = 50;
    const UPSERT_CHUNK_ROWS_LOCAL = 250;

    public function __construct(?MxChat_DuckDB_Connection $conn = null) {
        $opts = MxChat_DuckDB_Options::get();
        $this->conn   = $conn ?? MxChat_DuckDB_Connection_Factory::from_options($opts);
        $this->table  = $opts['table_name'];
        $this->dim    = (int) $opts['embedding_dim'];
        $this->metric = $opts['distance_metric'];
        $this->hnsw   = !empty($opts['hnsw_enabled']);
    }

    /**
     * Idempotent — runs any pending schema migrations. Cached per-request so
     * the (cheap but non-trivial) version check only fires once per request.
     */
    public function ensure_schema(): void {
        $cache_key = $this->conn->identifier() . '|' . $this->table . '|' . $this->dim;
        if (isset(self::$schema_ensured[$cache_key])) return;

        $this->ensure_meta_table();
        $current = $this->get_schema_version();
        for ($v = $current + 1; $v <= self::TARGET_SCHEMA_VERSION; $v++) {
            $this->apply_migration($v);
            $this->set_schema_version($v);
        }

        self::$schema_ensured[$cache_key] = true;
    }

    private function ensure_meta_table(): void {
        $this->conn->execute(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (key VARCHAR PRIMARY KEY, value VARCHAR)',
            $this->quote_ident(self::META_TABLE)
        ));
    }

    private function get_schema_version(): int {
        try {
            $rows = $this->conn->execute(sprintf(
                "SELECT value FROM %s WHERE key = 'schema_version'",
                $this->quote_ident(self::META_TABLE)
            ));
            return (int) ($rows[0]['value'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function set_schema_version(int $version): void {
        $this->conn->execute(sprintf(
            "INSERT OR REPLACE INTO %s (key, value) VALUES ('schema_version', '%d')",
            $this->quote_ident(self::META_TABLE),
            $version
        ));
    }

    /**
     * Migrations run sequentially, each one transitioning the schema from
     * v(N-1) to vN. Migrations MUST be idempotent — they may run against an
     * install that already has parts of the target schema in place.
     */
    private function apply_migration(int $version): void {
        switch ($version) {
            case 1:
                $this->migration_v1_base_schema();
                return;
            case 2:
                $this->migration_v2_updated_at_column();
                return;
            case 3:
                $this->migration_v3_fts_index();
                return;
        }
    }

    private function migration_v1_base_schema(): void {
        $this->conn->execute('INSTALL vss');
        $this->conn->execute('LOAD vss');

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                vector_id        VARCHAR PRIMARY KEY,
                bot_id           VARCHAR DEFAULT \'default\',
                embedding        FLOAT[%d],
                content          TEXT,
                source_url       VARCHAR,
                role_restriction VARCHAR DEFAULT \'public\',
                content_type     VARCHAR DEFAULT \'content\',
                chunk_index      INTEGER,
                total_chunks     INTEGER,
                is_chunked       BOOLEAN DEFAULT FALSE,
                created_at       TIMESTAMP DEFAULT current_timestamp
            )',
            $this->quote_ident($this->table),
            $this->dim
        );
        $this->conn->execute($sql);

        if ($this->hnsw) {
            $metric = $this->vss_metric();
            try {
                $this->conn->execute(sprintf(
                    'CREATE INDEX IF NOT EXISTS idx_%1$s_hnsw ON %2$s USING HNSW (embedding) WITH (metric = \'%3$s\')',
                    $this->table,
                    $this->quote_ident($this->table),
                    $metric
                ));
            } catch (\Throwable $e) {
                // HNSW is optional — fall back to brute-force scans if the index fails.
            }
        }
    }

    private function migration_v2_updated_at_column(): void {
        try {
            $this->conn->execute(sprintf(
                'ALTER TABLE %s ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT current_timestamp',
                $this->quote_ident($this->table)
            ));
        } catch (\Throwable $e) {
            // Older DuckDB versions don't support ADD COLUMN IF NOT EXISTS — ignore.
        }
    }

    private function migration_v3_fts_index(): void {
        // FTS is best-effort: the extension may not be available on every
        // DuckDB build. Hybrid search degrades gracefully to pure vector.
        try {
            $this->conn->execute('INSTALL fts');
            $this->conn->execute('LOAD fts');
            $this->conn->execute(sprintf(
                "PRAGMA create_fts_index('%s', 'vector_id', 'content', overwrite = 0)",
                str_replace("'", "''", $this->table)
            ));
        } catch (\Throwable $e) {
            error_log('[mxchat-duckdb] FTS extension unavailable, hybrid search disabled: ' . $e->getMessage());
        }
    }

    /**
     * Returns the row count and detected dimension of the existing table,
     * or null if the table does not exist yet. Used by the admin UI to block
     * dimension changes when data is already present.
     */
    public function table_info(): ?array {
        try {
            $rows = $this->conn->execute(sprintf(
                'SELECT COUNT(*) AS c FROM %s',
                $this->quote_ident($this->table)
            ));
            return ['count' => (int) ($rows[0]['c'] ?? 0)];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<int, array{vector_id:string, bot_id?:string, embedding:array, content?:string,
     *   source_url?:string, role_restriction?:string, content_type?:string,
     *   chunk_index?:?int, total_chunks?:?int, is_chunked?:bool}> $vectors
     */
    public function upsert(array $vectors): int {
        if (empty($vectors)) return 0;

        $chunk_size = $this->upsert_chunk_size();
        $total = 0;
        foreach (array_chunk($vectors, $chunk_size) as $chunk) {
            $total += $this->upsert_chunk($chunk);
        }

        if ($total > 0) {
            MxChat_DuckDB_Plugin::flush_query_cache();
        }
        return $total;
    }

    private function upsert_chunk(array $vectors): int {
        // DuckDB supports INSERT OR REPLACE. We batch into one statement of N rows.
        $rows = [];
        foreach ($vectors as $v) {
            if (!isset($v['vector_id']) || $v['vector_id'] === '') continue;
            if (!isset($v['embedding']) || !is_array($v['embedding'])) continue;
            if (count($v['embedding']) !== $this->dim) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: vector id, 2: expected dim, 3: actual dim */
                    __('Embedding dimension mismatch on upsert: vector_id=%1$s, expected %2$d, got %3$d.', 'mxchat-duckdb'),
                    $v['vector_id'],
                    $this->dim,
                    count($v['embedding'])
                ));
            }

            $rows[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $this->literal_string((string) $v['vector_id']),
                $this->literal_string((string) ($v['bot_id'] ?? 'default')),
                $this->literal_float_array($v['embedding']),
                $this->literal_string((string) ($v['content'] ?? '')),
                $this->literal_string((string) ($v['source_url'] ?? '')),
                $this->literal_string((string) ($v['role_restriction'] ?? 'public')),
                $this->literal_string((string) ($v['content_type'] ?? 'content')),
                isset($v['chunk_index']) && $v['chunk_index'] !== null ? (int) $v['chunk_index'] : 'NULL',
                isset($v['total_chunks']) && $v['total_chunks'] !== null ? (int) $v['total_chunks'] : 'NULL',
                !empty($v['is_chunked']) ? 'TRUE' : 'FALSE'
            );
        }

        if (empty($rows)) return 0;

        $sql = sprintf(
            'INSERT OR REPLACE INTO %s (vector_id, bot_id, embedding, content, source_url, role_restriction, content_type, chunk_index, total_chunks, is_chunked) VALUES %s',
            $this->quote_ident($this->table),
            implode(",\n", $rows)
        );
        $this->conn->execute($sql);
        return count($rows);
    }

    /**
     * Top-K similarity search. Returns Pinecone-shaped matches:
     *   [{id, score, metadata: {text, source_url, role_restriction, type, chunk_index, total_chunks, is_chunked}}, ...]
     *
     * Pipeline (each step is optional / gated by options or filters):
     *   1. Result cache lookup (md5 of embedding + filter + top_k + bot_id)
     *   2. Vector search (always), optionally hybrid-merged with BM25
     *   3. Per-source dedup
     *   4. `mxchat_duckdb_rerank_matches` filter for custom rerankers
     *   5. Slow-query log + cache write
     */
    public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
        if (count($embedding) !== $this->dim) {
            throw new RuntimeException(sprintf(
                /* translators: 1: expected dim, 2: actual dim */
                __('Embedding dimension mismatch: expected %1$d, got %2$d. Update embedding_dim or re-sync.', 'mxchat-duckdb'),
                $this->dim,
                count($embedding)
            ));
        }

        $opts = MxChat_DuckDB_Options::get();
        $cache_key = self::cache_key($embedding, $top_k, $bot_id, $filter);

        if (!empty($opts['query_cache_enabled'])) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                MxChat_DuckDB_Metrics::record('query_cache_hit');
                return $cached;
            }
        }

        $start = microtime(true);
        $where_parts = $this->build_where($bot_id, $filter);

        // Optionally hybrid-merge BM25 if the consumer supplied query text.
        $query_text = (string) apply_filters('mxchat_duckdb_query_text', '', $bot_id, $filter);
        if (!empty($opts['hybrid_enabled']) && $query_text !== '') {
            $matches = $this->query_hybrid($embedding, $query_text, $top_k, $where_parts, (float) $opts['hybrid_alpha']);
        } else {
            $matches = $this->query_vector_only($embedding, $top_k, $where_parts);
        }

        if (!empty($opts['dedup_per_source'])) {
            $matches = self::dedup_per_source($matches, $top_k);
        }

        $matches = apply_filters('mxchat_duckdb_rerank_matches', $matches, $embedding, $bot_id, $filter, $query_text);

        $elapsed_ms = (int) ((microtime(true) - $start) * 1000);
        MxChat_DuckDB_Metrics::observe_latency($elapsed_ms);
        $threshold = (int) ($opts['slow_query_ms'] ?? 500);
        if ($threshold > 0 && $elapsed_ms >= $threshold) {
            error_log(sprintf(
                '[mxchat-duckdb] slow query %dms (bot=%s top_k=%d hybrid=%s dedup=%s)',
                $elapsed_ms, $bot_id, $top_k,
                !empty($opts['hybrid_enabled']) && $query_text !== '' ? '1' : '0',
                !empty($opts['dedup_per_source']) ? '1' : '0'
            ));
        }

        if (!empty($opts['query_cache_enabled']) && (int) $opts['query_cache_ttl'] > 0) {
            set_transient($cache_key, $matches, (int) $opts['query_cache_ttl']);
        }

        return $matches;
    }

    /**
     * Pure vector top-K. Returns Pinecone-shaped matches.
     *
     * @param string[] $where_parts already-quoted SQL fragments (AND-joined)
     */
    private function query_vector_only(array $embedding, int $top_k, array $where_parts): array {
        $sql = sprintf(
            'SELECT vector_id AS id,
                    %1$s AS score,
                    content AS text,
                    source_url,
                    role_restriction,
                    content_type AS type,
                    chunk_index,
                    total_chunks,
                    is_chunked
             FROM %2$s
             WHERE %3$s
             ORDER BY score DESC
             LIMIT %4$d',
            $this->score_expression($embedding),
            $this->quote_ident($this->table),
            implode(' AND ', $where_parts),
            $top_k
        );
        return self::rows_to_matches($this->conn->execute($sql));
    }

    /**
     * Hybrid BM25 + vector via min-max-normalised score blending.
     * Both lists are over-fetched to top_k * 4 to give the merge enough signal.
     */
    private function query_hybrid(array $embedding, string $query_text, int $top_k, array $where_parts, float $alpha): array {
        $over = max($top_k * 4, 50);
        $where_sql = implode(' AND ', $where_parts);

        $vector_rows = $this->conn->execute(sprintf(
            'SELECT vector_id AS id, %1$s AS score, content AS text, source_url,
                    role_restriction, content_type AS type, chunk_index, total_chunks, is_chunked
             FROM %2$s WHERE %3$s ORDER BY score DESC LIMIT %4$d',
            $this->score_expression($embedding),
            $this->quote_ident($this->table),
            $where_sql,
            $over
        ));

        $bm25_rows = [];
        try {
            $bm25_rows = $this->conn->execute(sprintf(
                "SELECT vector_id AS id,
                        fts_main_%1\$s.match_bm25(vector_id, %2\$s) AS score
                 FROM %3\$s
                 WHERE %4\$s
                   AND fts_main_%1\$s.match_bm25(vector_id, %2\$s) IS NOT NULL
                 ORDER BY score DESC LIMIT %5\$d",
                preg_replace('/[^a-zA-Z0-9_]/', '', $this->table),
                $this->literal_string($query_text),
                $this->quote_ident($this->table),
                $where_sql,
                $over
            ));
        } catch (\Throwable $e) {
            // FTS unavailable on this DuckDB build — fall back to pure vector.
            error_log('[mxchat-duckdb] BM25 path failed, falling back to vector-only: ' . $e->getMessage());
            return self::rows_to_matches(array_slice($vector_rows, 0, $top_k));
        }

        // Min-max normalise both lists into [0,1], then blend.
        $v_norm = self::normalize_scores($vector_rows);
        $b_norm = self::normalize_scores($bm25_rows);

        $blended = [];
        foreach ($v_norm as $id => $v) {
            $blended[$id] = ['v' => $v, 'b' => $b_norm[$id] ?? 0.0];
        }
        foreach ($b_norm as $id => $b) {
            if (!isset($blended[$id])) {
                $blended[$id] = ['v' => 0.0, 'b' => $b];
            }
        }

        // Index vector rows by id for metadata recovery.
        $by_id = [];
        foreach ($vector_rows as $r) {
            $by_id[(string) ($r['id'] ?? '')] = $r;
        }

        $combined = [];
        foreach ($blended as $id => $sc) {
            $row = $by_id[$id] ?? null;
            if (!$row) continue; // BM25-only hits without metadata are skipped
            $row['score'] = $alpha * $sc['v'] + (1.0 - $alpha) * $sc['b'];
            $combined[] = $row;
        }

        usort($combined, fn($a, $b) => ($b['score'] <=> $a['score']));
        return self::rows_to_matches(array_slice($combined, 0, $top_k));
    }

    /**
     * Min-max normalise scores into [0,1]. Returns an id => normalized map.
     */
    private static function normalize_scores(array $rows): array {
        if (empty($rows)) return [];
        $min = INF; $max = -INF;
        foreach ($rows as $r) {
            $s = (float) ($r['score'] ?? 0);
            if ($s < $min) $min = $s;
            if ($s > $max) $max = $s;
        }
        $range = $max - $min;
        $out = [];
        foreach ($rows as $r) {
            $s = (float) ($r['score'] ?? 0);
            $out[(string) ($r['id'] ?? '')] = $range > 0 ? ($s - $min) / $range : 1.0;
        }
        return $out;
    }

    /**
     * Collapse multiple chunks from the same source_url, keeping the highest-
     * scoring one. Empty source_url rows are kept as-is.
     */
    private static function dedup_per_source(array $matches, int $top_k): array {
        $seen = [];
        $out = [];
        foreach ($matches as $m) {
            $url = (string) ($m['metadata']['source_url'] ?? '');
            if ($url === '') {
                $out[] = $m;
                continue;
            }
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $out[] = $m;
            }
        }
        return array_slice($out, 0, $top_k);
    }

    private function build_where(string $bot_id, array $filter): array {
        $parts = ['bot_id = ' . $this->literal_string($bot_id)];
        foreach (self::compile_filter($filter, $this) as $sql) {
            $parts[] = $sql;
        }
        return $parts;
    }

    /**
     * Compile a Pinecone-style filter dict into SQL fragments. Supported
     * operators per field: $eq, $ne, $in, $nin, $gte, $gt, $lte, $lt.
     * Unsupported fields/operators are silently skipped (Pinecone behaviour).
     *
     * @return string[]
     */
    private static function compile_filter(array $filter, MxChat_DuckDB_Vector_Store $store): array {
        $allowed_fields = [
            'type'             => 'content_type',
            'content_type'     => 'content_type',
            'role_restriction' => 'role_restriction',
            'source_url'       => 'source_url',
            'chunk_index'      => 'chunk_index',
        ];

        $out = [];
        foreach ($filter as $field => $ops) {
            if (!isset($allowed_fields[$field]) || !is_array($ops)) continue;
            $col = $allowed_fields[$field];
            foreach ($ops as $op => $val) {
                switch ($op) {
                    case '$eq':
                        $out[] = $col . ' = ' . $store->literal_for($val);
                        break;
                    case '$ne':
                        $out[] = $col . ' <> ' . $store->literal_for($val);
                        break;
                    case '$gt':
                        $out[] = $col . ' > ' . $store->literal_for($val);
                        break;
                    case '$gte':
                        $out[] = $col . ' >= ' . $store->literal_for($val);
                        break;
                    case '$lt':
                        $out[] = $col . ' < ' . $store->literal_for($val);
                        break;
                    case '$lte':
                        $out[] = $col . ' <= ' . $store->literal_for($val);
                        break;
                    case '$in':
                    case '$nin':
                        if (!is_array($val) || empty($val)) break;
                        $list = implode(',', array_map([$store, 'literal_for'], $val));
                        $out[] = $col . ($op === '$in' ? ' IN ' : ' NOT IN ') . '(' . $list . ')';
                        break;
                }
            }
        }
        return $out;
    }

    public function literal_for($val): string {
        if (is_int($val) || is_float($val)) return (string) $val;
        if (is_bool($val)) return $val ? 'TRUE' : 'FALSE';
        if (is_null($val)) return 'NULL';
        return $this->literal_string((string) $val);
    }

    private static function rows_to_matches(array $rows): array {
        $matches = [];
        foreach ($rows as $r) {
            $matches[] = [
                'id'       => (string) ($r['id'] ?? ''),
                'score'    => (float) ($r['score'] ?? 0),
                'metadata' => [
                    'text'             => (string) ($r['text'] ?? ''),
                    'source_url'       => (string) ($r['source_url'] ?? ''),
                    'role_restriction' => (string) ($r['role_restriction'] ?? 'public'),
                    'type'             => (string) ($r['type'] ?? 'content'),
                    'chunk_index'      => isset($r['chunk_index']) ? (int) $r['chunk_index'] : null,
                    'total_chunks'     => isset($r['total_chunks']) ? (int) $r['total_chunks'] : null,
                    'is_chunked'       => !empty($r['is_chunked']),
                ],
            ];
        }
        return $matches;
    }

    private static function cache_key(array $embedding, int $top_k, string $bot_id, array $filter): string {
        $h = md5(implode(',', array_map('strval', $embedding)) . '|' . $top_k . '|' . $bot_id . '|' . wp_json_encode($filter));
        return 'mxd_q_' . $h;
    }

    public function delete_by_ids(array $vector_ids, string $bot_id = 'default'): int {
        if (empty($vector_ids)) return 0;
        $list = implode(',', array_map([$this, 'literal_string'], array_map('strval', $vector_ids)));
        $sql = sprintf(
            'DELETE FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $list
        );
        $this->conn->execute($sql);
        MxChat_DuckDB_Plugin::flush_query_cache();
        return count($vector_ids);
    }

    public function delete_by_source_url(string $source_url, string $bot_id = 'default'): int {
        $sql = sprintf(
            'DELETE FROM %s WHERE bot_id = %s AND source_url = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $this->literal_string($source_url)
        );
        $this->conn->execute($sql);
        MxChat_DuckDB_Plugin::flush_query_cache();
        return 1;
    }

    public function count(string $bot_id = 'default'): int {
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s WHERE bot_id = %s',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    public function list_ids(string $bot_id = 'default', int $limit = 100, int $offset = 0): array {
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id FROM %s WHERE bot_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $limit,
            $offset
        ));
        return array_map(fn($r) => (string) ($r['vector_id'] ?? ''), $rows);
    }

    public function fetch_by_ids(array $vector_ids, string $bot_id = 'default'): array {
        if (empty($vector_ids)) return [];
        $list = implode(',', array_map([$this, 'literal_string'], array_map('strval', $vector_ids)));
        $rows = $this->conn->execute(sprintf(
            'SELECT vector_id AS id, content AS text, source_url, role_restriction, content_type AS type,
                    chunk_index, total_chunks, is_chunked
             FROM %s WHERE bot_id = %s AND vector_id IN (%s)',
            $this->quote_ident($this->table),
            $this->literal_string($bot_id),
            $list
        ));
        $out = [];
        foreach ($rows as $r) {
            $id = (string) ($r['id'] ?? '');
            $out[$id] = [
                'id'       => $id,
                'metadata' => [
                    'text'             => (string) ($r['text'] ?? ''),
                    'source_url'       => (string) ($r['source_url'] ?? ''),
                    'role_restriction' => (string) ($r['role_restriction'] ?? 'public'),
                    'type'             => (string) ($r['type'] ?? 'content'),
                    'chunk_index'      => isset($r['chunk_index']) ? (int) $r['chunk_index'] : null,
                    'total_chunks'     => isset($r['total_chunks']) ? (int) $r['total_chunks'] : null,
                    'is_chunked'       => !empty($r['is_chunked']),
                ],
            ];
        }
        return $out;
    }

    public function connection(): MxChat_DuckDB_Connection {
        return $this->conn;
    }

    public function table_name(): string {
        return $this->table;
    }

    public function table_name_quoted(): string {
        return $this->quote_ident($this->table);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function upsert_chunk_size(): int {
        $opts = MxChat_DuckDB_Options::get();
        $is_remote = ($opts['mode'] ?? '') === 'motherduck';
        $default = $is_remote ? self::UPSERT_CHUNK_ROWS_HTTP : self::UPSERT_CHUNK_ROWS_LOCAL;
        return (int) apply_filters('mxchat_duckdb_upsert_chunk_size', $default, $is_remote);
    }

    private function score_expression(array $embedding): string {
        $vec = $this->literal_float_array($embedding);
        switch ($this->metric) {
            case 'l2sq':
                return sprintf('(-1.0 * array_distance(embedding, %s::FLOAT[%d]))', $vec, $this->dim);
            case 'ip':
                return sprintf('array_inner_product(embedding, %s::FLOAT[%d])', $vec, $this->dim);
            case 'cosine':
            default:
                return sprintf('array_cosine_similarity(embedding, %s::FLOAT[%d])', $vec, $this->dim);
        }
    }

    private function vss_metric(): string {
        return match ($this->metric) {
            'l2sq' => 'l2sq',
            'ip'   => 'ip',
            default => 'cosine',
        };
    }

    private function quote_ident(string $ident): string {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $ident);
        return '"' . $clean . '"';
    }

    private function literal_string(string $val): string {
        return "'" . str_replace("'", "''", $val) . "'";
    }

    /**
     * @throws RuntimeException on non-numeric values. Silently zeroing them
     * would corrupt embeddings (a zero vector matches nothing useful in cosine
     * similarity but ranks deterministically — hard to detect downstream).
     */
    private function literal_float_array(array $arr): string {
        $parts = [];
        foreach ($arr as $i => $v) {
            if (is_int($v) || is_float($v)) {
                $parts[] = (string) $v;
            } elseif (is_numeric($v)) {
                $parts[] = (string) (float) $v;
            } else {
                throw new RuntimeException(sprintf(
                    /* translators: 1: index, 2: PHP type name */
                    __('Non-numeric embedding component at index %1$d (type %2$s). Refusing to write a corrupted vector.', 'mxchat-duckdb'),
                    $i,
                    gettype($v)
                ));
            }
        }
        return '[' . implode(',', $parts) . ']';
    }
}
