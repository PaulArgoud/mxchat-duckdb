<?php
/**
 * Read path for the vector store: top-K similarity search + hybrid BM25 +
 * dedup + custom reranker hook + result caching + slow-query log.
 *
 * Pure read-side — never mutates the table. The cache invalidation is owned
 * by the writer side (Vector_Store::upsert / delete_* calls
 * MxChat_DuckDB_Plugin::flush_query_cache()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Vector_Store_Query {

    use MxChat_DuckDB_SQL_Helpers_Trait;

    public function __construct(
        protected MxChat_DuckDB_Connection $conn,
        protected string $table,
        protected int $dim,
        protected string $metric,
        protected string $storage
    ) {}

    /**
     * Top-K similarity search. Returns Pinecone-shaped matches:
     *   [{id, score, metadata: {text, source_url, role_restriction, type,
     *      chunk_index, total_chunks, is_chunked}}, ...]
     *
     * Pipeline (each step gated by options or filters):
     *   1. Result cache lookup (md5 of embedding + filter + top_k + bot_id)
     *   2. Vector search, optionally hybrid-merged with BM25
     *   3. Per-source dedup
     *   4. `mxchat_duckdb_rerank_matches` filter
     *   5. Slow-query log + cache write
     *
     * @throws RuntimeException on dimension mismatch.
     */
    public function run(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
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
     * Pure vector top-K. $where_parts is the pre-built array of SQL fragments
     * (AND-joined).
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
     * Hybrid BM25 + vector via min-max-normalised score blending. Over-fetches
     * top_k * 4 from each leg so the merge has enough signal.
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
            error_log('[mxchat-duckdb] BM25 path failed, falling back to vector-only: ' . $e->getMessage());
            return self::rows_to_matches(array_slice($vector_rows, 0, $top_k));
        }

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

    private function score_expression(array $embedding): string {
        $vec = $this->literal_int_or_float_array($embedding);
        $col_sql = $this->embedding_as_float_sql();
        switch ($this->metric) {
            case 'l2sq':
                return sprintf('(-1.0 * array_distance(%s, %s::FLOAT[%d]))', $col_sql, $vec, $this->dim);
            case 'ip':
                return sprintf('array_inner_product(%s, %s::FLOAT[%d])', $col_sql, $vec, $this->dim);
            case 'cosine':
            default:
                return sprintf('array_cosine_similarity(%s, %s::FLOAT[%d])', $col_sql, $vec, $this->dim);
        }
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
     * Unknown fields/operators are silently dropped (Pinecone parity).
     *
     * @return string[]
     */
    public static function compile_filter(array $filter, MxChat_DuckDB_Vector_Store_Query $store): array {
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
                    case '$eq':  $out[] = $col . ' = '  . $store->literal_for($val); break;
                    case '$ne':  $out[] = $col . ' <> ' . $store->literal_for($val); break;
                    case '$gt':  $out[] = $col . ' > '  . $store->literal_for($val); break;
                    case '$gte': $out[] = $col . ' >= ' . $store->literal_for($val); break;
                    case '$lt':  $out[] = $col . ' < '  . $store->literal_for($val); break;
                    case '$lte': $out[] = $col . ' <= ' . $store->literal_for($val); break;
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

    /**
     * Min-max normalise scores into [0,1]. Returns an id => normalized map.
     * Constant inputs (range = 0) collapse to 1.0 to avoid silent zeroing.
     */
    public static function normalize_scores(array $rows): array {
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
     * scoring one. Empty-URL rows pass through unchanged.
     */
    public static function dedup_per_source(array $matches, int $top_k): array {
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

    public static function cache_key(array $embedding, int $top_k, string $bot_id, array $filter): string {
        $h = md5(implode(',', array_map('strval', $embedding)) . '|' . $top_k . '|' . $bot_id . '|' . wp_json_encode($filter));
        return 'mxd_q_' . $h;
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
}
