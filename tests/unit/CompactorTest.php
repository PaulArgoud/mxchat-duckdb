<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the orphan compactor — the daily cron job that prunes DuckDB
 * vectors whose MySQL KB row has been deleted out from under them.
 *
 * The compactor is destructive (DELETE) and runs unattended via cron;
 * the things that MUST hold:
 *   - it doesn't run when the plugin is disabled or when the last sync
 *     was very recent (a deletion may be a mid-sync transient);
 *   - it never deletes more than mxchat_duckdb_compactor_max_deletes per
 *     run (default 5000) — guards a runaway delete on a misconfigured
 *     install from blowing through MotherDuck billing;
 *   - the MySQL KB scan is paginated so a 100k-row KB doesn't allocate
 *     ~20 MB of $wpdb row objects in one shot (v0.6.0 fix);
 *   - orphans get deleted, alive vectors stay.
 */
final class CompactorTest extends TestCase {

    private MxChat_Test_WPDB $wpdb;
    private $mock_conn;

    protected function setUp(): void {
        $GLOBALS['__test_options']    = [];
        $GLOBALS['__test_transients'] = [];

        $this->wpdb = new MxChat_Test_WPDB();
        $this->wpdb->prefix = 'wp_c' . bin2hex(random_bytes(3)) . '_';
        $GLOBALS['wpdb'] = $this->wpdb;

        MxChat_Test_Helpers::reset_schema_memoisation();

        // The compactor's prune_orphans paginates DuckDB vector_id pages —
        // we set $pages + $page_index on the recording connection to feed
        // the loop and break it.
        $this->mock_conn = new class('mock:compactor') extends MxChat_Test_RecordingConnection {
            public array $pages = [];
            public int $page_index = 0;
            public function execute(string $sql, array $params = []): array {
                $this->log[] = $sql;
                if (stripos($sql, 'schema_meta') !== false && stripos($sql, 'SELECT value') !== false) {
                    return [['value' => '3']];
                }
                if (stripos($sql, 'SELECT vector_id FROM') !== false) {
                    return $this->pages[$this->page_index++] ?? [];
                }
                return [];
            }
        };

        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, [
            'enabled'       => true,
            'embedding_dim' => 3,
            'last_sync_at'  => time() - 7200, // 2h ago — past the 1h freshness floor
        ]));

        MxChat_Test_Helpers::inject_mock_connection($this->mock_conn);
    }

    private function compactor(): MxChat_DuckDB_Compactor {
        // Fresh instance — the singleton would hold the WP-cron state across
        // tests but a `new` works because register_hooks() isn't called here.
        return new MxChat_DuckDB_Compactor();
    }

    // ─── Skip paths ───────────────────────────────────────────────────────

    public function test_compactor_skips_when_plugin_disabled(): void {
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, ['enabled' => false]));

        $result = $this->compactor()->run();

        $this->assertFalse($result['ok']);
        $this->assertSame('plugin disabled', $result['reason']);
        $this->assertSame(0, $result['deleted']);
        $this->assertEmpty($this->mock_conn->log, 'no SQL must be issued when disabled');
    }

    public function test_compactor_skips_when_last_sync_too_recent(): void {
        // last_sync_at < MIN_SYNC_AGE_SECONDS (3600s) — sync may still be
        // mid-flight, the "deleted" rows might just be in-transit.
        $defaults = MxChat_DuckDB_Options::defaults();
        update_option('mxchat_duckdb_options', array_merge($defaults, [
            'enabled'      => true,
            'last_sync_at' => time() - 60,
        ]));

        $result = $this->compactor()->run();
        $this->assertFalse($result['ok']);
        $this->assertSame('last sync too recent', $result['reason']);
        $this->assertEmpty($this->mock_conn->log);
    }

    // ─── Happy path ───────────────────────────────────────────────────────

    public function test_compactor_deletes_only_orphan_vector_ids(): void {
        // Alive set (per MySQL): vectors keyed off two URLs + one id-only row.
        $alive_url_a = (object) ['id' => 1, 'source_url' => 'https://example.com/a'];
        $alive_url_b = (object) ['id' => 2, 'source_url' => 'https://example.com/b'];
        $alive_id   = (object) ['id' => 3, 'source_url' => ''];
        // Paginated response: first page returns the alive rows, second page
        // is empty so load_alive_ids() terminates its pagination loop.
        $alive_rows = [$alive_url_a, $alive_url_b, $alive_id];
        $this->wpdb->set_response('SELECT id, url AS source_url', function ($sql) use ($alive_rows) {
            return (stripos($sql, 'OFFSET 0') !== false) ? $alive_rows : [];
        });

        $alive_ids = [
            MxChat_DuckDB_Sync::vector_id_for_row($alive_url_a),
            MxChat_DuckDB_Sync::vector_id_for_row($alive_url_b),
            MxChat_DuckDB_Sync::vector_id_for_row($alive_id),
        ];

        // DuckDB page: 2 alive + 2 orphan + end-of-pages.
        $this->mock_conn->pages = [
            [
                ['vector_id' => $alive_ids[0]],
                ['vector_id' => $alive_ids[1]],
                ['vector_id' => 'orphan_one'],
                ['vector_id' => 'orphan_two'],
            ],
            [], // empty page → loop terminates
        ];

        $result = $this->compactor()->run();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['deleted'], 'both orphans must be deleted, neither alive');

        $log = implode("\n", $this->mock_conn->log);
        $this->assertStringContainsString("'orphan_one'", $log);
        $this->assertStringContainsString("'orphan_two'", $log);
        $this->assertStringNotContainsString("'" . $alive_ids[0] . "'", $log,
            'alive vector_id must NOT appear in any DELETE');
    }

    public function test_max_deletes_cap_is_respected(): void {
        // Provide 250 orphans across 3 pages but cap to 100 per run.
        // The compactor chunks DELETE by 100 ids; we expect exactly one
        // chunk to fire.
        $orphans = [];
        for ($i = 0; $i < 250; $i++) {
            $orphans[] = ['vector_id' => 'orphan_' . $i];
        }
        $this->mock_conn->pages = [array_slice($orphans, 0, 1000), []];
        // No alive vectors; paginated empty response so load_alive_ids
        // terminates immediately.
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });

        $GLOBALS['__test_filter_overrides']['mxchat_duckdb_compactor_max_deletes'] = 100;
        try {
            $result = $this->compactor()->run();
        } finally {
            $GLOBALS['__test_filter_overrides'] = [];
        }

        $this->assertSame(100, $result['deleted'], 'max_deletes filter must cap the delete count');
    }

    // ─── KB pagination (v0.6.0 memory fix) ────────────────────────────────

    public function test_kb_is_paginated_so_huge_kbs_dont_load_all_in_one_shot(): void {
        // Paginated empty response so load_alive_ids() terminates on first page.
        $this->wpdb->set_response('SELECT id, url AS source_url', function () { return []; });
        $this->mock_conn->pages = [[]];

        $this->compactor()->run();

        $kb_select = '';
        foreach ($this->wpdb->log as $sql) {
            if (stripos($sql, 'SELECT id, url AS source_url') !== false) {
                $kb_select = $sql;
                break;
            }
        }
        $this->assertNotEmpty($kb_select, 'compactor must hit the KB to build the alive set');
        // The query must include a LIMIT clause (paginated) — the v0.6.0
        // fix was specifically to avoid a single get_results() for 100k rows.
        $this->assertMatchesRegularExpression('/LIMIT \d+/i', $kb_select,
            'KB SELECT must be paginated (LIMIT clause)');
        $this->assertMatchesRegularExpression('/OFFSET \d+/i', $kb_select);
    }

    public function test_throws_when_kb_table_is_unreadable(): void {
        // get_results returns null instead of an array — the original code
        // checks for $rows === null and throws "MySQL KB table unreadable".
        $this->wpdb->set_response('SELECT id, url AS source_url', null);
        $this->mock_conn->pages = [[]];

        $result = $this->compactor()->run();
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unreadable', $result['reason']);
    }
}

