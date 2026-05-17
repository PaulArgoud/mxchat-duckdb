# MxChat DuckDB / MotherDuck

<p align="left">
  <a href="#"><img alt="Plugin version" src="https://img.shields.io/badge/version-0.3.0-blue.svg"></a>
  <a href="#"><img alt="PHP" src="https://img.shields.io/badge/php-%E2%89%A5%208.0-777BB4.svg?logo=php&logoColor=white"></a>
  <a href="#"><img alt="WordPress" src="https://img.shields.io/badge/wordpress-%E2%89%A5%206.0-21759B.svg?logo=wordpress&logoColor=white"></a>
  <a href="https://mxchat.ai/"><img alt="MxChat" src="https://img.shields.io/badge/mxchat-%E2%89%A5%203.2.5-2c3e50.svg"></a>
  <a href="https://duckdb.org/"><img alt="DuckDB" src="https://img.shields.io/badge/duckdb-VSS-FFF000.svg?logo=duckdb&logoColor=black"></a>
  <a href="https://motherduck.com/"><img alt="MotherDuck" src="https://img.shields.io/badge/motherduck-supported-FFD400.svg"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img alt="License: GPL v2+" src="https://img.shields.io/badge/license-GPL%20v2%2B-green.svg"></a>
  <a href="#"><img alt="Status: alpha" src="https://img.shields.io/badge/status-alpha-orange.svg"></a>
</p>

