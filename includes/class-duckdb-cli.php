<?php
/**
 * WP-CLI command surface. Only loaded when WP_CLI is defined.
 *
 *   wp mxchat-duckdb test              # ping the backend
 *   wp mxchat-duckdb stats             # vector count + metrics + last sync
 *   wp mxchat-duckdb sync               # full MySQL → DuckDB sync
 *   wp mxchat-duckdb reprocess          # walk WP posts through MxChat ingestion
 *   wp mxchat-duckdb compact            # run the orphan compactor now
 *   wp mxchat-duckdb metrics --reset    # reset metrics counters
 *   wp mxchat-duckdb cache --flush      # flush the query result cache
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class MxChat_DuckDB_CLI {

    /**
     * Ping the active backend and report identity + row count.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb test
     */
    public function test($args, $assoc_args) {
        try {
            $conn = MxChat_DuckDB_Connection_Factory::current();
            $ok = $conn->ping();
            \WP_CLI::log('Backend: ' . $conn->identifier());
            \WP_CLI::log('Ping: ' . ($ok ? 'OK' : 'FAILED'));
            if (!$ok) \WP_CLI::error('Backend did not respond to SELECT 1.');

            $store = new MxChat_DuckDB_Vector_Store($conn);
            $store->ensure_schema();
            \WP_CLI::log('Vectors: ' . $store->count());
            \WP_CLI::success('Backend healthy.');
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Show counters, latency percentiles, and last sync/compaction timestamps.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb stats
     */
    public function stats($args, $assoc_args) {
        $opts = MxChat_DuckDB_Options::get();
        $metrics = MxChat_DuckDB_Metrics::snapshot();

        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $count = $store->count();
        } catch (\Throwable $e) {
            $count = '?';
        }

        $rows = [
            ['key' => 'enabled',           'value' => $opts['enabled'] ? 'yes' : 'no'],
            ['key' => 'mode',              'value' => $opts['mode']],
            ['key' => 'embedding_dim',     'value' => (string) $opts['embedding_dim']],
            ['key' => 'vectors',           'value' => (string) $count],
            ['key' => 'searches',          'value' => (string) $metrics['searches']],
            ['key' => 'cache_hit_rate',    'value' => (string) $metrics['cache_hit_rate']],
            ['key' => 'p50_ms',            'value' => (string) $metrics['p50_ms']],
            ['key' => 'p95_ms',            'value' => (string) $metrics['p95_ms']],
            ['key' => 'p99_ms',            'value' => (string) $metrics['p99_ms']],
            ['key' => 'last_sync_at',      'value' => $opts['last_sync_at'] ? gmdate('c', (int) $opts['last_sync_at']) : 'never'],
            ['key' => 'last_compact_at',   'value' => !empty($opts['last_compact_at']) ? gmdate('c', (int) $opts['last_compact_at']) : 'never'],
            ['key' => 'last_error',        'value' => $opts['last_error'] ?: '-'],
        ];

        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * Full MySQL → DuckDB sync. Identical to the "Sync now" admin button.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb sync
     */
    public function sync($args, $assoc_args) {
        try {
            $progress = \WP_CLI\Utils\make_progress_bar('Syncing', 1);
            $count = MxChat_DuckDB_Sync::instance()->full_sync(function ($done, $total) use ($progress) {
                static $set = false;
                if (!$set) { $progress->tick($total); $set = true; }
            });
            $progress->finish();
            \WP_CLI::success(sprintf('Synced %d vectors.', $count));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Walk WP posts through MxChat's embedding pipeline.
     *
     * ## OPTIONS
     * [--post-types=<csv>]
     * : Comma-separated post types. Default: post,page
     *
     * [--batch=<n>]
     * : Posts per batch. Default: 10
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb reprocess --post-types=post,page,product --batch=25
     */
    public function reprocess($args, $assoc_args) {
        $post_types_raw = (string) ($assoc_args['post-types'] ?? 'post,page');
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];
        $batch = max(1, (int) ($assoc_args['batch'] ?? 10));

        $sync = MxChat_DuckDB_Sync::instance();
        $offset = 0;
        $processed = 0; $failed = 0; $total = null;
        $progress = null;

        do {
            try {
                $r = $sync->reprocess_posts($post_types, $batch, $offset);
            } catch (\Throwable $e) {
                if ($progress) $progress->finish();
                \WP_CLI::error($e->getMessage());
            }

            if ($total === null) {
                $total = (int) ($r['total'] ?? 0);
                $progress = \WP_CLI\Utils\make_progress_bar('Reprocessing', max(1, $total));
            }
            $processed += (int) ($r['processed'] ?? 0);
            $failed    += (int) ($r['failed'] ?? 0);
            $progress->tick((int) ($r['processed'] ?? 0) + (int) ($r['failed'] ?? 0));

            $offset = $r['next_offset'];
        } while ($offset !== null);

        if ($progress) $progress->finish();
        \WP_CLI::success(sprintf('Reprocessed: %d processed, %d failed.', $processed, $failed));
    }

    /**
     * Run the orphan compactor immediately.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb compact
     */
    public function compact($args, $assoc_args) {
        $r = MxChat_DuckDB_Compactor::instance()->run();
        if (!empty($r['ok'])) {
            \WP_CLI::success(sprintf('Pruned %d orphan vectors.', (int) ($r['deleted'] ?? 0)));
        } else {
            \WP_CLI::warning('Skipped: ' . ($r['reason'] ?? 'unknown'));
        }
    }

    /**
     * Inspect or reset rolling metrics.
     *
     * ## OPTIONS
     * [--reset]
     * : Clear all metrics counters and samples.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb metrics
     *     wp mxchat-duckdb metrics --reset
     */
    public function metrics($args, $assoc_args) {
        if (isset($assoc_args['reset'])) {
            MxChat_DuckDB_Metrics::reset();
            \WP_CLI::success('Metrics reset.');
            return;
        }
        $m = MxChat_DuckDB_Metrics::snapshot();
        $rows = [];
        foreach ($m as $k => $v) {
            $rows[] = ['key' => $k, 'value' => (string) $v];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['key', 'value']);
    }

    /**
     * Flush the query result cache (transient layer).
     *
     * ## OPTIONS
     * [--flush]
     * : Required for confirmation.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb cache --flush
     */
    public function cache($args, $assoc_args) {
        if (!isset($assoc_args['flush'])) {
            \WP_CLI::log('Pass --flush to confirm.');
            return;
        }
        global $wpdb;
        $rows = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mxd\\_q\\_%' ESCAPE '\\\\'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mxd\\_q\\_%' ESCAPE '\\\\'"
        );
        \WP_CLI::success(sprintf('Flushed %d cached query results.', $rows));
    }
}

\WP_CLI::add_command('mxchat-duckdb', 'MxChat_DuckDB_CLI');
