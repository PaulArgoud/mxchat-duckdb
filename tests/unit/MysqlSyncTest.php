<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the MySQL → DuckDB sync pipeline (the bulk-copy path that
 * brings existing mxchat-basic installs onto DuckDB) and the cascade-
 * delete handler (the AJAX hook that mirrors mxchat's deletions).
 *
 * Two areas where a regression hurts:
 *   - silent data loss during sync (rows skipped without warning, the
 *     scenario the v0.6.0 "log skipped > 1%" guard was added to surface);
 *   - cascade-delete authorisation bypass (a missing nonce check would
 *     let any logged-out request delete vectors).
 *
 * The orchestration touches both $wpdb and Vector_Store — we install a
 * recording WPDB mock and inject a mock connection into
 * Connection_Factory::$cache so `new Vector_Store()` inside full_sync()
 * sees our stub instead of trying to spin up a real DuckDB.
 */
final class MysqlSyncTest extends TestCase {

    private MxChat_Test_WPDB $wpdb;
    private $mock_conn; // anonymous-class instance; recorded SQL lives on it.

    protected function setUp(): void {
        $GLOBALS['__test_options']         = [];
        $GLOBALS['__test_transients']      = [];
        $GLOBALS['__test_valid_nonces']    = [];
        $GLOBALS['__test_current_user_can'] = true;

        $this->wpdb = new MxChat_Test_WPDB();
        // Unique prefix per test so detect_kb_columns()' static-variable
        // cache doesn't leak across tests within the same PHP process.
        // PHP doesn't let us clear function-level statics externally;
        // changing the table name moves us to a fresh cache slot.
        $this->wpdb->prefix = 'wp_t' . bin2hex(random_bytes(3)) . '_';
        $GLOBALS['wpdb'] = $this->wpdb;

        // Wipe the per-request memoisation caches that would otherwise
        // leak between tests.
        $r = new ReflectionProperty(MxChat_DuckDB_Vector_Store_Schema::class, 'ensured');
        $r->setAccessible(true);
        $r->setValue(null, []);

        // Static cache in Mysql_Sync::detect_kb_columns.
        $rm = new ReflectionMethod(MxChat_DuckDB_Mysql_Sync::class, 'detect_kb_columns');
        $rm->setAccessible(true);
        // Force re-evaluation by clearing PHP's static via the same trick:
        // call it once on a "primer" table that won't exist later so the
        // static cache holds an unused entry. Each real test uses its own
        // distinct table name, dodging the cache.
        // (PHP doesn't expose function-level statics; the test names avoid
        // collision with each other instead.)

        // Inject a recording mock connection into Connection_Factory::$cache
        // so `new MxChat_DuckDB_Vector_Store()` inside the sync routines
        // doesn't try to instantiate a real DuckDB backend.
        $this->mock_conn = new class implements MxChat_DuckDB_Connection {
            public array $log = [];
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                // Schema's `SELECT value FROM …schema_meta` should return v3
                // so ensure_schema() short-circuits with no extra DDL.
                if (stripos($sql, 'schema_meta') !== false && stripos($sql, 'SELECT value') !== false) {
                    return [['value' => '3']];
                }
                return [];
            }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'mock:sync'; }
        };

        MxChat_DuckDB_Connection_Factory::reset_cache();
        $r2 = new ReflectionProperty(MxChat_DuckDB_Connection_Factory::class, 'cache');
        $r2->setAccessible(true);
        // Tests use 3-dim embeddings for readability; align the option so
        // Vector_Store's upsert guard doesn't reject the synthetic rows.
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
        ]));
        $opts = MxChat_DuckDB_Options::get();
        // Reproduce Connection_Factory::cache_key($opts) so the injection
        // hits the slot Vector_Store will look up.
        $rk = new ReflectionMethod(MxChat_DuckDB_Connection_Factory::class, 'cache_key');
        $rk->setAccessible(true);
        $key = $rk->invoke(null, $opts);
        $r2->setValue(null, [$key => $this->mock_conn]);
    }

    // ─── detect_kb_columns ────────────────────────────────────────────────

    public function test_detect_kb_columns_reports_bot_id_presence(): void {
        $this->wpdb->set_response('SHOW COLUMNS FROM wp_kb_a', ['id', 'url', 'embedding_vector', 'bot_id']);
        $cols = MxChat_DuckDB_Mysql_Sync::detect_kb_columns('wp_kb_a');
        $this->assertTrue($cols['has_bot_id']);
    }

    public function test_detect_kb_columns_reports_missing_bot_id(): void {
        $this->wpdb->set_response('SHOW COLUMNS FROM wp_kb_b', ['id', 'url', 'embedding_vector']);
        $cols = MxChat_DuckDB_Mysql_Sync::detect_kb_columns('wp_kb_b');
        $this->assertFalse($cols['has_bot_id']);
    }

    public function test_detect_kb_columns_caches_per_table_name(): void {
        $this->wpdb->set_response('SHOW COLUMNS FROM wp_kb_c', ['id']);
        MxChat_DuckDB_Mysql_Sync::detect_kb_columns('wp_kb_c');
        $initial_calls = count($this->wpdb->log);
        MxChat_DuckDB_Mysql_Sync::detect_kb_columns('wp_kb_c');
        $this->assertSame($initial_calls, count($this->wpdb->log),
            'second call for the same table must hit the static cache');
    }

    // ─── Full sync ────────────────────────────────────────────────────────

    public function test_full_sync_with_empty_kb_returns_zero_and_stamps_last_sync(): void {
        $this->wpdb->set_response('SELECT COUNT(*)', 0);

        $sync = new MxChat_DuckDB_Mysql_Sync();
        $this->assertSame(0, $sync->full_sync());

        $opts = get_option('mxchat_duckdb_options');
        $this->assertNotEmpty($opts['last_sync_at'], 'empty sync still bumps last_sync_at');
        $this->assertSame(0, (int) $opts['last_sync_count']);
    }

    public function test_full_sync_upserts_decoded_rows_and_skips_unusable_ones(): void {
        $good_embedding = serialize([0.1, 0.2, 0.3]);
        // Row 1: good. Row 2: empty embedding. Row 3: non-array embedding.
        $rows = [
            (object) ['id' => 1, 'source_url' => 'https://a/', 'article_content' => 'foo',
                      'embedding_vector' => $good_embedding,
                      'role_restriction' => 'public', 'content_type' => 'post'],
            (object) ['id' => 2, 'source_url' => 'https://b/', 'article_content' => 'bar',
                      'embedding_vector' => '',
                      'role_restriction' => 'public', 'content_type' => 'post'],
            (object) ['id' => 3, 'source_url' => 'https://c/', 'article_content' => 'baz',
                      'embedding_vector' => serialize('not-an-array'),
                      'role_restriction' => 'public', 'content_type' => 'post'],
        ];
        $this->wpdb->set_response('SELECT COUNT(*)', 3);
        $this->wpdb->set_response('SELECT id, url AS source_url', $rows);

        $sync = new MxChat_DuckDB_Mysql_Sync();
        $count = $sync->full_sync();

        $this->assertSame(1, $count, '2 rows must be skipped (empty + non-array embedding)');

        // The mock connection saw an INSERT OR REPLACE for the one good row.
        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString('INSERT OR REPLACE INTO "mxchat_vectors"', $log);
        $this->assertSame(1, substr_count($log, 'INSERT OR REPLACE'),
            'exactly one batched upsert for the single usable row');

        $opts = get_option('mxchat_duckdb_options');
        $this->assertSame(1, (int) $opts['last_sync_count']);
    }

    public function test_full_sync_propagates_bot_id_when_column_exists(): void {
        // Use 'SHOW COLUMNS FROM' as the pattern — unique to detect_kb_columns
        // and doesn't collide with the SELECT COUNT / SELECT id queries.
        $this->wpdb->set_response('SELECT COUNT(*)', 1);
        $this->wpdb->set_response('SELECT id, url AS source_url', [
            (object) [
                'id' => 1, 'source_url' => 'https://x/', 'article_content' => 'hi',
                'embedding_vector' => serialize([0.1, 0.2, 0.3]),
                'role_restriction' => 'public', 'content_type' => 'post',
                'bot_id' => 'support_fr',
            ],
        ]);
        $this->wpdb->set_response('SHOW COLUMNS FROM',
            ['id', 'url', 'article_content', 'embedding_vector', 'role_restriction', 'content_type', 'bot_id']);

        (new MxChat_DuckDB_Mysql_Sync())->full_sync();

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'support_fr'", $log,
            'bot_id from KB column must reach the INSERT OR REPLACE literal');
    }

    // ─── Incremental sync ─────────────────────────────────────────────────

    public function test_incremental_sync_filters_by_last_sync_minus_two_minutes(): void {
        // Seed last_sync_at = a fixed timestamp; verify the WHERE clause
        // uses the right cutoff (last_sync_at - 120 seconds to absorb clock skew).
        $last = 1700000000;
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, [
            'enabled' => true,
            'last_sync_at' => $last,
        ]));

        $this->wpdb->set_response('SELECT id, url AS source_url', []);
        (new MxChat_DuckDB_Mysql_Sync())->incremental_sync();

        $sql = implode("\n", $this->wpdb->log);
        $expected_cutoff = gmdate('Y-m-d H:i:s', $last - 120);
        $this->assertStringContainsString($expected_cutoff, $sql,
            'incremental WHERE must cut at last_sync_at - 120 seconds');
    }

    public function test_incremental_sync_returns_zero_when_disabled(): void {
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, ['enabled' => false]));

        $this->assertSame(0, (new MxChat_DuckDB_Mysql_Sync())->incremental_sync());
    }

    // ─── Cascade-delete handler authorisation ─────────────────────────────

    public function test_cascade_delete_rejects_request_with_no_nonce(): void {
        $_POST = ['vector_id' => 'vec_to_delete'];
        (new MxChat_DuckDB_Mysql_Sync())->cascade_delete_handler();

        $this->assertEmpty($this->mock_conn->log,
            'no DELETE must be issued when the nonce is missing');
    }

    public function test_cascade_delete_rejects_request_with_wrong_nonce(): void {
        $_POST = ['_wpnonce' => 'bogus', 'vector_id' => 'vec_to_delete'];
        (new MxChat_DuckDB_Mysql_Sync())->cascade_delete_handler();

        $this->assertEmpty($this->mock_conn->log);
    }

    public function test_cascade_delete_rejects_user_without_capability(): void {
        $GLOBALS['__test_current_user_can'] = false;
        $GLOBALS['__test_valid_nonces'] = ['legit' => 'mxchat_delete_pinecone_prompt'];
        $_POST = ['_wpnonce' => 'legit', 'vector_id' => 'vec_to_delete'];

        (new MxChat_DuckDB_Mysql_Sync())->cascade_delete_handler();
        $this->assertEmpty($this->mock_conn->log,
            'capability check must run AND short-circuit');
    }

    public function test_cascade_delete_accepts_mxchat_nonce_and_fires_delete(): void {
        $GLOBALS['__test_valid_nonces'] = ['legit' => 'mxchat_delete_pinecone_prompt'];
        $_POST = ['_wpnonce' => 'legit', 'vector_id' => 'vec_42', 'bot_id' => 'support_fr'];

        (new MxChat_DuckDB_Mysql_Sync())->cascade_delete_handler();

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $log);
        $this->assertStringContainsString("'vec_42'", $log);
        $this->assertStringContainsString("'support_fr'", $log);
    }

    public function test_cascade_delete_also_accepts_the_plugin_admin_nonce(): void {
        // The handler accepts a fallback nonce so legacy mxchat installs
        // that don't ship the delete-specific nonce keep working. See the
        // docblock in class-duckdb-mysql-sync.php for the rationale.
        $GLOBALS['__test_valid_nonces'] = ['legit' => 'mxchat_duckdb_admin'];
        $_POST = ['_wpnonce' => 'legit', 'vector_id' => 'vec_x'];

        (new MxChat_DuckDB_Mysql_Sync())->cascade_delete_handler();

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString('DELETE FROM "mxchat_vectors"', $log);
    }
}
