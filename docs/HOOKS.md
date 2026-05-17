# Hooks & filters

Every filter the plugin applies, with signature, purpose, and a short example. Use these to bend the plugin's behaviour without forking it.

## Quick reference

| Filter | Signature | Default | Purpose |
|---|---|---|---|
| [`mxchat_duckdb_post_content`](#mxchat_duckdb_post_content) | `(string $content, WP_Post $post): string` | title + `the_content` filters + stripped tags | Customise the text content sent to MxChat's ingestion pipeline during reprocess. |
| [`mxchat_duckdb_sync_bot_id`](#mxchat_duckdb_sync_bot_id) | `(string $bot_id, object $row): string` | row's `bot_id` column, else `'default'` | Override the `bot_id` derived from a MySQL KB row. |
| [`mxchat_duckdb_upsert_chunk_size`](#mxchat_duckdb_upsert_chunk_size) | `(int $size, bool $is_remote): int` | 250 local, 50 MotherDuck | Override the batch size for `INSERT OR REPLACE`. |
| [`mxchat_duckdb_proxy_rate_limit_per_minute`](#mxchat_duckdb_proxy_rate_limit_per_minute) | `(int $max): int` | 120 | Per-minute request cap on the Pinecone-proxy REST endpoints. `0` disables. |
| [`mxchat_duckdb_query_text`](#mxchat_duckdb_query_text) | `(string $text, string $bot_id, array $filter): string` | `''` | Supply the user query text for hybrid BM25 scoring. Empty = BM25 leg disabled. |
| [`mxchat_duckdb_rerank_matches`](#mxchat_duckdb_rerank_matches) | `(array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array` | identity | Custom reranker hook — return a re-ordered top-K. |
| [`mxchat_duckdb_max_retries`](#mxchat_duckdb_max_retries) | `(int $n): int` | 3 | Retry attempts for idempotent SQL on transient errors. |
| [`mxchat_duckdb_health_public`](#mxchat_duckdb_health_public) | `(bool $allow, WP_REST_Request $req): bool` | `true` | Gate the `/health` endpoint behind authentication. |
| [`mxchat_duckdb_compactor_max_deletes`](#mxchat_duckdb_compactor_max_deletes) | `(int $max): int` | 5000 | Per-run delete cap for the orphan compactor. |

## Detailed reference

### `mxchat_duckdb_post_content`

Customise the text content sent through MxChat's ingestion pipeline when **Reprocess all posts** runs. By default the plugin sends `title + apply_filters('the_content', $content) + wp_strip_all_tags()`. Use this filter to append custom meta fields, ACF data, Yoast SEO descriptions, etc.

```php
add_filter('mxchat_duckdb_post_content', function (string $content, WP_Post $post): string {
    // Append all ACF text fields to the body so they're searchable.
    if (function_exists('get_fields')) {
        $fields = (array) get_fields($post->ID);
        foreach ($fields as $k => $v) {
            if (is_string($v) && $v !== '') {
                $content .= "\n\n" . $k . ': ' . $v;
            }
        }
    }
    return $content;
}, 10, 2);
```

### `mxchat_duckdb_sync_bot_id`

When the bulk / incremental sync copies a row from `wp_mxchat_system_prompt_content` to DuckDB, the `bot_id` is read from the row's `bot_id` column when present, else falls back to `'default'`. Use this filter to derive it from a URL prefix or post-meta instead.

```php
add_filter('mxchat_duckdb_sync_bot_id', function (string $bot_id, $row): string {
    $url = (string) ($row->source_url ?? '');
    if (strpos($url, '/help-fr/') !== false) return 'support_fr';
    if (strpos($url, '/help-en/') !== false) return 'support_en';
    return $bot_id;
}, 10, 2);
```

### `mxchat_duckdb_upsert_chunk_size`

The upsert path batches rows into a single `INSERT OR REPLACE` to amortise the SQL overhead. The default is 250 rows for the embedded backend and 50 for MotherDuck (the smaller default keeps each request well under HTTP body size limits). Drop it lower on slow links, raise it on a beefy local DuckDB.

```php
add_filter('mxchat_duckdb_upsert_chunk_size', fn(int $n, bool $remote): int => $remote ? 25 : 500, 10, 2);
```

### `mxchat_duckdb_proxy_rate_limit_per_minute`

The REST proxy (Option B) caps requests at 120/min per site to protect against a misbehaving client saturating CPU with HNSW searches. Raise it for high-traffic chatbots, set `0` to disable entirely.

```php
add_filter('mxchat_duckdb_proxy_rate_limit_per_minute', fn() => 600);
```

### `mxchat_duckdb_query_text`

Provide the user's raw query text so the plugin can run the BM25 leg of hybrid retrieval. MxChat's Pinecone integration doesn't naturally pass the text — it only passes the embedding. Wire this filter once and the plugin will run hybrid search whenever `hybrid_enabled` is on.

```php
// Capture the user message before MxChat embeds it, then expose it via the filter.
add_action('mxchat_before_query', function (string $user_message): void {
    add_filter('mxchat_duckdb_query_text', fn() => $user_message, 10, 0);
});
```

### `mxchat_duckdb_rerank_matches`

The plugin runs cosine (and optionally BM25) but doesn't ship a cross-encoder reranker. This filter receives the top-K and can return a re-ordered set. Plug in BGE-reranker on a local FastAPI, Cohere Rerank, Voyage Rerank, anything.

```php
add_filter('mxchat_duckdb_rerank_matches', function (array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array {
    if (empty($matches) || $query_text === '') return $matches;
    $resp = wp_remote_post('https://api.cohere.com/v1/rerank', [
        'headers' => ['Authorization' => 'Bearer ' . getenv('COHERE_API_KEY')],
        'body' => wp_json_encode([
            'model' => 'rerank-multilingual-v3.0',
            'query' => $query_text,
            'documents' => array_map(fn($m) => $m['metadata']['text'], $matches),
            'top_n' => count($matches),
        ]),
        'timeout' => 5,
    ]);
    if (is_wp_error($resp)) return $matches;
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $reordered = [];
    foreach (($body['results'] ?? []) as $r) {
        $reordered[] = $matches[$r['index']];
    }
    return $reordered ?: $matches;
}, 10, 5);
```

### `mxchat_duckdb_max_retries`

The embedded backend retries idempotent SQL (SELECT/WITH/PRAGMA/SHOW/DESCRIBE/EXPLAIN) on transient errors (timeout, connection reset, 502/503, rate-limit, TLS handshake) with exponential backoff + jitter. Defaults to 3 attempts. Set higher on flaky links.

```php
add_filter('mxchat_duckdb_max_retries', fn() => 5);
```

### `mxchat_duckdb_health_public`

The `/wp-json/mxchat-duckdb/v1/health` endpoint is **public by default** so external uptime monitors (UptimeRobot, Pingdom, k6) can probe it without an auth token. The payload only leaks aggregate counts, never vector content. Override to require `manage_options` (or your own auth check) if your threat model objects.

```php
add_filter('mxchat_duckdb_health_public', '__return_false'); // require capability check
```

### `mxchat_duckdb_compactor_max_deletes`

The daily orphan compactor caps its delete count per run to avoid blowing up MotherDuck billing on a misconfigured install. Default is 5000. Lower it for paranoid setups; raise it after a big content purge.

```php
add_filter('mxchat_duckdb_compactor_max_deletes', fn() => 50000);
```

## Other extension points

Beyond filters, two action hooks are useful:

- **`mxchat_duckdb_incremental_sync`** (cron, hourly) — picks up new rows from the MySQL KB. You can `do_action('mxchat_duckdb_incremental_sync')` to trigger it manually.
- **`mxchat_duckdb_compact`** (cron, daily) — runs the orphan compactor. Same pattern.

For deeper integration (custom backends, replacing the connection factory, etc.), the codebase uses dependency injection through constructors — see [ARCHITECTURE.md → Design conventions](../ARCHITECTURE.md#design-conventions).
