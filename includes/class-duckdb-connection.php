<?php
/**
 * Connection abstraction. Factory returns the right backend implementation
 * and caches it for the lifetime of the request to avoid re-spawning CLI
 * processes / re-running ATTACH on every call.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface MxChat_DuckDB_Connection {

    /**
     * Execute a SQL statement. Parameters are bound by ? placeholders.
     * Returns an array of associative-array rows. Empty array on non-SELECT.
     *
     * @throws RuntimeException on any error.
     */
    public function execute(string $sql, array $params = []): array;

    /**
     * Lightweight liveness check. Returns true if the backend responds.
     */
    public function ping(): bool;

    /**
     * Human-readable backend identifier (motherduck:db_name / embedded:/path/to.duckdb).
     */
    public function identifier(): string;
}

class MxChat_DuckDB_Connection_Factory {

    /** @var array<string, MxChat_DuckDB_Connection> */
    private static array $cache = [];

    /**
     * @throws RuntimeException if the configured backend cannot be instantiated.
     */
    public static function from_options(array $opts): MxChat_DuckDB_Connection {
        $key = self::cache_key($opts);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $mode = $opts['mode'] ?? 'motherduck';
        $conn = $mode === 'embedded'
            ? new MxChat_DuckDB_Embedded_Connection($opts)
            : new MxChat_DuckDB_MotherDuck_Connection($opts);

        self::$cache[$key] = $conn;
        return $conn;
    }

    public static function current(): MxChat_DuckDB_Connection {
        return self::from_options(MxChat_DuckDB_Options::get());
    }

    /**
     * Drop any cached connections. Called when options are saved so the next
     * request picks up the new config without a process reload.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }

    private static function cache_key(array $opts): string {
        $mode = $opts['mode'] ?? 'motherduck';
        $fingerprint = [
            $mode,
            $opts['motherduck_database'] ?? '',
            $opts['embedded_path'] ?? '',
            // Include the token's hash, not the token itself.
            isset($opts['motherduck_token']) ? substr(md5((string) $opts['motherduck_token']), 0, 8) : '',
        ];
        return implode('|', $fingerprint);
    }
}
