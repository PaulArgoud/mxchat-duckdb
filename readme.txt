=== MxChat DuckDB / MotherDuck ===
Contributors: paulargoud
Tags: chatbot, ai, vector-search, duckdb, motherduck, pinecone, embeddings, rag, mxchat
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds DuckDB (embedded) and MotherDuck (cloud) as SQL-native vector stores for MxChat — an open-source replacement for Pinecone.

== Description ==

**MxChat DuckDB / MotherDuck** is a companion plugin for [MxChat](https://mxchat.ai/) that lets your chatbot store its vector knowledge base in **DuckDB** (embedded `.duckdb` file) or **MotherDuck** (cloud), instead of Pinecone or the slow MySQL fallback.

Both backends use DuckDB's native VSS (vector similarity search) extension with HNSW indexing, and the plugin emulates the Pinecone wire protocol so **no changes to MxChat itself are required** — drop it in, switch the backend, done.

= Why =

* **Open source.** GPL v2, runs locally.
* **Cheap.** Embedded mode = $0 storage. MotherDuck = pay only for compute, with a generous free tier.
* **Fast.** DuckDB's VSS extension delivers HNSW-indexed top-K queries; the plugin caches results, deduplicates by source, and can blend BM25 full-text with vector similarity for higher recall.
* **Production-ready.** Rolling p50/p95/p99 latency metrics, slow-query log, `/health` endpoint, retry+backoff, daily orphan compactor, multisite-aware uninstall.

= Features =

* Two backend modes — embedded `.duckdb` file or MotherDuck cloud (via DuckDB's native `ATTACH 'md:...'`).
* HNSW-indexed similarity search via DuckDB's VSS extension.
* Hybrid BM25 + vector retrieval (optional) via DuckDB FTS.
* Query result cache keyed by embedding hash + filter + bot.
* Per-source dedup and custom reranker hook.
* Drop-in Pinecone wire-protocol emulator (Option B) — zero MxChat patch required.
* Optional in-process integration (Option A) via a ~12-line upstream patch.
* WP-CLI: `wp mxchat-duckdb {test|stats|sync|reprocess|compact|metrics|cache|export|import|migrate-from-pinecone}`.
* `/health` REST endpoint for external uptime monitors.
* Rolling latency metrics (p50/p95/p99) + cache hit rate, surfaced in the admin diagnostics panel.
* Daily orphan-vector compactor cron.
* Per-namespace REST tokens (leaking bot A's key doesn't grant access to bot B).
* Async reprocessing via Action Scheduler — survives PHP timeouts on large catalogs.
* Pinecone → DuckDB migration tool (no re-embedding required).
* Parquet export/import for backups and cross-backend moves.
* Optional INT8 quantization (4× smaller vectors, marginal recall loss).
* Versioned schema with idempotent migrations.
* i18n-ready — English source strings, French translation shipped.

= Requirements =

* PHP 8.0+
* WordPress 6.0+
* [MxChat](https://wordpress.org/plugins/mxchat-basic/) (mxchat-basic) 3.2.5 or newer
* HTTPS (required by MxChat's Pinecone client when using Option B)
* For the local DuckDB process: the PECL `duckdb` extension (preferred) OR the `duckdb` CLI binary on the host

For MotherDuck: a token from [app.motherduck.com](https://app.motherduck.com).

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install through the Plugins screen.
2. Activate **MxChat DuckDB / MotherDuck** (after MxChat itself).
3. Go to **MxChat → DuckDB / MotherDuck** in the admin.
4. Pick a backend (MotherDuck or embedded), test the connection, and pick an ingestion strategy:
   * **Sync MySQL → DuckDB** copies existing embeddings from the MxChat MySQL KB.
   * **Reprocess all posts** re-runs the MxChat ingestion pipeline on WordPress posts.
5. (Optional) Apply the ~12-line upstream patch from `patches/README.md` for the faster Option A integration.

== Frequently Asked Questions ==

= Do I need to modify MxChat? =

No. The plugin emulates the Pinecone wire protocol and registers itself with MxChat through the `mxchat_get_bot_pinecone_config` filter that MxChat already exposes. The optional Option A patch removes one HTTP round-trip but isn't required.

= Does this work on shared hosting? =

Yes, with caveats. The PECL `duckdb` PHP extension is rarely available on shared hosts. The plugin falls back to invoking the `duckdb` CLI binary via `proc_open()`, which most hosts allow but adds ~50–200 ms of process-spawn latency per query. For low-traffic admin sites this is fine; for production chatbot traffic, install the PECL extension on a VPS.

= Does MotherDuck use a REST API? =

No. MotherDuck has no public SQL-execution REST endpoint — the official client path is DuckDB native + `ATTACH 'md:<db>?motherduck_token=...'`. This plugin runs a local DuckDB process and uses ATTACH; it never makes a generic HTTP POST to MotherDuck.

= Can I migrate from Pinecone without re-embedding? =

Yes. Run `wp mxchat-duckdb migrate-from-pinecone --api-key=... --host=... --index=...`. The command pulls vectors + metadata directly from Pinecone (no embedding API calls) and writes them into DuckDB.

= What about uninstall? Does it delete my data? =

By default, no — your `.duckdb` file is preserved on uninstall because it may represent hours of embedding work. To wipe it, define `MXCHAT_DUCKDB_DELETE_DATA_ON_UNINSTALL` in `wp-config.php` before deleting the plugin, or toggle the option in plugin settings. MotherDuck data must be deleted manually at `app.motherduck.com`.

== Screenshots ==

1. The settings page with backend selection (MotherDuck or embedded).
2. Diagnostics panel showing vector count, last sync, and rolling latency metrics.
3. WP-CLI `wp mxchat-duckdb stats` output.
4. Async reprocess progress driven by Action Scheduler.

== Changelog ==

= 0.7.0 =
* Project hygiene + test coverage pass. **No schema migration**, no behaviour change for production.
* New: SECURITY.md, GitHub issue templates, `composer audit` CI job, PHP 8.4 in CI matrix, szepeviktor/phpstan-wordpress dev-dep.
* CI: `actions/checkout` v4 → v6 (Node 24), PHPStan no longer continue-on-error (level 6 clean with WP stubs).
* PHPStan: phpstan/phpstan ^1.11 → ^2.0 to support phpstan-wordpress ^2.0.
* Fixed: uninstall.php was leaking three sidecar options (cache_gen, reprocess_state, pinecone_migration_state).
* Fixed: `wp mxchat-duckdb cache --flush` migrated from legacy LIKE DELETE to O(1) generation-counter bump.
* Fixed: `wp mxchat-duckdb sync` progress bar no longer jumps 0% → 100% at first batch.
* Fixed: two hardcoded French strings in `assets/admin.js` routed through `mxchatDuckDB.i18n.*`; fr_FR.po updated.
* Tests: 57 → 240 tests, 100 → 857 assertions, 5/20 → **20/20 classes covered**. Every class in includes/ now has at least one dedicated test file.

= 0.6.0 =
* New `mxchat_pre_vector_query` filter handler (WordPress-canonical `pre_*` short-circuit convention) ahead of the upstream PR. Legacy `mxchat_pinecone_matches_override` hook kept in parallel for installs already patched.
* Query cache invalidation is now O(1) via a generation counter; the per-write `LIKE DELETE` over `wp_options` is gone. Orphans expire by TTL.
* `cache_key()` ~50× faster on 1536-dim embeddings (`pack('g*', ...)` instead of `strval` + `implode`).
* DuckDB CLI execution now has a 30-second deadline (filterable) with non-blocking IO; a hung CLI no longer freezes PHP-FPM workers.
* `dedup_per_source` over-fetches `top_k × 3` so the final result actually reaches `top_k`.
* Pinecone proxy rate-limit bucket is now per-namespace (was global). A misbehaving bot can't starve the others.
* `POST /pinecone-proxy/query` validates the vector dimension up-front (400 instead of 500 with a cryptic SQL error).
* `looks_like_duckdb_binary()` probes a candidate CLI path via a marker SELECT and surfaces a settings warning on mismatch.
* `compile_filter()` logs ignored ops/fields under `WP_DEBUG` (deduplicated per request) so filter typos don't silently leak unfiltered results.
* `Vector_Store::current()` singleton shared by REST proxy and search adapter (reset on options save).
* `detect_embedding_dim()` now prefers mxchat-basic's `MxChat_Utils::embedding_model_dimensions()` as the single source of truth. Local fallback corrected: `voyage-3-large` 1024 → 2048, `gemini-embedding-001` 3072 → 1536.
* Fixed: `dequantize_int8()` returned `int` when the divide was exact (`0/127`, `127/127`); now always returns `float[]`.
* New tests: `CacheGenerationTest`, `BinaryProbeTest`; relaxed the synthetic-vector recall threshold to a realistic 0.99. 57/57 passing.

= 0.5.0 =
* Documentation reorganised: ARCHITECTURE.md with Mermaid flowchart + sequence diagrams; reference moved to docs/CONFIGURATION.md, docs/HOOKS.md, docs/CLI.md, docs/USAGE.md. README trimmed from 354 → 144 lines.
* Internal refactor (no behaviour change): `Vector_Store` split into Schema + Query + façade; `Sync` split into MySQL pipeline + Post Reprocessor + façade; `admin/views/settings.php` split into 7 per-section partials. Largest remaining file went from 858 → 332 lines. Public API unchanged.

= 0.4.0 =
* Async reprocess via Action Scheduler (survives PHP timeouts on large catalogs).
* Pinecone → DuckDB migration WP-CLI command — no re-embedding required.
* Parquet export/import (WP-CLI + admin buttons).
* Optional INT8 quantization for embeddings (4× smaller, marginal recall loss).
* GitHub Actions CI: `php -l` matrix (PHP 8.0–8.3) + PHPStan + PHPUnit.
* PHPUnit smoke tests on the pure-PHP utilities.
* PHPStan configuration (informational baseline).
* Automated release zip on git tag.

= 0.3.0 =
* Hybrid BM25 + vector search via DuckDB FTS.
* Query result cache, per-source dedup, custom reranker hook.
* Rich Pinecone-style filter operators ($eq, $ne, $in, $nin, $gt, $gte, $lt, $lte).
* Rolling p50/p95/p99 latency metrics + slow-query log.
* `/health` REST endpoint.
* WP-CLI command surface.
* Daily orphan compactor cron.
* Per-namespace REST tokens.
* MotherDuck retry + backoff on transient errors.
* Schema versioning with idempotent migrations.
* Multisite-aware uninstall with custom-path cleanup.
* Full gettext setup (English source, fr_FR shipped).

= 0.2.0 =
* MotherDuck connection rewritten to use native DuckDB ATTACH (HTTP path was unsupported).
* Default data directory moved under `uploads/mxchat-duckdb-private/` with auto-generated `.htaccess`/`web.config`.
* `proc_open` switched to array form.
* Batched upserts (250 local, 50 remote).
* Connection cache + schema-ensured memoisation.
* `bot_id` propagation from the MxChat KB row.
* REST proxy rate-limit (120 req/min).
* Persistent admin error notice + `last_error`.
* `embedding_dim` change guard.
* `uninstall.php`.
* i18n consistency pass.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.7.0 =
Project hygiene + test coverage pass. No schema migration, no behaviour change for production traffic — pure improvement of correctness guarantees (57 → 240 tests, 5/20 → 20/20 classes covered). Safe drop-in upgrade.

= 0.6.0 =
Performance + hardening pass. No schema migration, no behaviour change for default settings — public API stable. The legacy `mxchat_pinecone_matches_override` hook stays registered so installs already running the upstream patch keep working unchanged. Safe drop-in upgrade.

= 0.5.0 =
Documentation reorganised + internal refactor. No data migration, no behaviour change — public API stable. Safe drop-in upgrade.

= 0.4.0 =
Adds async reprocess (Action Scheduler), Pinecone migration tool, Parquet I/O, optional INT8 quantization. No data migration required — existing schema continues to work; new features are opt-in.

= 0.3.0 =
Major feature release. Hybrid search, query cache, WP-CLI, /health, metrics. No breaking changes from 0.2.0; bumping schema to v3 (FTS index) is idempotent.

= 0.2.0 =
**Breaking for MotherDuck users.** The HTTP-based MotherDuck path is gone — the plugin now uses native DuckDB `ATTACH`. Re-test your connection after upgrading. The `mxchat_duckdb_motherduck_endpoint` and `mxchat_duckdb_motherduck_timeout` filters are removed.
