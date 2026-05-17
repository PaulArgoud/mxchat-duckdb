<?php

use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['__test_options'] = [];
        $GLOBALS['__test_transients'] = [];
    }

    public function test_snapshot_is_empty_initially(): void {
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertSame(0, $s['searches']);
        $this->assertSame(0, $s['p50_ms']);
        $this->assertSame(0, $s['p95_ms']);
        $this->assertSame(0.0, $s['cache_hit_rate']);
    }

    public function test_observe_latency_increments_searches(): void {
        MxChat_DuckDB_Metrics::observe_latency(50);
        MxChat_DuckDB_Metrics::observe_latency(80);
        MxChat_DuckDB_Metrics::observe_latency(110);
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertSame(3, $s['searches']);
        $this->assertSame(3, $s['sample_count']);
    }

    public function test_percentiles_are_monotonic(): void {
        foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 100] as $ms) {
            MxChat_DuckDB_Metrics::observe_latency($ms);
        }
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertLessThanOrEqual($s['p95_ms'], $s['p50_ms']);
        $this->assertLessThanOrEqual($s['p99_ms'], $s['p95_ms']);
        // p50 of 10..100 ≈ 60 (we pick the value at index 5 in a sorted 10-sample list)
        $this->assertGreaterThanOrEqual(50, $s['p50_ms']);
        $this->assertLessThanOrEqual(70, $s['p50_ms']);
    }

    public function test_cache_hit_rate_is_correct(): void {
        MxChat_DuckDB_Metrics::observe_latency(50); // 1 search
        MxChat_DuckDB_Metrics::record('query_cache_hit'); // 1 hit
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertSame(0.5, $s['cache_hit_rate']);
    }

    public function test_reset_clears_everything(): void {
        MxChat_DuckDB_Metrics::observe_latency(123);
        MxChat_DuckDB_Metrics::record('errors');
        MxChat_DuckDB_Metrics::reset();
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertSame(0, $s['searches']);
        $this->assertSame(0, $s['errors']);
    }

    public function test_negative_latency_is_clamped_to_zero(): void {
        MxChat_DuckDB_Metrics::observe_latency(-50);
        $s = MxChat_DuckDB_Metrics::snapshot();
        $this->assertGreaterThanOrEqual(0, $s['p50_ms']);
    }
}
