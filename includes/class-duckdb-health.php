<?php
/**
 * Read-only /health endpoint for external uptime monitors.
 *
 *   GET /wp-json/mxchat-duckdb/v1/health
 *
 * Returns 200 with backend identity + vector count + last sync age + metrics
 * snapshot when everything is healthy, or 503 when ping() fails. No auth by
 * default — the response leaks only aggregate counts, not vector content.
 * Sites that want auth can replace the permission callback via filter.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Health {

    private static ?self $instance = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_routes(): void {
        add_action('rest_api_init', function () {
            register_rest_route('mxchat-duckdb/v1', '/health', [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle'],
                'permission_callback' => function (WP_REST_Request $req) {
                    $allow = apply_filters('mxchat_duckdb_health_public', true, $req);
                    if ($allow) return true;
                    return current_user_can('manage_options');
                },
            ]);
        });
    }

    public function handle(WP_REST_Request $req): WP_REST_Response {
        $opts = MxChat_DuckDB_Options::get();
        $payload = [
            'plugin_version' => MXCHAT_DUCKDB_VERSION,
            'enabled'        => (bool) $opts['enabled'],
            'mode'           => (string) $opts['mode'],
            'ext_loaded'     => extension_loaded('duckdb'),
            'metrics'        => MxChat_DuckDB_Metrics::snapshot(),
            'last_sync_at'   => (int) $opts['last_sync_at'],
            'last_sync_age_s' => $opts['last_sync_at'] ? (time() - (int) $opts['last_sync_at']) : null,
            'last_error'     => (string) $opts['last_error'],
        ];

        if (!$opts['enabled']) {
            $payload['ok'] = true;
            $payload['status'] = 'disabled';
            return new WP_REST_Response($payload, 200);
        }

        try {
            $conn = MxChat_DuckDB_Connection_Factory::current();
            $payload['backend'] = $conn->identifier();
            $payload['ping'] = $conn->ping();

            $store = new MxChat_DuckDB_Vector_Store($conn);
            $payload['count'] = $store->count();
            $payload['ok'] = (bool) $payload['ping'];
            $payload['status'] = $payload['ok'] ? 'healthy' : 'ping_failed';
            return new WP_REST_Response($payload, $payload['ok'] ? 200 : 503);
        } catch (\Throwable $e) {
            $payload['ok'] = false;
            $payload['status'] = 'error';
            $payload['error'] = $e->getMessage();
            return new WP_REST_Response($payload, 503);
        }
    }
}
