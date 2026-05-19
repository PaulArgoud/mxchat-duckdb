<?php
/** @var array<string, mixed> $opts */
if (!defined('ABSPATH')) { exit; }
?>
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
