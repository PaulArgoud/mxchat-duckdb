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
 *   wp mxchat-duckdb export --path=…    # Parquet dump of every vector
 *   wp mxchat-duckdb import --path=…    # Parquet restore (INSERT OR REPLACE)
 *   wp mxchat-duckdb async-reprocess --post-types=post,page
 *                                       # enqueue every post into Action Scheduler
 *   wp mxchat-duckdb migrate-from-pinecone --api-key=… --host=… [--namespace=…]
 *                                       # one-shot vector copy, no re-embedding
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
     * ## OPTIONS
     *
     * [--native]
     * : Use the DuckDB-native fast path (ATTACH 'mysql' + single INSERT-SELECT
     *   with regex-parsed embeddings). Requires the DuckDB mysql extension.
     *   5–10× faster on large catalogues but skips the per-row PHP guards;
     *   if it fails for any reason, fall back to `wp mxchat-duckdb sync`
     *   without the flag.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb sync
     *     wp mxchat-duckdb sync --native
     */
    public function sync($args, $assoc_args) {
        if (isset($assoc_args['native'])) {
            try {
                $start = microtime(true);
                $count = (new MxChat_DuckDB_Mysql_Sync())->full_sync_native();
                $elapsed = round((microtime(true) - $start) * 1000);
                \WP_CLI::success(sprintf('Native sync: %d vectors in %d ms.', $count, $elapsed));
            } catch (\Throwable $e) {
                \WP_CLI::error('Native sync failed: ' . $e->getMessage()
                    . ' (re-run without --native to use the PHP fallback)');
            }
            return;
        }

        try {
            // The progress bar needs the total up-front; full_sync() only
            // discovers it on the first callback. Defer creation to that point
            // and tick by the delta on subsequent callbacks.
            $progress = null;
            $last_done = 0;
            $count = MxChat_DuckDB_Sync::instance()->full_sync(
                function ($done, $total) use (&$progress, &$last_done) {
                    if ($progress === null) {
                        $progress = \WP_CLI\Utils\make_progress_bar('Syncing', max(1, (int) $total));
                    }
                    $delta = max(0, (int) $done - $last_done);
                    if ($delta > 0) $progress->tick($delta);
                    $last_done = (int) $done;
                }
            );
            if ($progress) $progress->finish();
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
     * Flush the query result cache by bumping the generation counter.
     *
     * O(1) since v0.6.0 — existing transients become unreachable and expire
     * via their TTL; no `LIKE DELETE` over wp_options. See
     * MxChat_DuckDB_Plugin::bump_cache_generation().
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
        $before = MxChat_DuckDB_Plugin::cache_generation();
        MxChat_DuckDB_Plugin::bump_cache_generation();
        \WP_CLI::success(sprintf(
            'Cache generation bumped (%d → %d). Existing cached results are now unreachable and will expire via TTL.',
            $before,
            MxChat_DuckDB_Plugin::cache_generation()
        ));
    }

    /**
     * Export every vector to a Parquet file.
     *
     * ## OPTIONS
     * --path=<path>
     * : Output file path. Must be writable by the PHP process (or by MotherDuck
     *   when in cloud mode — see DuckDB COPY TO docs for remote URIs).
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb export --path=/tmp/mxchat-backup.parquet
     */
    public function export($args, $assoc_args) {
        $path = (string) ($assoc_args['path'] ?? '');
        if ($path === '') \WP_CLI::error('Missing --path=<file>.');
        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $count = $store->export_parquet($path);
            \WP_CLI::success(sprintf('Exported %d vectors to %s.', $count, $path));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Import vectors from a Parquet file produced by `export`.
     *
     * ## OPTIONS
     * --path=<path>
     * : Input file path.
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb import --path=/tmp/mxchat-backup.parquet
     */
    public function import($args, $assoc_args) {
        $path = (string) ($assoc_args['path'] ?? '');
        if ($path === '') \WP_CLI::error('Missing --path=<file>.');
        try {
            $store = new MxChat_DuckDB_Vector_Store();
            $count = $store->import_parquet($path);
            \WP_CLI::success(sprintf('Imported %d vectors from %s.', $count, $path));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Enqueue every published post of the given types into Action Scheduler
     * for asynchronous reprocessing. Survives PHP timeouts on large catalogs.
     *
     * ## OPTIONS
     * [--post-types=<csv>]
     * : Default: post,page
     *
     * [--bot-id=<id>]
     * : Default: default
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb async-reprocess --post-types=post,page,product
     */
    public function async_reprocess($args, $assoc_args) {
        $post_types_raw = (string) ($assoc_args['post-types'] ?? 'post,page');
        $post_types = array_filter(array_map('sanitize_key', array_map('trim', explode(',', $post_types_raw))));
        if (empty($post_types)) $post_types = ['post', 'page'];
        $bot_id = (string) ($assoc_args['bot-id'] ?? 'default');

        try {
            $r = MxChat_DuckDB_Async_Reprocess::instance()->enqueue_batch($post_types, $bot_id);
            \WP_CLI::success(sprintf(
                'Queued %d of %d posts. Action Scheduler will process them in the background. Run `wp action-scheduler run --group=mxchat-duckdb` to drain inline.',
                $r['scheduled'], $r['total']
            ));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Migrate vectors from a Pinecone index into DuckDB. No re-embedding —
     * pulls existing vectors + metadata directly.
     *
     * ## OPTIONS
     * --api-key=<key>
     * --host=<host>
     * : The Pinecone index host (e.g. my-index-abcd.svc.us-east1-aws.pinecone.io).
     *
     * [--namespace=<ns>]
     *
     * ## EXAMPLES
     *     wp mxchat-duckdb migrate-from-pinecone \
     *         --api-key=pcsk_... \
     *         --host=my-index-abcd.svc.us-east1-aws.pinecone.io \
     *         --namespace=default
     */
    public function migrate_from_pinecone($args, $assoc_args) {
        $api_key = (string) ($assoc_args['api-key'] ?? '');
        $host    = (string) ($assoc_args['host'] ?? '');
        $ns      = (string) ($assoc_args['namespace'] ?? '');
        if ($api_key === '' || $host === '') {
            \WP_CLI::error('Both --api-key=<key> and --host=<host> are required.');
        }
        try {
            $migrator = new MxChat_DuckDB_Pinecone_Migrator($api_key, $host, $ns);
            $r = $migrator->run(function ($copied) {
                \WP_CLI::log(sprintf('… copied %d so far', $copied));
            });
            \WP_CLI::success(sprintf('Migration complete: %d copied, %d failed.', $r['copied'], $r['failed']));
        } catch (\Throwable $e) {
            \WP_CLI::error($e->getMessage());
        }
    }
}

\WP_CLI::add_command('mxchat-duckdb', 'MxChat_DuckDB_CLI');
