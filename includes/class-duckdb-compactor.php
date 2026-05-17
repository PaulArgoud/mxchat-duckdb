<?php
/**
 * Compactor: nightly job that prunes orphan vectors.
 *
 * A vector is orphan when:
 *   - its source_url no longer maps to any row in wp_mxchat_system_prompt_content
 *     (post deleted in WP, mxchat row removed, cascade-delete missed for any reason);
 *   OR
 *   - its bot_id no longer matches any known bot (kept best-effort, optional).
 *
 * The compactor is conservative: it never runs if last sync was within the past
 * hour (the sync may be mid-transit), and it caps the per-run delete count
 * (filter `mxchat_duckdb_compactor_max_deletes`, default 5000) to avoid blowing
 * up MotherDuck billing on a misconfigured install.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Compactor {

    private static ?self $instance = null;
    const CRON_HOOK = 'mxchat_duckdb_compact';
    const MIN_SYNC_AGE_SECONDS = 3600;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        add_action(self::CRON_HOOK, [$this, 'run']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(self::next_run_timestamp(), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Anchor the first run at 03:00 UTC + a deterministic per-install jitter
     * (0–59 min, derived from home_url()) so:
     *   - we land in a low-traffic window (~04:00–05:00 in most EU timezones);
     *   - many installs running this plugin don't all hit MotherDuck at the
     *     same UTC minute, which would otherwise look like a coordinated load
     *     spike to the upstream;
     *   - the schedule is stable across activations on the same site (jitter
     *     is hashed from a value that doesn't change).
     */
    private static function next_run_timestamp(): int {
        $jitter_minutes = abs(crc32(home_url())) % 60;
        $base = strtotime('today 03:00 UTC');
        if ($base === false) $base = time();
        $first = $base + ($jitter_minutes * MINUTE_IN_SECONDS);
        return $first > time() ? $first : $first + DAY_IN_SECONDS;
    }

    public function run(): array {
        $opts = MxChat_DuckDB_Options::get();
        if (empty($opts['enabled'])) {
            return ['ok' => false, 'reason' => 'plugin disabled', 'deleted' => 0];
        }
        if (!empty($opts['last_sync_at']) && (time() - (int) $opts['last_sync_at']) < self::MIN_SYNC_AGE_SECONDS) {
            return ['ok' => false, 'reason' => 'last sync too recent', 'deleted' => 0];
        }

        $max = (int) apply_filters('mxchat_duckdb_compactor_max_deletes', 5000);
        $deleted = 0;

        try {
            $deleted = $this->prune_orphans($max);
            MxChat_DuckDB_Options::update([
                'last_compact_at' => time(),
                'last_error'      => '',
            ]);
            return ['ok' => true, 'deleted' => $deleted];
        } catch (\Throwable $e) {
            error_log('[mxchat-duckdb] compactor: ' . $e->getMessage());
            MxChat_DuckDB_Options::update(['last_error' => 'compactor: ' . $e->getMessage()]);
            return ['ok' => false, 'reason' => $e->getMessage(), 'deleted' => $deleted];
        }
    }

    const KB_PAGE_SIZE = 5000;

    /**
     * Strategy: build a set of "alive" vector_ids by paging through the MySQL
     * KB (avoids one ~20 MB allocation for big KBs — PHP can free each batch's
     * row objects as we move on, keeping only the much smaller id-only map
     * around). Then page through DuckDB and delete everything that isn't in
     * that set. DELETE is chunked to 100 IDs at a time to stay under
     * MotherDuck HTTP body limits.
     */
    private function prune_orphans(int $max_deletes): int {
        $alive = $this->load_alive_ids();

        $store = new MxChat_DuckDB_Vector_Store();
        $store->ensure_schema();
        $conn = $store->connection();

        // Page through DuckDB vector_ids and remove orphans in batches.
        $deleted = 0;
        $page_size = 1000;
        $offset = 0;
        $orphans = [];

        while ($deleted < $max_deletes) {
            $page = $conn->execute(sprintf(
                'SELECT vector_id FROM %s ORDER BY vector_id LIMIT %d OFFSET %d',
                $store->table_name_quoted(),
                $page_size,
                $offset
            ));
            if (empty($page)) break;

            foreach ($page as $row) {
                $id = (string) ($row['vector_id'] ?? '');
                if ($id !== '' && !isset($alive[$id])) {
                    $orphans[] = $id;
                }
            }
            $offset += $page_size;

            // Drain orphan buffer in chunks of 100 to avoid massive IN(…) lists.
            while (count($orphans) >= 100 && $deleted < $max_deletes) {
                $chunk = array_splice($orphans, 0, 100);
                $deleted += $this->delete_chunk($conn, $store->table_name_quoted(), $chunk);
            }
        }

        // Final flush.
        while (!empty($orphans) && $deleted < $max_deletes) {
            $chunk = array_splice($orphans, 0, 100);
            $deleted += $this->delete_chunk($conn, $store->table_name_quoted(), $chunk);
        }

        return $deleted;
    }

    private function delete_chunk(MxChat_DuckDB_Connection $conn, string $quoted_table, array $ids): int {
        $list = implode(',', array_map(fn($id) => "'" . str_replace("'", "''", $id) . "'", $ids));
        $conn->execute(sprintf(
            'DELETE FROM %s WHERE vector_id IN (%s)',
            $quoted_table,
            $list
        ));
        return count($ids);
    }

    /**
     * Stream mxchat KB rows in pages so a 100k-row KB doesn't allocate ~20 MB
     * of $wpdb objects in one shot. Returns a vector_id → true map (a few MB
     * even at 100k entries — orders of magnitude smaller than the row objects).
     *
     * @return array<string, true>
     */
    private function load_alive_ids(): array {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        $alive = [];
        $offset = 0;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, url AS source_url FROM {$kb} ORDER BY id LIMIT %d OFFSET %d",
                self::KB_PAGE_SIZE,
                $offset
            ));
            if ($rows === null) {
                throw new RuntimeException('MySQL KB table unreadable; aborting compaction.');
            }
            if (empty($rows)) break;
            foreach ($rows as $r) {
                $alive[MxChat_DuckDB_Sync::vector_id_for_row($r)] = true;
            }
            $offset += self::KB_PAGE_SIZE;
            unset($rows); // let PHP reclaim the batch before fetching the next
        }
        return $alive;
    }
}
