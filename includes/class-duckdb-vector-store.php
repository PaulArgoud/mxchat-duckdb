<?php
/**
 * Vector store on top of a DuckDB connection. Orchestrator that delegates:
 *
 *   - schema lifecycle  → MxChat_DuckDB_Vector_Store_Schema
 *   - read / top-K path → MxChat_DuckDB_Vector_Store_Query
 *
 * Owns the write path (upsert, delete), the lifecycle helpers (count,
 * list_ids, fetch_by_ids), and the Parquet I/O. Keeps the public API stable
 * — existing call-sites (sync, REST proxy, admin, CLI, tests) all keep
 * compiling without change.
 *
 * Schema (managed by Vector_Store_Schema, migration target v3):
 *   CREATE TABLE mxchat_vectors (
 *       vector_id        VARCHAR PRIMARY KEY,
 *       bot_id           VARCHAR DEFAULT 'default',
 *       embedding        FLOAT[<dim>] or TINYINT[<dim>] when embedding_storage='int8',
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
 * Plus an HNSW index on the embedding column (VSS extension) when enabled,
 * and an FTS index on content for hybrid search.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Vector_Store {

    use MxChat_DuckDB_SQL_Helpers_Trait;

    /**
     * MotherDuck enforces a HTTP body size limit per query; keep upsert
     * batches well under it. ~50 rows × 1536 dims ≈ 1 MB of SQL.
     */
    const UPSERT_CHUNK_ROWS_HTTP = 50;
    const UPSERT_CHUNK_ROWS_LOCAL = 250;

    protected MxChat_DuckDB_Connection $conn;
    protected string $table;
    protected int $dim;
    protected string $metric;
    protected bool $hnsw;
    protected string $storage; // 'float32' | 'int8'

    private MxChat_DuckDB_Vector_Store_Schema $schema;
    private MxChat_DuckDB_Vector_Store_Query $query;

    /** Per-request cached default instance, returned by current(). */
    private static ?self $current = null;

    /**
     * Per-request cached store keyed off the current plugin options. Hot REST
     * handlers (Pinecone proxy, search adapter) call this instead of `new …`
     * to avoid re-resolving options, re-building the Schema/Query collaborators,
     * and re-walking the migration short-circuit on every request.
     *
     * Mutating options invalidates the cache via reset_current(), which is
     * fired alongside Connection_Factory::reset_cache() from the options
     * sanitiser. Tests and CLI paths that want a fresh store can keep using
     * `new MxChat_DuckDB_Vector_Store()` or pass an explicit connection.
     */
    public static function current(): self {
        return self::$current ??= new self();
    }

    public static function reset_current(): void {
        self::$current = null;
    }

    public function __construct(?MxChat_DuckDB_Connection $conn = null) {
        $opts = MxChat_DuckDB_Options::get();
        $this->conn    = $conn ?? MxChat_DuckDB_Connection_Factory::from_options($opts);
        $this->table   = $opts['table_name'];
        $this->dim     = (int) $opts['embedding_dim'];
        $this->metric  = $opts['distance_metric'];
        $this->hnsw    = !empty($opts['hnsw_enabled']);
        $this->storage = in_array($opts['embedding_storage'] ?? 'float32', ['float32', 'int8'], true)
            ? $opts['embedding_storage']
            : 'float32';

        $this->schema = new MxChat_DuckDB_Vector_Store_Schema(
            $this->conn, $this->table, $this->dim, $this->metric, $this->hnsw, $this->storage
        );
        $this->query = new MxChat_DuckDB_Vector_Store_Query(
            $this->conn, $this->table, $this->dim, $this->metric, $this->storage
        );
    }

    // ─── Schema delegation ──────────────────────────────────────────────

    public function ensure_schema(): void { $this->schema->ensure_schema(); }
    public function table_info(): ?array { return $this->schema->table_info(); }

    // ─── Query delegation ───────────────────────────────────────────────

    /**
     * Top-K similarity search returning Pinecone-shaped matches.
     * See MxChat_DuckDB_Vector_Store_Query::run() for the full pipeline.
     */
    public function query_pinecone_shape(array $embedding, int $top_k, string $bot_id = 'default', array $filter = []): array {
        $this->ensure_schema();
        return $this->query->run($embedding, $top_k, $bot_id, $filter);
    }

    // ─── Write path ─────────────────────────────────────────────────────

    /**
     * @param array<int, array{vector_id:string, bot_id?:string, embedding:array,
     *   content?:string, source_url?:string, role_restriction?:string,
     *   content_type?:string, chunk_index?:?int, total_chunks?:?int,
     *   is_chunked?:bool}> $vectors
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

            $embedding_for_storage = $this->storage === 'int8'
                ? MxChat_DuckDB_Quantization::quantize_int8($v['embedding'])
                : $v['embedding'];

            $rows[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                $this->literal_string((string) $v['vector_id']),
                $this->literal_string((string) ($v['bot_id'] ?? 'default')),
                $this->literal_int_or_float_array($embedding_for_storage),
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

    private function upsert_chunk_size(): int {
        $opts = MxChat_DuckDB_Options::get();
        $is_remote = ($opts['mode'] ?? '') === 'motherduck';
        $default = $is_remote ? self::UPSERT_CHUNK_ROWS_HTTP : self::UPSERT_CHUNK_ROWS_LOCAL;
        return (int) apply_filters('mxchat_duckdb_upsert_chunk_size', $default, $is_remote);
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

    // ─── Lookup / inspection ────────────────────────────────────────────

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

    // ─── Parquet I/O ────────────────────────────────────────────────────

    /**
     * Dump the entire table to a Parquet file on the DuckDB-side filesystem.
     * @throws RuntimeException on SQL error.
     */
    public function export_parquet(string $path): int {
        $this->ensure_schema();
        $safe_path = str_replace("'", "''", $path);
        $this->conn->execute(sprintf(
            "COPY %s TO '%s' (FORMAT PARQUET, COMPRESSION ZSTD)",
            $this->quote_ident($this->table),
            $safe_path
        ));
        $rows = $this->conn->execute(sprintf(
            'SELECT COUNT(*) AS c FROM %s',
            $this->quote_ident($this->table)
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Restore rows from a Parquet file produced by export_parquet().
     * Existing vectors with the same vector_id are replaced.
     */
    public function import_parquet(string $path): int {
        $this->ensure_schema();
        $safe_path = str_replace("'", "''", $path);

        $tmp_view = '__mxd_import_' . wp_generate_password(8, false, false);
        $this->conn->execute(sprintf(
            "CREATE OR REPLACE TEMP VIEW %s AS SELECT * FROM read_parquet('%s')",
            $this->quote_ident($tmp_view),
            $safe_path
        ));
        $count_rows = $this->conn->execute(sprintf('SELECT COUNT(*) AS c FROM %s', $this->quote_ident($tmp_view)));
        $expected = (int) ($count_rows[0]['c'] ?? 0);
        if ($expected === 0) return 0;

        $this->conn->execute(sprintf(
            'INSERT OR REPLACE INTO %s SELECT * FROM %s',
            $this->quote_ident($this->table),
            $this->quote_ident($tmp_view)
        ));
        $this->conn->execute(sprintf('DROP VIEW IF EXISTS %s', $this->quote_ident($tmp_view)));

        MxChat_DuckDB_Plugin::flush_query_cache();
        return $expected;
    }

    /**
     * Approximate footprint of the vectors table for the admin diagnostics
     * panel. Returns bytes_estimate + row_count + dim.
     */
    public function storage_estimate(): array {
        try {
            $bytes_per_value = $this->storage === 'int8' ? 1 : 4;
            $row_bytes = $bytes_per_value * $this->dim + 200; // metadata fudge factor
            $rows = $this->conn->execute(sprintf(
                'SELECT COUNT(*) AS c FROM %s',
                $this->quote_ident($this->table)
            ));
            $count = (int) ($rows[0]['c'] ?? 0);
            return [
                'rows'           => $count,
                'bytes_estimate' => $count * $row_bytes,
                'dim'            => $this->dim,
                'storage'        => $this->storage,
            ];
        } catch (\Throwable $e) {
            return ['rows' => 0, 'bytes_estimate' => 0, 'dim' => $this->dim, 'storage' => $this->storage];
        }
    }

    // ─── Accessors ──────────────────────────────────────────────────────

    public function connection(): MxChat_DuckDB_Connection { return $this->conn; }
    public function table_name(): string { return $this->table; }
    public function table_name_quoted(): string { return $this->quote_ident($this->table); }
}
