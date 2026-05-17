<?php
/** @var array $opts */
if (!defined('ABSPATH')) { exit; }
?>
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
