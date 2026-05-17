# Changelog

All notable changes to **MxChat DuckDB / MotherDuck** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned

- Submit upstream patch (`mxchat_pinecone_matches_override` filter) to mxchat-basic.
- Import-from-Pinecone tool (one-shot vector copy that bypasses re-embedding).
- PDF / attachment reprocessing.
- Per-bot configuration UI for multi-bot installs.

---

## [0.3.0] — 2026-05-17

Feature pass: retrieval quality, observability, operations.

### Added

- **Schema versioning** — `mxchat_duckdb_schema_meta` table tracks the current
  schema version; migrations run sequentially and idempotently. Target version
  is 3 in this release.
- **Hybrid BM25 + vector search** — when `hybrid_enabled` is on and a
  `mxchat_duckdb_query_text` filter supplies the user query, results from
  DuckDB's FTS extension are min-max-normalised and blended with cosine
  similarity using configurable `hybrid_alpha`. Falls back gracefully to pure
  vector when FTS is unavailable.
- **Query result cache** — top-K matches are cached in a transient keyed by
  `md5(embedding) + bot_id + filter + top_k`. Default TTL 300 s, configurable.
  Automatically invalidated on upsert/delete.
- **Per-source dedup** — optional collapse of multiple chunks from the same
  `source_url` in the final top-K (the LLM gets distinct sources, not five
  near-duplicates).
- **Pinecone-style filter operators** — `$eq`, `$ne`, `$in`, `$nin`, `$gt`,
  `$gte`, `$lt`, `$lte` on `content_type`, `role_restriction`, `source_url`,
  `chunk_index`. Unknown operators/fields are silently skipped (Pinecone parity).
- **Re-ranking hook** — `mxchat_duckdb_rerank_matches` filter receives the
  top-K and can return a re-ordered set (cross-encoders, Cohere Rerank, …).
- **Metrics class** — rolling 1-hour window of latency samples; exposes
  p50/p95/p99 + cache hit rate + counters via `MxChat_DuckDB_Metrics::snapshot()`
  and the admin diagnostics panel.
- **Slow-query log** — queries slower than `slow_query_ms` (default 500) are
  written to the PHP error log with bot id and hybrid/dedup flags.
- **MotherDuck retry + backoff** — idempotent queries (SELECT/WITH/PRAGMA/…)
  automatically retry up to 3× on transient errors (timeout, EOF, 502/503,
  rate-limit) with exponential backoff + jitter.
- **`/health` REST endpoint** — `GET /wp-json/mxchat-duckdb/v1/health` returns
  backend status, vector count, last sync age, metrics snapshot. Public by
  default; restrict with the `mxchat_duckdb_health_public` filter.
- **WP-CLI command surface** — `wp mxchat-duckdb {test|stats|sync|reprocess|
  compact|metrics|cache}`. Indispensable for scripted deployments.
- **Compactor** — daily `mxchat_duckdb_compact` cron job that deletes vectors
  whose `vector_id` no longer maps to any row in the MySQL KB. Capped per run
  (filter `mxchat_duckdb_compactor_max_deletes`, default 5000) and skipped if
  the last sync was within the past hour.
- **Per-namespace proxy tokens** — `MxChat_DuckDB_Pinecone_Proxy::get_or_create_token_for($namespace)`
  issues tokens scoped to a single bot, so a leak on bot A no longer grants
  access to bot B's vectors. The legacy global token still works as a
  fallback (wildcard scope).
- **Nonce + capability check on cascade delete** — the
  `wp_ajax_mxchat_delete_pinecone_prompt` handler now validates the nonce and
  `manage_options` capability itself instead of relying on mxchat's check
  running afterwards.
- **Composer autoloader** — `composer.json` ships with a classmap autoload
  over `includes/`; the bootstrap prefers `vendor/autoload.php` when present
  and falls back to manual `require_once` otherwise. Non-breaking.
- **i18n** — full gettext setup: `Text Domain` + `Domain Path` headers,
  `load_plugin_textdomain()`, English source strings, `mxchat-duckdb.pot`
  template + ready-to-use `mxchat-duckdb-fr_FR.po`/`.mo`.

### Changed

- All `__()` / `_e()` source strings converted from French to English; French
  is now shipped as the fr_FR translation (canonical WordPress convention).
- Default `embedded_path` directory now hosts a `.htaccess` + `index.php` +
  `web.config` trio (was added in 0.2.0; mentioned here for completeness).
- New options added with sensible defaults:
  `hybrid_enabled` (false), `hybrid_alpha` (0.7), `query_cache_enabled` (true),
  `query_cache_ttl` (300), `dedup_per_source` (false), `slow_query_ms` (500),
  `last_compact_at` (0).

### New filters

