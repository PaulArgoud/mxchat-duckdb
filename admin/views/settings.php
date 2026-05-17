<?php
/**
 * Settings page template. Variables in scope (set by class-duckdb-admin.php):
 *   $opts          (array)   plugin options
 *   $proxy_token   (string)  Pinecone-proxy auth token (read-only)
 *   $proxy_host    (string)  Pinecone-proxy host fragment
 *   $detected_dim  (int)     detected embedding dimension from mxchat
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e('MxChat — DuckDB / MotherDuck', 'mxchat-duckdb'); ?></h1>

    <p class="description">
        <?php esc_html_e(
            'Replaces Pinecone with DuckDB (embedded) or MotherDuck (cloud) as the vector store. The plugin registers itself with MxChat as an alternative Pinecone backend.',
            'mxchat-duckdb'
        ); ?>
    </p>

    <?php if (!empty($opts['last_error'])): ?>
        <div class="notice notice-warning">
            <p><strong><?php esc_html_e('Last error:', 'mxchat-duckdb'); ?></strong>
                <code><?php echo esc_html($opts['last_error']); ?></code></p>
        </div>
    <?php endif; ?>

    <?php
    $has_pecl = extension_loaded('duckdb');
    $is_motherduck = ($opts['mode'] ?? '') === 'motherduck';
    if (!$has_pecl): ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e('Performance:', 'mxchat-duckdb'); ?></strong>
                <?php esc_html_e(
                    'the PHP duckdb extension is not loaded. The plugin falls back to the duckdb CLI binary, which adds 50–200 ms of process-spawn latency per query.',
                    'mxchat-duckdb'
                ); ?>
                <?php if ($is_motherduck): ?>
                    <br>
                    <strong style="color:#a00;"><?php esc_html_e('MotherDuck + CLI mode:', 'mxchat-duckdb'); ?></strong>
                    <?php esc_html_e(
                        'each query re-runs the MotherDuck ATTACH (network handshake + token validation). Expect 1–3 s of overhead per call. For production, install the PECL duckdb extension.',
                        'mxchat-duckdb'
                    ); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields(MxChat_DuckDB_Admin::MENU_SLUG); ?>

        <h2><?php esc_html_e('Activation', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable DuckDB / MotherDuck', 'mxchat-duckdb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($opts['enabled'])); ?>>
                        <?php esc_html_e('Enabled', 'mxchat-duckdb'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                            'When enabled, MxChat uses this plugin as its vector store (in place of Pinecone). When disabled, MxChat falls back to its default behaviour.',
                            'mxchat-duckdb'
                        ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Backend', 'mxchat-duckdb'); ?></th>
                <td>
                    <label style="margin-right:1em;">
                        <input type="radio" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[mode]" value="motherduck" <?php checked($opts['mode'] ?? '', 'motherduck'); ?>>
                        <?php esc_html_e('MotherDuck (cloud, via ATTACH)', 'mxchat-duckdb'); ?>
                    </label>
                    <label>
                        <input type="radio" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[mode]" value="embedded" <?php checked($opts['mode'] ?? '', 'embedded'); ?>>
                        <?php esc_html_e('DuckDB embedded (local file)', 'mxchat-duckdb'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('MotherDuck', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="motherduck_token"><?php esc_html_e('Token', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="password" id="motherduck_token" class="regular-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[motherduck_token]"
                           value="<?php echo esc_attr($opts['motherduck_token'] ?? ''); ?>" autocomplete="off">
                    <p class="description">
                        <?php
                        echo wp_kses_post(__(
                            'MotherDuck token (from <a href="https://app.motherduck.com" target="_blank" rel="noopener">app.motherduck.com</a>).',
                            'mxchat-duckdb'
                        ));
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="motherduck_database"><?php esc_html_e('Database', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="text" id="motherduck_database" class="regular-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[motherduck_database]"
                           value="<?php echo esc_attr($opts['motherduck_database'] ?? 'my_db'); ?>">
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('DuckDB embedded', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="embedded_path"><?php esc_html_e('Path to the .duckdb file', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="text" id="embedded_path" class="regular-text code"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[embedded_path]"
                           placeholder="<?php echo esc_attr(MxChat_DuckDB_Options::default_embedded_path()); ?>"
                           value="<?php echo esc_attr($opts['embedded_path'] ?? ''); ?>">
                    <p class="description">
                        <?php esc_html_e('Leave empty to use the default wp-content/uploads/mxchat-duckdb-private/ directory (protected by auto-generated .htaccess / web.config).', 'mxchat-duckdb'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="embedded_binary"><?php esc_html_e('DuckDB CLI binary', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="text" id="embedded_binary" class="regular-text code"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[embedded_binary]"
                           placeholder="/usr/local/bin/duckdb"
                           value="<?php echo esc_attr($opts['embedded_binary'] ?? ''); ?>">
                    <p class="description">
                        <?php esc_html_e('Used when the PHP duckdb extension is not installed. Leave empty for autodetection.', 'mxchat-duckdb'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Vector schema', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="embedding_dim"><?php esc_html_e('Embedding dimension', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="number" id="embedding_dim" class="small-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[embedding_dim]"
                           min="1" max="4096" value="<?php echo esc_attr((int) ($opts['embedding_dim'] ?? 1536)); ?>">
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %d = detected dimension */
                            esc_html__('MxChat is currently using dimension %d. This must match the active embedding model.', 'mxchat-duckdb'),
                            (int) $detected_dim
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="distance_metric"><?php esc_html_e('Metric', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <select id="distance_metric" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[distance_metric]">
                        <option value="cosine" <?php selected($opts['distance_metric'] ?? '', 'cosine'); ?>>cosine</option>
                        <option value="l2sq" <?php selected($opts['distance_metric'] ?? '', 'l2sq'); ?>>l2sq</option>
                        <option value="ip" <?php selected($opts['distance_metric'] ?? '', 'ip'); ?>>inner product</option>
                    </select>
                    <p class="description"><?php esc_html_e('MxChat uses cosine similarity — keep cosine unless you have a specific reason.', 'mxchat-duckdb'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('HNSW index', 'mxchat-duckdb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[hnsw_enabled]" value="1" <?php checked(!empty($opts['hnsw_enabled'])); ?>>
                        <?php esc_html_e('Create an HNSW index over the embedding column (recommended for > 10k entries)', 'mxchat-duckdb'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="top_k"><?php esc_html_e('Default top-K', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="number" id="top_k" class="small-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[top_k]"
                           min="1" max="1000" value="<?php echo esc_attr((int) ($opts['top_k'] ?? 50)); ?>">
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Retrieval quality', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Hybrid BM25 + vector', 'mxchat-duckdb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[hybrid_enabled]" value="1" <?php checked(!empty($opts['hybrid_enabled'])); ?>>
                        <?php esc_html_e('Blend BM25 full-text scores with vector similarity (requires the DuckDB FTS extension and a populated `mxchat_duckdb_query_text` filter).', 'mxchat-duckdb'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="hybrid_alpha"><?php esc_html_e('Hybrid α (vector weight)', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="number" id="hybrid_alpha" class="small-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[hybrid_alpha]"
                           min="0" max="1" step="0.05" value="<?php echo esc_attr((float) ($opts['hybrid_alpha'] ?? 0.7)); ?>">
                    <p class="description"><?php esc_html_e('1.0 = pure vector, 0.0 = pure BM25. 0.7 is a sensible default for factual KBs.', 'mxchat-duckdb'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Dedup per source', 'mxchat-duckdb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[dedup_per_source]" value="1" <?php checked(!empty($opts['dedup_per_source'])); ?>>
                        <?php esc_html_e('Keep only the highest-scoring chunk per source_url in the top-K (avoids passing N near-duplicate chunks from the same article to the LLM).', 'mxchat-duckdb'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Performance', 'mxchat-duckdb'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Query cache', 'mxchat-duckdb'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[query_cache_enabled]" value="1" <?php checked(!empty($opts['query_cache_enabled'])); ?>>
                        <?php esc_html_e('Cache top-K results (keyed by embedding hash + filter + bot). Invalidated automatically on upsert/delete.', 'mxchat-duckdb'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="query_cache_ttl"><?php esc_html_e('Cache TTL (seconds)', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="number" id="query_cache_ttl" class="small-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[query_cache_ttl]"
                           min="0" max="3600" value="<?php echo esc_attr((int) ($opts['query_cache_ttl'] ?? 300)); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="slow_query_ms"><?php esc_html_e('Slow-query threshold (ms)', 'mxchat-duckdb'); ?></label></th>
                <td>
                    <input type="number" id="slow_query_ms" class="small-text"
                           name="<?php echo esc_attr(MXCHAT_DUCKDB_OPTION_KEY); ?>[slow_query_ms]"
                           min="0" value="<?php echo esc_attr((int) ($opts['slow_query_ms'] ?? 500)); ?>">
                    <p class="description"><?php esc_html_e('Queries slower than this are written to the PHP error log. Set to 0 to disable.', 'mxchat-duckdb'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save', 'mxchat-duckdb')); ?>
    </form>

    <hr>

    <h2><?php esc_html_e('Diagnostics', 'mxchat-duckdb'); ?></h2>

    <p>
        <button type="button" class="button" id="mxchat-duckdb-test"><?php esc_html_e('Test connection', 'mxchat-duckdb'); ?></button>
        <button type="button" class="button" id="mxchat-duckdb-sync"><?php esc_html_e('Sync MySQL → DuckDB', 'mxchat-duckdb'); ?></button>
        <button type="button" class="button button-primary" id="mxchat-duckdb-reprocess"><?php esc_html_e('Reprocess all posts', 'mxchat-duckdb'); ?></button>
        <span id="mxchat-duckdb-status" style="margin-left:1em;"></span>
    </p>

    <p class="description" style="max-width:720px;">
        <strong><?php esc_html_e('Sync MySQL → DuckDB', 'mxchat-duckdb'); ?></strong>:
        <?php esc_html_e(
            'copies the wp_mxchat_system_prompt_content table into DuckDB. Useful only if MxChat is running in MySQL mode and the table contains embeddings.',
            'mxchat-duckdb'
        ); ?>
        <br>
        <strong><?php esc_html_e('Reprocess all posts', 'mxchat-duckdb'); ?></strong>:
        <?php esc_html_e(
            'walks WordPress posts/pages and re-runs the MxChat ingestion pipeline (chunking + embedding + upsert). Recommended for Pinecone-only installs. Calls the configured embedding API (OpenAI / Voyage / Gemini) — potential cost.',
            'mxchat-duckdb'
        ); ?>
    </p>

    <p>
        <label for="mxchat-duckdb-post-types">
            <?php esc_html_e('Post types to reprocess (comma-separated):', 'mxchat-duckdb'); ?>
        </label>
        <input type="text" id="mxchat-duckdb-post-types" class="regular-text code" value="post,page" placeholder="post,page,product">
    </p>

    <div id="mxchat-duckdb-progress" style="display:none; margin:1em 0; max-width:720px;">
        <div style="background:#eee; height:18px; border-radius:9px; overflow:hidden;">
            <div id="mxchat-duckdb-progress-bar" style="height:100%; width:0; background:#2271b1; transition:width 0.2s;"></div>
        </div>
    </div>

    <table class="widefat striped" style="max-width:720px;">
        <tbody>
        <tr>
            <th><?php esc_html_e('Vectors in DuckDB', 'mxchat-duckdb'); ?></th>
            <td>
                <?php
                try {
                    $store = new MxChat_DuckDB_Vector_Store();
                    echo (int) $store->count();
                } catch (\Throwable $e) {
                    echo '<span style="color:#a00;">' . esc_html($e->getMessage()) . '</span>';
                }
                ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Last sync', 'mxchat-duckdb'); ?></th>
            <td>
                <?php
                if (!empty($opts['last_sync_at'])) {
                    echo esc_html(sprintf(
                        /* translators: 1: date/time, 2: vector count */
                        __('%1$s (%2$d vectors)', 'mxchat-duckdb'),
                        wp_date('Y-m-d H:i:s', (int) $opts['last_sync_at']),
                        (int) $opts['last_sync_count']
                    ));
                } else {
                    esc_html_e('Never', 'mxchat-duckdb');
                }
                ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Active backend', 'mxchat-duckdb'); ?></th>
            <td><code><?php echo esc_html($opts['mode']); ?></code></td>
        </tr>
        <?php
        $metrics = MxChat_DuckDB_Metrics::snapshot();
        ?>
        <tr>
            <th><?php esc_html_e('Searches (rolling 1h)', 'mxchat-duckdb'); ?></th>
            <td><?php echo (int) $metrics['searches']; ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Latency p50 / p95 / p99', 'mxchat-duckdb'); ?></th>
            <td><?php echo (int) $metrics['p50_ms']; ?> / <?php echo (int) $metrics['p95_ms']; ?> / <?php echo (int) $metrics['p99_ms']; ?> ms</td>
        </tr>
        <tr>
            <th><?php esc_html_e('Cache hit rate', 'mxchat-duckdb'); ?></th>
            <td><?php echo esc_html(number_format(100 * (float) $metrics['cache_hit_rate'], 1)); ?>%</td>
        </tr>
        </tbody>
    </table>

    <h3><?php esc_html_e('Pinecone proxy endpoint (Option B)', 'mxchat-duckdb'); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'These values are auto-injected into MxChat via the mxchat_get_bot_pinecone_config filter. No action required.',
            'mxchat-duckdb'
        ); ?>
    </p>
    <table class="widefat" style="max-width:720px;">
        <tbody>
        <tr>
            <th><?php esc_html_e('Host (for MxChat)', 'mxchat-duckdb'); ?></th>
            <td><code><?php echo esc_html($proxy_host); ?></code></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Api-Key', 'mxchat-duckdb'); ?></th>
            <td><code><?php echo esc_html(substr($proxy_token, 0, 12) . '…'); ?></code></td>
        </tr>
        </tbody>
    </table>
</div>
