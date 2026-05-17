<?php
/**
 * Lightweight metrics aggregator.
 *
 * Two storage strategies:
 *   - Latency histogram: a rolling array of (timestamp, ms) samples kept in
 *     an option, capped at MAX_SAMPLES. Computes p50/p95 on demand.
 *   - Named counters: monotonic counts (searches, cache hits, errors, …)
 *     kept in the same option, reset only by explicit user action.
 *
 * Storage as a single non-autoloaded option avoids hammering the DB with
 * transient writes and makes the data trivially exportable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Metrics {

    const OPTION_KEY = 'mxchat_duckdb_metrics';
    const MAX_SAMPLES = 500;
    /** Drop samples older than this from the rolling window. */
    const SAMPLE_TTL_SECONDS = 3600;

    public static function observe_latency(int $ms): void {
        $data = self::load();
        $data['latency'][] = [time(), max(0, $ms)];
        // Trim by count + age.
        $cutoff = time() - self::SAMPLE_TTL_SECONDS;
        $data['latency'] = array_values(array_filter(
            $data['latency'],
            fn($s) => $s[0] >= $cutoff
        ));
        if (count($data['latency']) > self::MAX_SAMPLES) {
            $data['latency'] = array_slice($data['latency'], -self::MAX_SAMPLES);
        }
        $data['counters']['searches'] = ($data['counters']['searches'] ?? 0) + 1;
        self::save($data);
    }

    public static function record(string $counter, int $delta = 1): void {
        $data = self::load();
        $data['counters'][$counter] = ($data['counters'][$counter] ?? 0) + $delta;
        self::save($data);
    }

    /**
     * Returns ['searches' => n, 'p50_ms' => int, 'p95_ms' => int,
     *          'cache_hit_rate' => float, 'errors' => n, 'window_seconds' => …]
     */
    public static function snapshot(): array {
        $data = self::load();
        $samples = array_map(fn($s) => (int) $s[1], $data['latency'] ?? []);
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        $searches = (int) ($data['counters']['searches'] ?? 0);
        $cache_hits = (int) ($data['counters']['query_cache_hit'] ?? 0);

        return [
            'searches'       => $searches,
            'p50_ms'         => $n ? $samples[(int) ($n * 0.50)] : 0,
            'p95_ms'         => $n ? $samples[(int) min($n - 1, $n * 0.95)] : 0,
            'p99_ms'         => $n ? $samples[(int) min($n - 1, $n * 0.99)] : 0,
            'sample_count'   => $n,
            'cache_hits'     => $cache_hits,
            'cache_hit_rate' => $searches > 0 ? round($cache_hits / max(1, $searches + $cache_hits), 3) : 0.0,
            'errors'         => (int) ($data['counters']['errors'] ?? 0),
            'window_seconds' => self::SAMPLE_TTL_SECONDS,
        ];
    }

    public static function reset(): void {
        delete_option(self::OPTION_KEY);
    }

    private static function load(): array {
        $data = get_option(self::OPTION_KEY, []);
        if (!is_array($data)) $data = [];
        if (!isset($data['latency']) || !is_array($data['latency'])) $data['latency'] = [];
        if (!isset($data['counters']) || !is_array($data['counters'])) $data['counters'] = [];
        return $data;
    }

    private static function save(array $data): void {
        update_option(self::OPTION_KEY, $data, false);
    }
}
