<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the O(1) cache-invalidation scheme.
 *
 * Writes used to issue a `DELETE … LIKE '_transient_mxd_q_%'` on wp_options.
 * The new path bumps Plugin::cache_generation() instead, and Vector_Store_Query
 * weaves the generation into the transient key — orphans expire by TTL.
 *
 * These tests cover the contract from both ends:
 *   - cache_key() emits a `mxd_q_<gen>_<hash>` prefix when gen > 0 (so a bump
 *     produces a fresh key for the same inputs)
 *   - cache_key() stays backward-compatible when gen = 0 (default)
 *   - Plugin::bump_cache_generation() actually changes what cache_generation()
 *     returns (the test bootstrap stubs Plugin — see tests/bootstrap.php)
 */
final class CacheGenerationTest extends TestCase {

    private static function callKey(array $args) {
        $r = new ReflectionMethod(MxChat_DuckDB_Vector_Store_Query::class, 'cache_key');
        $r->setAccessible(true);
        return $r->invokeArgs(null, $args);
    }

    public function test_cache_key_without_gen_is_backward_compatible(): void {
        $key = self::callKey([[0.1, 0.2], 10, 'default', []]);
        // No generation segment when gen=0.
        $this->assertMatchesRegularExpression('/^mxd_q_[a-f0-9]{32}$/', $key);
    }

    public function test_cache_key_with_gen_carries_generation_prefix(): void {
        $key = self::callKey([[0.1, 0.2], 10, 'default', [], 7]);
        $this->assertMatchesRegularExpression('/^mxd_q_7_[a-f0-9]{32}$/', $key);
    }

    public function test_cache_key_changes_when_generation_changes(): void {
        $emb = [0.1, 0.2];
        $a = self::callKey([$emb, 10, 'default', [], 1]);
        $b = self::callKey([$emb, 10, 'default', [], 2]);
        $this->assertNotSame($a, $b, 'bumping generation must produce a new key');
    }

    public function test_cache_key_stable_within_same_generation(): void {
        $emb = [0.1, 0.2, 0.3, 0.4];
        $a = self::callKey([$emb, 5, 'bot_x', ['type' => ['$eq' => 'post']], 42]);
        $b = self::callKey([$emb, 5, 'bot_x', ['type' => ['$eq' => 'post']], 42]);
        $this->assertSame($a, $b);
    }

    public function test_cache_key_uses_packed_floats_not_strval(): void {
        // 0.1 + 0.2 in pack('g*') round-trips through float32, but two distinct
        // values still produce distinct bytes — make sure the new path doesn't
        // silently collide for trivially-different embeddings.
        $a = self::callKey([[0.1, 0.2, 0.3], 10, 'd', []]);
        $b = self::callKey([[0.1, 0.2, 0.31], 10, 'd', []]);
        $this->assertNotSame($a, $b);
    }

    public function test_plugin_bump_changes_reported_generation(): void {
        $start = MxChat_DuckDB_Plugin::cache_generation();
        MxChat_DuckDB_Plugin::bump_cache_generation();
        $this->assertGreaterThan($start, MxChat_DuckDB_Plugin::cache_generation());
    }

    public function test_plugin_flush_alias_still_bumps_generation(): void {
        // flush_query_cache() is kept as a back-compat shim that bumps the
        // generation; tests on Vector_Store::upsert() rely on the count of
        // flushes, so the legacy contract must keep ticking up.
        $before_gen = MxChat_DuckDB_Plugin::cache_generation();
        $before_flushes = count(MxChat_DuckDB_Plugin::$flushed);
        MxChat_DuckDB_Plugin::flush_query_cache();
        $this->assertCount($before_flushes + 1, MxChat_DuckDB_Plugin::$flushed);
        $this->assertGreaterThan($before_gen, MxChat_DuckDB_Plugin::cache_generation());
    }
}
