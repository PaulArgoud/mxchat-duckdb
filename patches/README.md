# Upstream patch for MxChat (Option A)

The plugin works **without** any patch via the Pinecone-proxy path (Option B):
MxChat is told that DuckDB is a Pinecone backend, and it talks to a local REST
endpoint we expose. This adds an HTTP round-trip and forces a JSON serialization
of the embedding vector, which is wasteful for large knowledge bases.

The patch below removes that overhead by giving MxChat a way to short-circuit
the Pinecone HTTP call with a PHP filter (`mxchat_pinecone_matches_override`).
When the filter returns a non-null array, MxChat uses it directly and never
fires `wp_remote_post()`.

## What to change

File: `mxchat-basic/includes/class-mxchat-integrator.php`

Find the function `find_relevant_content_pinecone()` (around line 5211). Look for
the block that calls `wp_remote_post($api_endpoint, ...)` (around line 5287).

**Wrap the HTTP block in an `else` branch** preceded by the override filter:

```php
// ──────────────── BEGIN PATCH ────────────────
// Allow companion plugins (e.g. mxchat-duckdb) to substitute the Pinecone HTTP
// call with a native implementation. Returning an array short-circuits the
// HTTP request entirely. Returning null falls through to Pinecone as normal.
$override_matches = apply_filters(
    'mxchat_pinecone_matches_override',
    null,
    $user_embedding,
    $bot_id,
    $namespace,
    $request_body
);

if (is_array($override_matches)) {
    $results = ['matches' => $override_matches, 'namespace' => $namespace];
} else {
// ───────────────── END PATCH (top) ────────────

    $response = wp_remote_post($api_endpoint, array(
        'headers' => array(
            'Api-Key' => $api_key,
            'accept' => 'application/json',
            'content-type' => 'application/json'
        ),
        'body' => wp_json_encode($request_body),
        'timeout' => 30
    ));

    if (is_wp_error($response)) {
        $this->current_valid_urls = [];
        return '';
    }

    $response_code = wp_remote_retrieve_response_code($response);

    if ($response_code !== 200) {
        $response_body = wp_remote_retrieve_body($response);
        $this->current_valid_urls = [];
        return '';
    }

    $response_body = wp_remote_retrieve_body($response);
    $results = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->current_valid_urls = [];
        return '';
    }

// ──────────────── BEGIN PATCH (bottom) ────────
}
// ───────────────── END PATCH ──────────────────

if (empty($results['matches'])) {
    $this->current_valid_urls = [];
    return '';
}
// ... rest of function unchanged ...
```

The total change is ~12 lines added (a filter call + an `if/else` wrapper).
Everything downstream — chunk reassembly, role checks, similarity analysis,
RAG context assembly — is reused as-is.

## How to verify the patch is active

After applying, open the admin page **MxChat → DuckDB / MotherDuck** and click
**Tester la connexion**. Then send a message through the chatbot and check
`wp-content/debug.log` (if `WP_DEBUG_LOG` is on) — the line
`MXCHAT DEBUG: About to call Pinecone API` should **not** appear when the
filter returns matches. If it does appear, the patch is not active and the
plugin is operating via the Option B proxy path.

## Submitting upstream

If MxChat maintainers accept this filter, the plugin will detect it automatically
and prefer Option A on every install. We would then deprecate the Option B path
once a minimum supported MxChat version is established.
