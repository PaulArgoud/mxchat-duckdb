<?php

use PHPUnit\Framework\TestCase;

/**
 * Locks the Pinecone-style filter compiler. Each op should produce a
 * predictable SQL fragment; unknown ops/fields are silently dropped.
 */
final class FilterCompilationTest extends TestCase {

    /**
     * The filter compiler lives on MxChat_DuckDB_Vector_Store_Query after
     * the post-v0.4 split. It's `public static` (the trait's `literal_for`
     * needs an instance) so we can call it directly given a Query instance
     * constructed with a no-op connection.
     */
    private function makeQuery(): MxChat_DuckDB_Vector_Store_Query {
        $dummy = new class implements MxChat_DuckDB_Connection {
            public function execute(string $sql, array $params = []): array { return []; }
            public function ping(): bool { return true; }
            public function identifier(): string { return 'test'; }
        };
        return new MxChat_DuckDB_Vector_Store_Query($dummy, 'mxchat_vectors', 3, 'cosine', 'float32');
    }

    private function compile(MxChat_DuckDB_Vector_Store_Query $store, array $filter): array {
        return MxChat_DuckDB_Vector_Store_Query::compile_filter($filter, $store);
    }

    public function test_empty_filter_returns_no_fragments(): void {
        $store = $this->makeQuery();
        $this->assertSame([], $this->compile($store, []));
    }

    public function test_eq_on_content_type(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['type' => ['$eq' => 'post']]);
        $this->assertCount(1, $out);
        $this->assertStringContainsString("content_type = 'post'", $out[0]);
    }

    public function test_eq_field_alias_content_type(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['content_type' => ['$eq' => 'page']]);
        $this->assertStringContainsString("content_type = 'page'", $out[0]);
    }

    public function test_ne_operator(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['role_restriction' => ['$ne' => 'public']]);
        $this->assertStringContainsString("role_restriction <> 'public'", $out[0]);
    }

    public function test_in_operator_with_strings(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['type' => ['$in' => ['post', 'page']]]);
        $this->assertStringContainsString("content_type IN ('post','page')", $out[0]);
    }

    public function test_nin_operator(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['type' => ['$nin' => ['private']]]);
        $this->assertStringContainsString("content_type NOT IN ('private')", $out[0]);
    }

    public function test_in_with_empty_array_is_dropped(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['type' => ['$in' => []]]);
        $this->assertSame([], $out);
    }

    public function test_range_operators_on_chunk_index(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, [
            'chunk_index' => ['$gte' => 0, '$lt' => 10],
        ]);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('chunk_index >= 0', implode(' ', $out));
        $this->assertStringContainsString('chunk_index < 10', implode(' ', $out));
    }

    public function test_unknown_field_is_silently_dropped(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['some_random_field' => ['$eq' => 'x']]);
        $this->assertSame([], $out);
    }

    public function test_unknown_operator_is_silently_dropped(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['type' => ['$unknown' => 'x']]);
        $this->assertSame([], $out);
    }

    public function test_string_values_are_escaped(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, ['source_url' => ['$eq' => "https://x/?q=O'Hara"]]);
        // single quote inside the value must be doubled to be SQL-safe
        $this->assertStringContainsString("'https://x/?q=O''Hara'", $out[0]);
    }

    public function test_multiple_ops_on_same_field_compose_with_AND(): void {
        $store = $this->makeQuery();
        $out = $this->compile($store, [
            'chunk_index' => ['$gte' => 5, '$lte' => 10, '$ne' => 7],
        ]);
        $this->assertCount(3, $out);
    }
}
