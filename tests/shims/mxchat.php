<?php
/**
 * Stubs for the two upstream/companion-plugin classes the production code
 * calls into:
 *
 *   - MxChat_Utils — mxchat-basic's public utility class. The plugin's
 *     post_reprocessor + detect_embedding_dim call into it. The stub
 *     records every submit_content_to_db() call into $submit_calls so
 *     tests can assert on what content was sent; the return value is
 *     controllable via $submit_returns (set to a WP_Error to force the
 *     failure path).
 *
 *   - MxChat_DuckDB_Plugin — the orchestration class in mxchat-duckdb.php
 *     itself. We don't load that file (it calls register_*_hook on
 *     constants that aren't defined under PHPUnit), so we stub the static
 *     surface that Vector_Store writes call into (flush_query_cache,
 *     bump_cache_generation, cache_generation).
 */

if (!class_exists('MxChat_Utils')) {
    class MxChat_Utils {
        public static array $submit_calls = [];
        /** @var mixed Set to a WP_Error to force a failure path. */
        public static $submit_returns = true;

        public static function submit_content_to_db(
            $content, $source_url, $api_key, $vector_id = null,
            $bot_id = 'default', $content_type = 'content'
        ) {
            self::$submit_calls[] = compact('content', 'source_url', 'api_key', 'vector_id', 'bot_id', 'content_type');
            return self::$submit_returns;
        }

        public static function embedding_model_dimensions($model) {
            // Mirrors mxchat-basic v3.x's centralised registry — keep in
            // sync with includes/class-mxchat-utils.php upstream so
            // OptionsSanitizeTest / detect_embedding_dim assertions
            // reflect real-world values.
            $known = [
                'text-embedding-ada-002' => 1536,
                'text-embedding-3-small' => 1536,
                'text-embedding-3-large' => 3072,
                'voyage-3-large'         => 2048,
                'gemini-embedding-001'   => 1536,
            ];
            return $known[$model] ?? 0;
        }
    }
}

if (!class_exists('MxChat_DuckDB_Plugin')) {
    class MxChat_DuckDB_Plugin {
        public static array $flushed = [];
        public static int $cache_gen = 1;

        public static function flush_query_cache(): void {
            self::$flushed[] = microtime(true);
            self::bump_cache_generation();
        }
        public static function cache_generation(): int {
            return self::$cache_gen;
        }
        public static function bump_cache_generation(): void {
            self::$cache_gen++;
        }
    }
}