- `mxchat_duckdb_query_text` — populate the user query text for BM25.
- `mxchat_duckdb_rerank_matches` — custom reranker hook.
- `mxchat_duckdb_max_retries` — override the retry attempts (default 3).
- `mxchat_duckdb_health_public` — gate the `/health` endpoint behind auth.
- `mxchat_duckdb_compactor_max_deletes` — per-run cap (default 5000).

### Fixed

- Cascade-delete handler no longer trusts mxchat's nonce check happening
  later; verifies the nonce itself first. Defense-in-depth.
- **`uninstall.php` — dotfile cleanup.** `glob($dir.'/*')` silently skipped
  the `.htaccess` we wrote into the data directory, leaving an orphan that
  prevented `rmdir()` from succeeding. Now uses recursive `scandir()`.
- **`uninstall.php` — custom `embedded_path` honoured.** Plugin options are
  read *before* deletion so a user-configured path outside `uploads/` is
  also cleaned (file + `.wal` / `.tmp` / `.lock` companions). The parent
  directory is left untouched in case it's shared with other tools.
- **`uninstall.php` — multisite.** On multisite the cleanup now iterates
  every blog (`switch_to_blog()` loop) so per-site options / cron / transients
  are removed for the whole network, not just the main site.

### Notes

- `uninstall.php` deliberately never makes a network call. To wipe data on
  MotherDuck, run `DROP TABLE mxchat_vectors; DROP TABLE mxchat_duckdb_schema_meta;`
  manually at app.motherduck.com.

---

## [0.2.0] — 2026-05-17

Hardening pass triggered by an internal review. No data migration required, but
**re-test the connection after upgrading** — the MotherDuck backend now talks
the native DuckDB protocol instead of the previously-assumed REST endpoint.

### Changed (breaking for MotherDuck users)

