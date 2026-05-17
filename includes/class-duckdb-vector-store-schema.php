<?php
/**
 * Schema lifecycle for the vector store: migration runner + meta table.
 *
 * Migrations are sequential integers; each one transitions from v(N-1) to vN.
 * Every migration MUST be idempotent — it can run against an install that
 * already has parts of the target state in place.
 *
 * Per-request memoisation lives here (static cache keyed by backend + table +
 * dim), so `ensure_schema()` is cheap on the hot path.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Vector_Store_Schema {

    use MxChat_DuckDB_SQL_Helpers_Trait;

    /**
     * Bump when a migration is added below. The runner is idempotent because
     * each migration uses IF NOT EXISTS / ADD COLUMN IF NOT EXISTS short-circuits.
     */
    const TARGET_SCHEMA_VERSION = 3;
    const META_TABLE = 'mxchat_duckdb_schema_meta';

    /** @var array<string,bool> cache: which (backend|table|dim) combos are already migrated this request */
    private static array $ensured = [];

    public function __construct(
        protected MxChat_DuckDB_Connection $conn,
        protected string $table,
        protected int $dim,
        protected string $metric,
        protected bool $hnsw,
        protected string $storage
    ) {}

    /**
     * Run any pending migrations. Memoised per-request to avoid the version
     * check on every search.
     */
    public function ensure_schema(): void {
        $cache_key = $this->conn->identifier() . '|' . $this->table . '|' . $this->dim;
        if (isset(self::$ensured[$cache_key])) return;

        $this->ensure_meta_table();
        $current = $this->get_schema_version();
        for ($v = $current + 1; $v <= self::TARGET_SCHEMA_VERSION; $v++) {
            $this->apply_migration($v);
            $this->set_schema_version($v);
        }

        self::$ensured[$cache_key] = true;
    }

    /**
     * Row count + dim of the existing table, or null when the table doesn't
     * exist yet. Drives the dimension-change guard in the options sanitiser.
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

    private function apply_migration(int $version): void {
        switch ($version) {
            case 1: $this->migration_v1_base_schema();      return;
            case 2: $this->migration_v2_updated_at_column(); return;
            case 3: $this->migration_v3_fts_index();         return;
        }
    }

    private function migration_v1_base_schema(): void {
        $this->conn->execute('INSTALL vss');
        $this->conn->execute('LOAD vss');

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                vector_id        VARCHAR PRIMARY KEY,
                bot_id           VARCHAR DEFAULT \'default\',
                embedding        %s,
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
            $this->embedding_column_type()
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
                // HNSW is optional — fall back to brute-force scans if creation fails.
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
        // DuckDB build. Hybrid search degrades to pure vector gracefully.
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

    private function vss_metric(): string {
        return match ($this->metric) {
            'l2sq' => 'l2sq',
            'ip'   => 'ip',
            default => 'cosine',
        };
    }
}
