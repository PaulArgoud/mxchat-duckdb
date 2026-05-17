<?php
/**
 * @var array $opts
 * @var int   $detected_dim
 */
if (!defined('ABSPATH')) { exit; }
?>
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
