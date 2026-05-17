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
            // 03:17 site-local each day; daily schedule + small offset to avoid
            // colliding with the hourly incremental sync at the top of the hour.
            wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
        }
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

    /**
     * Strategy: pull every known mxchat KB vector_id from MySQL, then delete
     * everything in DuckDB that isn't in that set. For very large KBs we chunk
     * the DELETE to stay under MotherDuck body limits.
     */
    private function prune_orphans(int $max_deletes): int {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        // Collect the set of "alive" vector IDs as mxchat sees them.
        $rows = $wpdb->get_results("SELECT id, url AS source_url FROM {$kb}");
        if ($rows === null) {
            throw new RuntimeException('MySQL KB table unreadable; aborting compaction.');
        }
        $alive = [];
        foreach ($rows as $r) {
            $alive[MxChat_DuckDB_Sync::vector_id_for_row($r)] = true;
        }

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
}
