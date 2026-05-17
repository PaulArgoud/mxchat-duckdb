<?php
/**
 * MySQL → DuckDB pipeline.
 *
 * Bulk + incremental sync of MxChat's MySQL KB table
 * (wp_mxchat_system_prompt_content) into DuckDB, plus the cascade-delete
 * AJAX handler that mirrors mxchat's deletions to DuckDB.
 *
 * MxChat does not expose a "vector saved" hook, so strictly real-time
 * consistency would require polling. The hourly cron is the freshness
 * floor; users can hit "Sync now" for an immediate pass.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Mysql_Sync {

    const BATCH_SIZE = 250;

    /**
     * Full sync from MySQL → DuckDB. Idempotent thanks to the stable
     * vector_id_for_row() scheme. Returns total upserted.
     *
     * @param callable|null $progress fn(int $done, int $total): void
     */
    public function full_sync(?callable $progress = null): int {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        $store = new MxChat_DuckDB_Vector_Store();
        $store->ensure_schema();

        $columns = self::detect_kb_columns($kb);

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$kb}");
        if ($total === 0) {
            MxChat_DuckDB_Options::update(['last_sync_at' => time(), 'last_sync_count' => 0, 'last_error' => '']);
            return 0;
        }

        $done = 0;
        $offset = 0;
        while ($offset < $total) {
            $select = self::build_select($columns, $kb);
            $batch = $wpdb->get_results($wpdb->prepare(
                $select . ' ORDER BY id ASC LIMIT %d OFFSET %d',
                self::BATCH_SIZE,
                $offset
            ));

            if (empty($batch)) break;

            $vectors = [];
            foreach ($batch as $row) {
                $v = self::row_to_vector($row, $columns);
                if ($v !== null) $vectors[] = $v;
            }

            if (!empty($vectors)) {
                $store->upsert($vectors);
                $done += count($vectors);
            }

            $offset += self::BATCH_SIZE;
            if ($progress) {
                $progress(min($offset, $total), $total);
            }
        }

        MxChat_DuckDB_Options::update([
            'last_sync_at'    => time(),
            'last_sync_count' => $done,
            'last_error'      => '',
        ]);

        return $done;
    }

    /**
     * Picks up rows whose `timestamp` is newer than last_sync_at. Conservative
     * — also re-syncs the last few minutes to absorb clock skew.
     */
    public function incremental_sync(): int {
        global $wpdb;
        $kb = $wpdb->prefix . 'mxchat_system_prompt_content';

        $opts = MxChat_DuckDB_Options::get();
        if (empty($opts['enabled'])) return 0;

        $since = max(0, (int) $opts['last_sync_at'] - 120);
        $since_sql = gmdate('Y-m-d H:i:s', $since);

        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $columns = self::detect_kb_columns($kb);

            $select = self::build_select($columns, $kb);
            $rows = $wpdb->get_results($wpdb->prepare(
                $select . ' WHERE timestamp >= %s ORDER BY id ASC',
                $since_sql
            ));

            if (empty($rows)) {
                MxChat_DuckDB_Options::update(['last_sync_at' => time(), 'last_error' => '']);
                return 0;
            }

            $vectors = [];
            foreach ($rows as $row) {
                $v = self::row_to_vector($row, $columns);
                if ($v !== null) $vectors[] = $v;
            }

            $count = 0;
            if (!empty($vectors)) {
                $count = $store->upsert($vectors);
            }

            MxChat_DuckDB_Options::update([
                'last_sync_at' => time(),
                'last_error'   => '',
            ]);

            return $count;
        } catch (\Throwable $e) {
            error_log('[mxchat-duckdb] incremental_sync: ' . $e->getMessage());
            MxChat_DuckDB_Options::update(['last_error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * AJAX hook fired by mxchat's own delete action. We re-check the nonce +
     * capability ourselves instead of relying on mxchat's check running after
     * — defense in depth against priority changes upstream.
     */
    public function cascade_delete_handler(): void {
        $opts = MxChat_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;

        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        $nonce_ok = $nonce !== '' && (
            wp_verify_nonce($nonce, 'mxchat_delete_pinecone_prompt')
            || wp_verify_nonce($nonce, 'mxchat_duckdb_admin')
        );
        if (!$nonce_ok || !current_user_can('manage_options')) {
            return;
        }

        $vector_id = isset($_POST['vector_id']) ? sanitize_text_field(wp_unslash($_POST['vector_id'])) : '';
        $bot_id    = isset($_POST['bot_id']) ? sanitize_text_field(wp_unslash($_POST['bot_id'])) : 'default';

        if (empty($vector_id)) return;

        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $store->delete_by_ids([$vector_id], $bot_id);
        } catch (\Throwable $e) {
            MxChat_DuckDB_Options::update(['last_error' => 'cascade delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Vector ID scheme aligned with mxchat's: md5(source_url) for URL-based
     * entries, fallback to "mxchat_kb_{id}" for manual rows without a URL.
     * Public + static so the compactor and tests can call it without a
     * Mysql_Sync instance.
     */
    public static function vector_id_for_row($row): string {
        $url = (string) ($row->source_url ?? '');
        if ($url !== '') return md5($url);
        return 'mxchat_kb_' . (int) ($row->id ?? 0);
    }

    /**
     * Inspect the mxchat KB table once per sync to figure out which optional
     * columns are present (notably `bot_id`, which only some mxchat versions
     * carry). Cached for the request.
     *
     * @return array{has_bot_id:bool}
     */
    public static function detect_kb_columns(string $kb_table): array {
        static $cache = [];
        if (isset($cache[$kb_table])) return $cache[$kb_table];

        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$kb_table}", 0);
        $set = is_array($cols) ? array_flip($cols) : [];

        return $cache[$kb_table] = [
            'has_bot_id' => isset($set['bot_id']),
        ];
    }

    private static function build_select(array $columns, string $kb_table): string {
        $fields = 'id, url AS source_url, article_content, embedding_vector, role_restriction, content_type';
        if (!empty($columns['has_bot_id'])) {
            $fields .= ', bot_id';
        }
        return "SELECT {$fields} FROM {$kb_table}";
    }

    /**
     * Hydrate one MySQL KB row into a vector ready for DuckDB upsert. Returns
     * null when the row has no usable embedding.
     */
    private static function row_to_vector($row, array $columns): ?array {
        $embedding = $row->embedding_vector
            ? @unserialize($row->embedding_vector, ['allowed_classes' => false])
            : null;
        if (!is_array($embedding) || empty($embedding)) {
            return null;
        }

        $bot_id = !empty($columns['has_bot_id']) && !empty($row->bot_id)
            ? (string) $row->bot_id
            : 'default';

        $bot_id = (string) apply_filters('mxchat_duckdb_sync_bot_id', $bot_id, $row);

        return [
            'vector_id'        => self::vector_id_for_row($row),
            'bot_id'           => $bot_id ?: 'default',
            'embedding'        => $embedding,
            'content'          => (string) $row->article_content,
            'source_url'       => (string) ($row->source_url ?? ''),
            'role_restriction' => (string) ($row->role_restriction ?? 'public'),
            'content_type'     => (string) ($row->content_type ?? 'content'),
            'chunk_index'      => null,
            'total_chunks'     => null,
            'is_chunked'       => false,
        ];
    }
}
