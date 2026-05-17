<?php
/**
 * MotherDuck connection via the official DuckDB MotherDuck extension.
 *
 * MotherDuck is *not* a generic REST/SQL HTTP service — the official client
 * path is: a local DuckDB process loads the `motherduck` extension and runs
 *   ATTACH 'md:<database>?motherduck_token=<token>';
 * then queries pass through DuckDB's native protocol. We piggyback on the
 * Embedded_Connection (PECL ext or duckdb CLI) and inject those init
 * statements as `init_sql`.
 *
 * Note: in CLI fallback mode the ATTACH runs on *every* query, which is slow
 * (network handshake + token validation per call). The admin UI surfaces a
 * warning when CLI + MotherDuck is detected; production deployments should
 * install the PECL duckdb extension.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_MotherDuck_Connection extends MxChat_DuckDB_Embedded_Connection {

    private string $md_database;

    public function __construct(array $opts) {
        $token = (string) ($opts['motherduck_token'] ?? '');
        $database = (string) ($opts['motherduck_database'] ?? 'my_db');

        if ($token === '') {
            throw new RuntimeException(
                __('MotherDuck token is not configured.', 'mxchat-duckdb')
            );
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $database)) {
            throw new RuntimeException(
                __('Invalid MotherDuck database name (letters, digits and underscore only).', 'mxchat-duckdb')
            );
        }

        // Local DuckDB session — use an in-memory DB; all data lives in MotherDuck.
        $local_opts = $opts;
        $local_opts['db_path'] = ':memory:';

        $init = [
            "INSTALL motherduck",
            "LOAD motherduck",
            // Token is interpolated into SQL; we constrain the DB name above and
            // assume the token is a well-formed JWT-like string from MotherDuck.
            // Single quotes inside the token (impossible in practice) are doubled.
            sprintf(
                "ATTACH 'md:%s?motherduck_token=%s'",
                $database,
                str_replace("'", "''", $token)
            ),
            sprintf('USE "%s"', $database),
        ];

        parent::__construct($local_opts, $init);
        $this->md_database = $database;
    }

    public function identifier(): string {
        return 'motherduck:' . $this->md_database . ($this->use_extension ? ' (ext)' : ' (cli)');
    }
}