- **MotherDuck connection rewritten.** The v0.1.0 implementation posted SQL to
  a `POST /v1/sql` endpoint that does not exist as a public SQL-execution API
  (MotherDuck's REST surface is user/token management only). The connection now
  wraps a local DuckDB process (PECL extension or `duckdb` CLI) and runs
  `INSTALL motherduck; LOAD motherduck; ATTACH 'md:<db>?motherduck_token=…';`
  as init SQL. Same backend class name, same factory; only the wire path
  changes. The `mxchat_duckdb_motherduck_endpoint` and
  `mxchat_duckdb_motherduck_timeout` filters are removed.
- **Default embedded path** moved from `uploads/mxchat-duckdb/` to
  `uploads/mxchat-duckdb-private/`. Existing installs with a custom
  `embedded_path` are unaffected; defaults are migrated transparently because
  the new dir is created on demand.

### Added

- **`.htaccess` + `index.php` + `web.config` blockers** automatically written
  into the DuckDB data directory at runtime so the `.duckdb` file is not
  served over HTTP.
- **Connection cache** in `MxChat_DuckDB_Connection_Factory` — one instance per
  request, keyed by mode + token hash; flushed when options are saved.
- **`ensure_schema()` memoisation** — `INSTALL vss / LOAD vss / CREATE TABLE`
  run at most once per request instead of on every search.
- **Batched upserts** — 250 rows for local DuckDB, 50 rows for MotherDuck to
  stay under network body limits. Filter: `mxchat_duckdb_upsert_chunk_size`.
- **`bot_id` propagation in sync** — the bulk and incremental sync routines
  now detect the `bot_id` column on `wp_mxchat_system_prompt_content` (when
  present) and propagate it instead of hardcoding `'default'`. New filter:
  `mxchat_duckdb_sync_bot_id` for installs that derive the bot from URL/meta.
- **REST proxy rate-limit** — 120 req/min per site by default to protect
  against a misbehaving client saturating CPU with HNSW searches. Filter:
  `mxchat_duckdb_proxy_rate_limit_per_minute` (set 0 to disable).
- **Persistent error notice** in the WP admin when a vector search fails
  (transient + `error_log()` instead of silently returning empty matches).
- **`embedding_dim` change guard** — sanitiser refuses to change the dimension
  when the table already contains rows (the `FLOAT[N]` column type is fixed at
  CREATE time); user must wipe and re-sync.
- **`uninstall.php`** — full cleanup of options, proxy token, scheduled cron,
  rate-limit transients, and (opt-in via
  `MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL`) the data directory itself.
- **CLI-mode performance warning** in the settings page when the PECL extension
  is absent, with a stronger warning for MotherDuck + CLI combos.
- **`updated_at` column** added to the schema (with idempotent ALTER for
  upgrades from 0.1.0).
- New filter `mxchat_duckdb_upsert_chunk_size`, new filter
  `mxchat_duckdb_sync_bot_id`, new filter
  `mxchat_duckdb_proxy_rate_limit_per_minute`.

### Fixed

- **Shell-injection-shaped bug** in `proc_open` — the v0.1.0 path passed a
  concatenated command string with un-escaped `$binary_path`; now uses the
  array form of `proc_open` (PHP 7.4+), no shell at all.
- **Silent vector corruption** — `literal_float_array` no longer falls back to
  `0.0` on a non-numeric component (which would have produced a zero vector
  that matches nothing useful but ranks deterministically). Throws instead.
- **Upsert dimension check** — incoming vectors whose length doesn't match the
  configured `embedding_dim` are rejected up-front with a clear error,
  preventing partial batches from corrupting the table.
- **Cron cleanup on deactivation** — `mxchat_duckdb_incremental_sync` is now
  unscheduled when the plugin is deactivated instead of firing hourly against
  a disabled backend.
- **Proxy token race** — the `Api-Key` token is now generated at activation
  time instead of lazily on the first admin page load, closing a window where
  Option B requests from mxchat would fail until an admin opened the settings.
- **i18n consistency** — all user-facing strings (including
  `RuntimeException` messages surfaced via `last_error`) now go through
  `__()` with French source strings, matching the rest of the UI.
- Removed unnecessary `flush_rewrite_rules()` on activation/deactivation
  (REST routes don't use the rewrite system).

### Removed

- `MxChat_DuckDB_MotherDuck_Connection`'s HTTP code path and the related
  `MOTHERDUCK_ENDPOINT` constant.
- Filters `mxchat_duckdb_motherduck_endpoint` and
  `mxchat_duckdb_motherduck_timeout` (no longer applicable).

---

## [0.1.0] — 2026-05-17

First MVP release. Working end-to-end on stock MxChat 3.2.5, no upstream changes required.

### Added

- **Two parallel integration paths** with MxChat's vector search dispatch:
  - **Option A** — `mxchat_pinecone_matches_override` filter (requires the
    ~12-line upstream patch documented in [`patches/README.md`](patches/README.md)).
  - **Option B** — Pinecone wire-protocol emulator served at
    `/wp-json/mxchat-duckdb/v1/pinecone-proxy/` (zero patch required).
- **Two backends**, switchable from the settings page:
  - **MotherDuck** over HTTP, authenticated by bearer token.
  - **Embedded DuckDB** via the PECL `duckdb` extension when available,
    falling back to the `duckdb` CLI invoked through `proc_open()`.
- **Vector store** with DuckDB VSS extension:
  - Schema with `FLOAT[N]` embedding column (dimension configurable).
  - Optional HNSW index over the embedding column.
  - Cosine / l2sq / inner-product similarity metrics.
- **Two ingestion strategies**:
  - **Sync MySQL → DuckDB** — bulk copy from `wp_mxchat_system_prompt_content`
    in 250-row batches; idempotent thanks to a stable `vector_id` scheme.
  - **Reprocess from WordPress posts** — walks posts/pages/CPTs and routes each
    through `MxChat_Utils::submit_content_to_db()` so MxChat's chunking and
    embedding pipeline writes directly into DuckDB via the proxy. Batched via
    AJAX with progress bar to avoid PHP timeouts.
- **Incremental sync** via WP-cron (`mxchat_duckdb_incremental_sync`, hourly).
- **Cascading delete** when MxChat's UI deletes a KB entry
  (hooks `wp_ajax_mxchat_delete_pinecone_prompt`).
- **REST endpoints** emulating Pinecone:
  - `POST /query` — top-K similarity search.
  - `POST /vectors/upsert` — batch upsert of vectors + metadata.
  - `POST /vectors/fetch` — fetch by ID array.
  - `POST /vectors/delete` — delete by ID array.
  - `POST /vectors/list` (also `GET`) — paginated ID listing.
  - All endpoints authenticated by a one-time-generated `Api-Key` header.
- **Admin settings page** under **MxChat → DuckDB / MotherDuck** with:
  - Backend selection (MotherDuck / embedded).
  - Test connection button.
  - Sync now / reprocess now buttons with progress feedback.
  - Live stats (vectors in DuckDB, last sync, last error).
- **Filters exposed for extensibility**:
  - `mxchat_duckdb_post_content` — customize reprocessed post content.
  - `mxchat_duckdb_motherduck_endpoint` — override the MotherDuck HTTP URL.
  - `mxchat_duckdb_motherduck_timeout` — override the MotherDuck HTTP timeout.

### Known limitations

- Direct SQL writes to `wp_mxchat_system_prompt_content` (outside MxChat's UI)
  don't propagate to DuckDB until the next hourly cron tick.
- Option B requires the WordPress site to be on HTTPS (MxChat hardcodes
  `https://` when calling Pinecone hosts).
- The PECL `duckdb` extension API is still evolving; the embedded backend will
  prefer the CLI path on most installs until the extension stabilizes.
- Embedding dimension is fixed at table creation; changing the embedding model
  requires re-creating the DuckDB table and re-syncing.