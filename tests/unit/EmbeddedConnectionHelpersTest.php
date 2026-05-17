<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the conservative idempotency sniff used to decide whether a SQL
 * statement may be retried on a transient error. False positives here are
 * the dangerous direction (we'd re-execute an INSERT), so the test list is
 * deliberately strict about negatives.
 */
final class EmbeddedConnectionHelpersTest extends TestCase {

    private static function call(string $method, array $args = []) {
        $r = new ReflectionMethod(MxChat_DuckDB_Embedded_Connection::class, $method);
        $r->setAccessible(true);
        return $r->invokeArgs(null, $args);
    }

    public function test_select_is_idempotent(): void {
        $this->assertTrue(self::call('looks_idempotent', ['SELECT 1']));
        $this->assertTrue(self::call('looks_idempotent', ["  select count(*) from t"]));
    }

    public function test_with_pragma_show_describe_explain_are_idempotent(): void {
        $this->assertTrue(self::call('looks_idempotent', ['WITH x AS (SELECT 1) SELECT * FROM x']));
        $this->assertTrue(self::call('looks_idempotent', ['PRAGMA table_info(foo)']));
        $this->assertTrue(self::call('looks_idempotent', ['SHOW TABLES']));
        $this->assertTrue(self::call('looks_idempotent', ['DESCRIBE foo']));
        $this->assertTrue(self::call('looks_idempotent', ['EXPLAIN SELECT 1']));
    }

    public function test_insert_update_delete_are_not_idempotent(): void {
        $this->assertFalse(self::call('looks_idempotent', ['INSERT INTO t VALUES (1)']));
        $this->assertFalse(self::call('looks_idempotent', ['INSERT OR REPLACE INTO t VALUES (1)']));
        $this->assertFalse(self::call('looks_idempotent', ['UPDATE t SET x=1']));
        $this->assertFalse(self::call('looks_idempotent', ['DELETE FROM t']));
        $this->assertFalse(self::call('looks_idempotent', ['COPY t TO \'/tmp\'']));
    }

    public function test_is_transient_error_recognises_common_signals(): void {
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('Read timeout')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('connection reset by peer')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('HTTP 503 Service Unavailable')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('rate limit exceeded')]));
        $this->assertTrue(self::call('is_transient_error', [new RuntimeException('TLS handshake failure')]));
    }

    public function test_is_transient_error_rejects_logic_errors(): void {
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('syntax error at column 12')]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('unknown column foo')]));
        $this->assertFalse(self::call('is_transient_error', [new RuntimeException('permission denied')]));
    }
}
