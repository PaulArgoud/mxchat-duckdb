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
     * Capability tokens used by callers that need to adapt their behaviour
     * to what the backend can actually do. Connections answer via
     * supports_capability(). Tokens are namespaced strings so the set is
     * extensible without an interface bump every time a new gap is found.
     *
     * Currently used:
     *   - CAP_VSS_PERSISTENT_INDEX: backend stores CREATE INDEX … USING HNSW
     *     persistently and queries can leverage it. False on MotherDuck cloud
     *     (the VSS extension is not supported cloud-side, see
     *     https://motherduck.com/docs/concepts/duckdb-extensions/). True on
     *     local DuckDB (embedded mode).
     */
    public const CAP_VSS_PERSISTENT_INDEX = 'vss.persistent_index';

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

    /**
     * Capability probe. Implementations should return the conservative
     * answer when in doubt — returning `true` for a capability the backend
     * doesn't actually have will lead to silent failure or wasted work,
     * whereas returning `false` only degrades to a fallback path.
     *
     * Unknown capability tokens MUST return false (forward-compat: a
     * caller asking about a brand-new capability gets a clean "no, plan
     * accordingly" instead of a fatal).
     */
    public function supports_capability(string $capability): bool;
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