> Companion WordPress plugin that adds **DuckDB** (embedded) and **MotherDuck** (cloud) as alternative vector stores for [MxChat](https://mxchat.ai/) — an open-source, SQL-native replacement for Pinecone.

---

## Why

[MxChat](https://mxchat.ai/) is a popular AI-chatbot plugin for WordPress that ships with two storage backends for its vector knowledge base:

1. **MySQL** — embeddings serialized in `LONGTEXT` columns; cosine similarity computed in PHP. Simple but slow past a few thousand entries.
2. **Pinecone** — fast and managed, but a proprietary SaaS with per-record pricing.

This plugin adds a third option:

3. **DuckDB / MotherDuck** — analytical columnar database with native [VSS](https://duckdb.org/docs/extensions/vss) (vector similarity search) extension. Open-source, runs locally or in the cloud, $0 for the embedded mode.

## Features

- 🪶 **Two backend modes** — embedded `.duckdb` file or [MotherDuck](https://motherduck.com/) cloud (via native DuckDB `ATTACH`), switchable at runtime.
- ⚡ **HNSW-indexed similarity search** via DuckDB's VSS extension (`array_cosine_similarity`).
- 🔀 **Hybrid BM25 + vector retrieval** (optional) via DuckDB's FTS extension with min-max-normalised score blending.
- 💨 **Query result cache** keyed by embedding hash + filter + bot — slashes MotherDuck cost and latency on repeat queries.
- 🎯 **Per-source dedup + custom reranker hook** so the LLM sees diverse, high-quality context.
- 🔌 **Drop-in for Pinecone** — implements the Pinecone wire protocol over REST, so MxChat needs zero modifications to use it.
- 🔐 **Per-namespace REST tokens** so leaking one bot's API key doesn't compromise others.
- 🪛 **Optional upstream patch** for direct in-process integration (eliminates one HTTP round-trip; ~12 lines, [see patches/](patches/README.md)).
- 🔁 **Two ingestion paths** — sync from MySQL knowledge base, or reprocess from WordPress posts (recommended for Pinecone-only installs).
- 🧰 **Auto cascade-delete** with nonce-verified handler; orphan compactor cron sweeps stragglers.
- 🩺 **`/health` endpoint + rolling p50/p95/p99 latency metrics** for external monitors.
- 🛠️ **WP-CLI**: `wp mxchat-duckdb {test|stats|sync|reprocess|compact|metrics|cache}`.
- 🕒 **Hourly WP-cron** for incremental sync of new content + daily orphan compaction.
- 🛡️ **Per-user-role access control** preserved from MxChat (metadata-driven).
- 🌐 **i18n-ready** — English source strings, French translation shipped, `.pot` template for additional locales.

## Architecture

The plugin connects to MxChat via two parallel integration paths. The two paths can coexist; whichever is available "wins" at runtime.

```
                                        ┌────────────────────────────┐
   ┌──────────────┐                     │       MxChat (core)        │
   │ User message │ ──► chatbot ──────► │  find_relevant_content()   │
   └──────────────┘                     │            │               │
                                        │            ▼               │
                                        │  find_relevant_content_    │
                                        │       pinecone()           │
                                        └─────┬──────┬───────────────┘
                  (Option A)                  │      │   (Option B)
            mxchat_pinecone_matches_override  │      │   wp_remote_post → "Pinecone host"
                                              │      │   (which is actually our REST endpoint)
                                              ▼      ▼
                                 ┌──────────────────────────────┐
                                 │   mxchat-duckdb plugin       │
                                 │  ──────────────────────      │
                                 │  Pinecone proxy (REST)       │
                                 │  Search adapter (filter)     │
                                 │            │                 │
                                 │            ▼                 │
                                 │   ┌──────────────────────┐   │
                                 │   │  DuckDB Vector Store │   │
                                 │   │  (HNSW + cosine)     │   │
                                 │   └──────────────────────┘   │
                                 │            │                 │
                                 │     ┌──────┴──────┐          │
                                 │     ▼             ▼          │
                                 │ ┌─────────┐  ┌──────────┐    │
                                 │ │embedded │  │MotherDuck│    │
                                 │ │ .duckdb │  │  cloud   │    │
                                 │ └─────────┘  └──────────┘    │
                                 └──────────────────────────────┘
```

### Option A — filter override (preferred, requires a small upstream patch)

When `mxchat-basic` is patched with the snippet in [`patches/README.md`](patches/README.md), this plugin hooks the `mxchat_pinecone_matches_override` filter to substitute the Pinecone HTTP call with a direct DuckDB SQL query. **Zero HTTP overhead.**

### Option B — Pinecone proxy (works on stock MxChat)

Without the patch, the plugin registers itself as a Pinecone backend via the `mxchat_get_bot_pinecone_config` filter that MxChat already exposes, and serves a REST endpoint at `/wp-json/mxchat-duckdb/v1/pinecone-proxy/` that emulates the Pinecone wire protocol. MxChat thinks it's talking to Pinecone; the proxy translates each call to DuckDB SQL.

Both paths return identical results. Option A is faster; Option B is zero-touch.

## Requirements

| Component | Version |
|---|---|
| PHP | ≥ 8.0 |
| WordPress | ≥ 6.0 |
| MxChat (`mxchat-basic`) | ≥ 3.2.5 |
| Site protocol | HTTPS (required for Option B; MxChat hardcodes `https://` when calling Pinecone) |

**Both backends** rely on a local DuckDB process — either the PECL `duckdb` PHP extension (preferred, in-process) **or** the `duckdb` CLI binary (auto-detected in `/usr/local/bin`, `/usr/bin`, `/opt/homebrew/bin`, or set explicitly in plugin settings).

**For MotherDuck**: a token from [app.motherduck.com](https://app.motherduck.com). MotherDuck mode is a thin wrapper around the local DuckDB process — it runs `INSTALL motherduck; LOAD motherduck; ATTACH 'md:<db>?motherduck_token=…'` at connect time. There is **no HTTP-only path**: SQL is shipped through DuckDB's native protocol. With CLI fallback, each query re-attaches; for any production traffic, install the PECL extension.

## Installation

### From source

```bash
cd wp-content/plugins/
git clone https://github.com/paulargoud/mxchat-duckdb.git
```

Then activate **MxChat DuckDB / MotherDuck** in the WordPress plugins screen (after **MxChat** itself).

### From release zip

1. Download the latest `mxchat-duckdb-x.y.z.zip` from the [Releases](https://github.com/paulargoud/mxchat-duckdb/releases) page.
2. **Plugins → Add New → Upload Plugin** → choose the zip.
3. Activate.

## Quick start

1. Go to **MxChat → DuckDB / MotherDuck** in the WordPress admin.
2. Choose a backend:
   - **MotherDuck** — paste your token + database name.
   - **Embedded** — leave the path empty for the default (`wp-content/uploads/mxchat-duckdb-private/store.duckdb`, protected by an auto-generated `.htaccess` + `index.php` + `web.config`).
3. Click **Test connection** to verify.
4. Choose an ingestion strategy:
   - **Sync MySQL → DuckDB** — copies the existing `wp_mxchat_system_prompt_content` table. Use this if MxChat has been running in MySQL mode and the table contains embeddings.
   - **Reprocess all posts** — walks published WordPress posts/pages and runs them through MxChat's full ingestion pipeline (chunking + embedding + upsert). **Recommended** for installs that have been on Pinecone-only.
5. (Optional) Apply [`patches/README.md`](patches/README.md) to enable the faster Option A integration.

> ⚠️ Reprocessing calls the embedding API configured in MxChat (OpenAI / Voyage / Gemini), which may incur usage costs. Typical cost: a few cents for 100–500 posts on `text-embedding-3-small`.

## Configuration

All settings are stored in a single WP option, `mxchat_duckdb_options`:

| Key | Type | Default | Purpose |
|---|---|---|---|
| `enabled` | bool | `false` | Master switch. When off, MxChat falls back to its native behavior. |
| `mode` | enum | `motherduck` | `motherduck` or `embedded`. |
| `motherduck_token` | string | `""` | MotherDuck auth token. |
| `motherduck_database` | string | `my_db` | MotherDuck database name. |
| `embedded_path` | string | autodetect | Path to the `.duckdb` file. |
| `embedded_binary` | string | autodetect | Path to the `duckdb` CLI binary (used if the PECL extension is unavailable). |
| `embedding_dim` | int | `1536` | Must match the embedding model active in MxChat. |
| `distance_metric` | enum | `cosine` | `cosine`, `l2sq`, or `ip`. |
| `hnsw_enabled` | bool | `true` | Create an HNSW index over the embedding column. |
| `top_k` | int | `50` | Default `topK` for similarity queries. |
| `hybrid_enabled` | bool | `false` | Blend BM25 full-text scores with vector similarity. Requires the DuckDB FTS extension. |
| `hybrid_alpha` | float | `0.7` | Weight on the vector score (1.0 = pure vector, 0.0 = pure BM25). |
| `query_cache_enabled` | bool | `true` | Cache top-K results in a transient. Invalidated automatically on upsert/delete. |
| `query_cache_ttl` | int | `300` | Cache TTL in seconds (0 disables write, lookups still happen). |
| `dedup_per_source` | bool | `false` | Collapse multiple chunks from the same `source_url` in the final top-K. |
| `slow_query_ms` | int | `500` | Queries slower than this are logged to PHP's error log. Set 0 to disable. |

## Hooks & filters

The plugin exposes the following filters that **other** plugins can use to extend its behavior:

| Filter | Signature | Purpose |
|---|---|---|
| `mxchat_duckdb_post_content` | `(string $content, WP_Post $post): string` | Customize the text content that gets sent to MxChat's ingestion pipeline during reprocessing — useful for appending custom meta, ACF data, etc. |
| `mxchat_duckdb_sync_bot_id` | `(string $bot_id, object $row): string` | Override the `bot_id` derived from a KB row during sync. Useful for multi-bot installs where the bot is derived from URL prefix or meta. |
| `mxchat_duckdb_upsert_chunk_size` | `(int $size, bool $is_remote): int` | Override the upsert batch size (defaults: 250 local, 50 MotherDuck). Drop it lower if you hit body-size limits on slow links. |
| `mxchat_duckdb_proxy_rate_limit_per_minute` | `(int $max): int` | Override the per-minute request cap on the Pinecone-proxy REST endpoints (default 120). Set to 0 to disable. |
| `mxchat_duckdb_query_text` | `(string $text, string $bot_id, array $filter): string` | Provide the user query text for hybrid BM25 scoring. Empty string disables the BM25 leg. |
| `mxchat_duckdb_rerank_matches` | `(array $matches, array $embedding, string $bot_id, array $filter, string $query_text): array` | Custom reranker hook — return a re-ordered top-K (cross-encoders, Cohere Rerank, etc.). |
| `mxchat_duckdb_max_retries` | `(int $n): int` | Override the retry-on-transient-error attempts for idempotent SQL (default 3). |
| `mxchat_duckdb_health_public` | `(bool $allow, WP_REST_Request $req): bool` | Gate the `/health` endpoint behind authentication. Default is `true` (public). |
| `mxchat_duckdb_compactor_max_deletes` | `(int $max): int` | Per-run delete cap for the orphan compactor (default 5000). |

## WP-CLI

```
wp mxchat-duckdb test                                    # ping backend + report row count
wp mxchat-duckdb stats                                   # counters, p50/p95/p99, last sync
wp mxchat-duckdb sync                                    # full MySQL → DuckDB
wp mxchat-duckdb reprocess --post-types=post,page,product --batch=25
wp mxchat-duckdb compact                                 # run orphan compactor now
wp mxchat-duckdb metrics [--reset]                       # inspect / reset metrics
wp mxchat-duckdb cache --flush                           # clear the query result cache
```

## Health endpoint

```
GET /wp-json/mxchat-duckdb/v1/health
```

Returns 200 + JSON when healthy, 503 + error JSON otherwise. The payload
includes the backend identifier, vector count, last-sync age, and the rolling
metrics snapshot. Suitable for UptimeRobot / Pingdom / k6 probes. Public by
default — gate it via the `mxchat_duckdb_health_public` filter to require
`manage_options`.

## File layout

```
mxchat-duckdb/
├── mxchat-duckdb.php                            Plugin bootstrap
├── uninstall.php                                Full cleanup on plugin delete
├── composer.json                                Classmap autoload (optional)
├── LICENSE                                      GPL v2
├── includes/
│   ├── class-duckdb-options.php                 Settings + defaults + directory blockers
│   ├── class-duckdb-metrics.php                 Rolling latency window + counters
│   ├── class-duckdb-connection.php              Interface + cached factory
│   ├── class-duckdb-motherduck-connection.php   ATTACH wrapper over the embedded backend
│   ├── class-duckdb-embedded-connection.php     PECL + CLI backend with init-SQL + retry
│   ├── class-duckdb-vector-store.php            Versioned schema, batched upsert, hybrid query
│   ├── class-duckdb-sync.php                    Bulk sync + reprocess + WP-cron + bot_id detection
│   ├── class-duckdb-compactor.php               Daily orphan-vector pruning cron
│   ├── class-duckdb-pinecone-proxy.php          REST endpoints (rate-limited, per-namespace tokens)
│   ├── class-duckdb-search-adapter.php          Filter hooks (Option A + Option B) + error notices
│   ├── class-duckdb-health.php                  /wp-json/mxchat-duckdb/v1/health
│   ├── class-duckdb-cli.php                     WP-CLI commands (only loaded under WP_CLI)
│   └── class-duckdb-admin.php                   Settings page + AJAX
├── admin/views/
│   └── settings.php                             Settings UI template
├── assets/
│   └── admin.js                                 AJAX handlers (test, sync, reprocess)
├── languages/
│   ├── mxchat-duckdb.pot                        Translation template (76 strings)
│   ├── mxchat-duckdb-fr_FR.po                   French translation source
│   └── mxchat-duckdb-fr_FR.mo                   French compiled catalog
├── patches/
│   └── README.md                                Optional upstream patch (Option A)
├── CHANGELOG.md
├── CONTRIBUTING.md
└── README.md
```

## Verification (end-to-end test)

1. Install on a staging WordPress with MxChat active.
2. Activate the plugin, set the backend, **Test connection** → ✅.
3. **Reprocess all posts** → wait for progress bar to reach 100%.
4. Ask the chatbot a question that matches your KB content.
5. Inspect `wp-content/debug.log` (with `WP_DEBUG_LOG` on): you should see DuckDB queries; you should **not** see successful Pinecone API responses.
6. Run `SELECT COUNT(*) FROM mxchat_vectors;` directly in DuckDB (CLI or MotherDuck UI) — count should match the number of synced/reprocessed entries.

## Roadmap

- [ ] Submit the upstream patch (`mxchat_pinecone_matches_override` filter) to MxChat
- [ ] Migrate Option B users to Option A automatically once the filter ships
- [ ] PDF / attachment reprocessing (currently only post types are covered)
- [ ] Per-bot configuration UI (multi-bot installs)
- [ ] Import-from-Pinecone tool (one-shot vector copy for users who don't want to re-embed)
- [ ] Native DuckDB extension binding when the PECL extension API stabilizes
- [ ] Bench suite comparing query latency: MySQL-PHP vs Pinecone vs DuckDB embedded vs MotherDuck

## Limitations

- **Shared hosting**: the PECL `duckdb` extension is rarely available; falls back to invoking the CLI via `proc_open()`, which may be disabled by some hosts. CLI mode adds ~50–200 ms of process-spawn latency per query.
- **MotherDuck + CLI**: each query re-runs `ATTACH 'md:…'`, adding 1–3 s of network handshake. Acceptable for low-traffic admin tasks; install the PECL extension for any production chatbot traffic.
- **Embedding dimension** must match the model active in MxChat. The settings page shows the detected dimension; the plugin now blocks `embedding_dim` changes when the table already contains vectors — you must wipe and re-sync to switch models.
- **Direct SQL writes** to `wp_mxchat_system_prompt_content` (outside MxChat's UI) won't propagate to DuckDB until the next incremental cron tick.
- **HNSW + multi-tenant `bot_id` filter**: DuckDB VSS does not push down arbitrary `WHERE` clauses into the HNSW index, so queries scoped by `bot_id` fall back to a brute-force scan. Single-tenant installs use the index as expected.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full guide. TL;DR: PHP 8.0+, run `php -l` on changed files, update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]`, run translatable strings through `__()` and re-compile `mxchat-duckdb-fr_FR.mo`.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html), same as MxChat itself.

## Acknowledgements

- [MxChat](https://mxchat.ai/) — the chatbot plugin this companion extends.
- [DuckDB](https://duckdb.org/) and the [VSS extension](https://duckdb.org/docs/extensions/vss).
- [MotherDuck](https://motherduck.com/) for the hosted DuckDB experience.
