<?php
/**
 * @var array<string, mixed> $opts
 * @var string $proxy_token
 * @var string $proxy_host
 */
if (!defined('ABSPATH')) { exit; }
?>
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
    <?php $metrics = MxChat_DuckDB_Metrics::snapshot(); ?>
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
