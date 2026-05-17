<?php
/**
 * Sync MxChat's MySQL knowledge base into DuckDB.
 *
 *   - Initial / bulk sync: triggered from the admin UI. Iterates the
 *     mxchat_system_prompt_content table in batches, deserializes each
 *     row's embedding_vector, and upserts into DuckDB.
 *   - Incremental sync: an hourly WP-cron job that picks up rows whose
 *     timestamp is newer than last_sync_at, plus cascading deletes via
 *     the wp_ajax_mxchat_delete_pinecone_prompt hook.
 *
 * MxChat does not expose a "vector saved" hook, so we cannot achieve
 * strictly real-time consistency without polling. The cron interval is
 * the freshness floor; users can hit "Sync now" for immediate sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Sync {

    private static ?self $instance = null;

    const BATCH_SIZE = 250;
    const CRON_HOOK = 'mxchat_duckdb_incremental_sync';

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        add_action(self::CRON_HOOK, [$this, 'incremental_sync']);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 3600, 'hourly', self::CRON_HOOK);
        }

        // Cascade deletes: when mxchat deletes a vector via its admin AJAX, mirror to DuckDB.
        add_action('wp_ajax_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);

        // Also hook the action fired for the basic delete pathway if present.
        add_action('admin_post_mxchat_delete_pinecone_prompt', [$this, 'cascade_delete_handler'], 1);
    }

    /**
     * Full sync from MySQL → DuckDB. Runs in batches; returns total upserted.
     *
     * @param callable|null $progress  fn(int $done, int $total): void
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
     * Picks up rows whose `timestamp` is newer than last_sync_at. Conservative —
     * also re-syncs the last few minutes to absorb clock skew.
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
     * AJAX hook entry point fired by mxchat's delete actions. We extract the
     * vector_id from $_POST and propagate the delete to DuckDB.
     *
     * Auth: this handler is called *before* mxchat itself validates the nonce
     * (priority 1). We therefore re-check the same nonce + capability ourselves
     * rather than relying on mxchat's check running afterwards — if mxchat
     * ever changes its priority, ordering, or removes its check, we still
     * refuse to act on unauthenticated POST data.
     */
    public function cascade_delete_handler(): void {
        $opts = MxChat_DuckDB_Options::get();
        if (empty($opts['enabled'])) return;

        // Mirror mxchat's auth model: nonce + capability. We accept either
        // mxchat's own nonce action (so we don't break its flow) or our own.
        $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
        $nonce_ok = $nonce !== '' && (
            wp_verify_nonce($nonce, 'mxchat_delete_pinecone_prompt')
            || wp_verify_nonce($nonce, 'mxchat_duckdb_admin')
        );
        if (!$nonce_ok || !current_user_can('manage_options')) {
            // Don't return an error — let mxchat handle the rejection — but
            // don't act on the request either.
            return;
        }

        $vector_id = isset($_POST['vector_id']) ? sanitize_text_field(wp_unslash($_POST['vector_id'])) : '';
        $bot_id    = isset($_POST['bot_id']) ? sanitize_text_field(wp_unslash($_POST['bot_id'])) : 'default';

        if (empty($vector_id)) return;

        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $store->delete_by_ids([$vector_id], $bot_id);
        } catch (\Throwable $e) {
            // Best effort — log but do not interrupt mxchat's own deletion.
            MxChat_DuckDB_Options::update(['last_error' => 'cascade delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Reprocess WordPress posts → DuckDB, by calling MxChat_Utils::submit_content_to_db()
     * for each post. Because our plugin advertises DuckDB as the Pinecone backend via
     * `mxchat_get_bot_pinecone_config`, mxchat will route the upsert through our REST
     * proxy → DuckDB. This is the recommended path when the user has been running
     * Pinecone-only and the MySQL KB table is empty/stale.
     *
     * Returns an array { 'processed': int, 'failed': int, 'next_offset': ?int }.
     * Callers should re-invoke with the returned next_offset until it's null, to
     * keep each HTTP request under PHP max_execution_time.
     */
    public function reprocess_posts(array $post_types, int $batch_size, int $offset, string $bot_id = 'default'): array {
        if (!class_exists('MxChat_Utils')) {
            throw new RuntimeException(
                __('MxChat_Utils is not available — is mxchat-basic activated?', 'mxchat-duckdb')
            );
        }

        $api_key = self::resolve_embedding_api_key($bot_id);
        if (empty($api_key)) {
            throw new RuntimeException(
                __('No embedding API key configured in MxChat.', 'mxchat-duckdb')
            );
        }

        $store = new MxChat_DuckDB_Vector_Store();
        $store->ensure_schema();

        $query = new WP_Query([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ]);

        $processed = 0;
        $failed = 0;

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post) { $failed++; continue; }

            $content = self::build_post_content($post);
            if (trim((string) $content) === '') { $failed++; continue; }

            $source_url = get_permalink($post_id);
            if (!$source_url) { $failed++; continue; }

            $vector_id = md5($source_url);
            $content_type = self::map_post_type_to_content_type($post->post_type);

            $result = MxChat_Utils::submit_content_to_db(
                $content,
                $source_url,
                $api_key,
                $vector_id,
                $bot_id,
                $content_type
            );

            if (is_wp_error($result)) {
                $failed++;
                MxChat_DuckDB_Options::update(['last_error' => 'reprocess post ' . $post_id . ': ' . $result->get_error_message()]);
            } else {
                $processed++;
            }
        }

        $next_offset = ($offset + $batch_size) < (int) $query->found_posts ? ($offset + $batch_size) : null;

        if ($next_offset === null) {
            MxChat_DuckDB_Options::update([
                'last_sync_at'    => time(),
                'last_sync_count' => $processed + $offset,
            ]);
        }

        return [
            'processed'   => $processed,
            'failed'      => $failed,
            'total'       => (int) $query->found_posts,
            'done'        => $offset + count($query->posts),
            'next_offset' => $next_offset,
        ];
    }

    /**
     * Build the ingestible content for a post. Mirrors what mxchat does in its
     * own ingestion: title + post_content (filtered by `the_content`). Plugin
     * users can extend via the `mxchat_duckdb_post_content` filter to add
     * custom meta fields, ACF data, etc.
     */
    private static function build_post_content(WP_Post $post): string {
        $title = trim((string) $post->post_title);
        $content = (string) $post->post_content;

        // Run through WP's filters so shortcodes and blocks are rendered.
        if (function_exists('apply_filters')) {
            $content = apply_filters('the_content', $content);
        }
        $content = wp_strip_all_tags($content, true);

        $out = $title !== '' ? ($title . "\n\n" . $content) : $content;
        return (string) apply_filters('mxchat_duckdb_post_content', $out, $post);
    }

    private static function map_post_type_to_content_type(string $post_type): string {
        return match ($post_type) {
            'post' => 'post',
            'page' => 'page',
            'product' => 'product',
            default => sanitize_key($post_type) ?: 'content',
        };
    }

    /**
     * Resolve the API key for the embedding provider currently configured in mxchat.
     */
    private static function resolve_embedding_api_key(string $bot_id): string {
        $options = function_exists('apply_filters')
            ? apply_filters('mxchat_get_bot_options', [], $bot_id)
            : [];
        if (empty($options)) {
            $options = get_option('mxchat_options', []);
        }

        $model = $options['embedding_model'] ?? 'text-embedding-ada-002';
        if (strpos($model, 'voyage') === 0) {
            return (string) ($options['voyage_api_key'] ?? '');
        }
        if (strpos($model, 'gemini-embedding') === 0) {
            return (string) ($options['gemini_api_key'] ?? '');
        }
        return (string) ($options['api_key'] ?? '');
    }

    /**
     * Vector ID scheme aligned with mxchat's: md5(source_url) for URL-based
     * entries, fallback to "mxchat_kb_{id}" for manual rows without a URL.
     */
    public static function vector_id_for_row($row): string {
        $url = (string) ($row->source_url ?? '');
        if ($url !== '') return md5($url);
        return 'mxchat_kb_' . (int) ($row->id ?? 0);
    }

    /**
     * Inspect the mxchat KB table once per sync to figure out which optional
     * columns are present (notably `bot_id`, which only some mxchat versions
     * carry). Result is cached for the request.
     *
     * @return array{has_bot_id:bool}
     */
    private static function detect_kb_columns(string $kb_table): array {
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

        /**
         * Filter: override the bot_id used when ingesting a KB row.
         * Useful for installs that derive bot_id from URL prefix or meta.
         */
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
