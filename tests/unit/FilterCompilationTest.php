<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Pinecone-style filter compiler. Each op should produce a
 * predictable SQL fragment; unknown ops/fields are silently dropped.
 */
final class FilterCompilationTest extends TestCase {

    private function makeStore(): MxChat_DuckDB_Vector_Store {
        // We don't need a real connection — the compiler only uses literal_for()
        // and quote_ident() which don't touch the DB. We use the static factory
        // by instantiating with a mock connection that throws on execute.
        $r = new ReflectionClass(MxChat_DuckDB_Vector_Store::class);
        $store = $r->newInstanceWithoutConstructor();
        // Set the minimum private fields the helpers need.
        $set = function (string $prop, $val) use ($r, $store): void {
            $p = $r->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($store, $val);
        };
        $set('table', 'mxchat_vectors');
        $set('dim', 3);
        $set('metric', 'cosine');
        $set('hnsw', false);

        // Inject a dummy connection so calls that need ->conn don't NPE,
        // even though our tests below never trigger them.
        $dummy = new class implements MxChat_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'test'; }
        };
        $set('conn', $dummy);
        return $store;
    }

    /** Invoke the private static compile_filter(filter, store). */
    private function compile(MxChat_DuckDB_Vector_Store $store, array $filter): array {
        $r = new ReflectionMethod(MxChat_DuckDB_Vector_Store::class, 'compile_filter');
        $r->setAccessible(true);
        return $r->invokeArgs(null, [$filter, $store]);
    }

    public function test_empty_filter_returns_no_fragments(): void {
        $store = $this->makeStore();
        $this->assertSame([], $this->compile($store, []));
    }

    public function test_eq_on_content_type(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['type' => ['$eq' => 'post']]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString("content_type = 'post'", $out[0]);
    }

    public function test_eq_field_alias_content_type(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['content_type' => ['$eq' => 'page']]);
        $this->assertStringContainsString("content_type = 'page'", $out[0]);
    }

    public function test_ne_operator(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['role_restriction' => ['$ne' => 'public']]);
        $this->assertStringContainsString("role_restriction <> 'public'", $out[0]);
    }

    public function test_in_operator_with_strings(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['type' => ['$in' => ['post', 'page']]]);
        $this->assertStringContainsString("content_type IN ('post','page')", $out[0]);
    }

    public function test_nin_operator(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['type' => ['$nin' => ['private']]]);
        $this->assertStringContainsString("content_type NOT IN ('private')", $out[0]);
    }

    public function test_in_with_empty_array_is_dropped(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['type' => ['$in' => []]]);
        $this->assertSame([], $out);
    }

    public function test_range_operators_on_chunk_index(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, [
            'chunk_index' => ['$gte' => 0, '$lt' => 10],
        ]);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('chunk_index >= 0', implode(' ', $out));
        $this->assertStringContainsString('chunk_index < 10', implode(' ', $out));
    }

    public function test_unknown_field_is_silently_dropped(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['some_random_field' => ['$eq' => 'x']]);
        $this->assertSame([], $out);
    }

    public function test_unknown_operator_is_silently_dropped(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['type' => ['$unknown' => 'x']]);
        $this->assertSame([], $out);
    }

    public function test_string_values_are_escaped(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, ['source_url' => ['$eq' => "https://x/?q=O'Hara"]]);
        // single quote inside the value must be doubled to be SQL-safe
        $this->assertStringContainsString("'https://x/?q=O''Hara'", $out[0]);
    }

    public function test_multiple_ops_on_same_field_compose_with_AND(): void {
        $store = $this->makeStore();
        $out = $this->compile($store, [
            'chunk_index' => ['$gte' => 5, '$lte' => 10, '$ne' => 7],
        ]);
        $this->assertCount(3, $out);
    }
}
