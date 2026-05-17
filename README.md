# MxChat DuckDB / MotherDuck

<p align="left">
  <a href="#"><img alt="Plugin version" src="https://img.shields.io/badge/version-0.5.0-blue.svg"></a>
  <a href="#"><img alt="PHP" src="https://img.shields.io/badge/php-%E2%89%A5%208.0-777BB4.svg?logo=php&logoColor=white"></a>
  <a href="#"><img alt="WordPress" src="https://img.shields.io/badge/wordpress-%E2%89%A5%206.0-21759B.svg?logo=wordpress&logoColor=white"></a>
  <a href="https://mxchat.ai/"><img alt="MxChat" src="https://img.shields.io/badge/mxchat-%E2%89%A5%203.2.5-2c3e50.svg"></a>
  <a href="https://duckdb.org/"><img alt="DuckDB" src="https://img.shields.io/badge/duckdb-VSS-FFF000.svg?logo=duckdb&logoColor=black"></a>
  <a href="https://motherduck.com/"><img alt="MotherDuck" src="https://img.shields.io/badge/motherduck-supported-FFD400.svg"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img alt="License: GPL v2+" src="https://img.shields.io/badge/license-GPL%20v2%2B-green.svg"></a>
  <a href="#"><img alt="Status: alpha" src="https://img.shields.io/badge/status-alpha-orange.svg"></a>
  <a href="https://github.com/paulargoud/mxchat-duckdb/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/paulargoud/mxchat-duckdb/actions/workflows/ci.yml/badge.svg"></a>
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
- 🗜️ **INT8 quantization** (experimental, opt-in) — 4× smaller vector storage, < 1 % recall loss on unit-normalised embeddings.
- 🔌 **Drop-in for Pinecone** — implements the Pinecone wire protocol over REST, so MxChat needs zero modifications to use it.
- 🔐 **Per-namespace REST tokens** so leaking one bot's API key doesn't compromise others.
- 🪛 **Optional upstream patch** for direct in-process integration (eliminates one HTTP round-trip; ~12 lines, [see patches/](patches/README.md)).
- 🔁 **Four ingestion paths** — bulk-sync from MySQL, sync reprocess from WordPress posts, async reprocess via Action Scheduler (survives PHP timeouts on large catalogs), and one-shot **Pinecone → DuckDB migration** without re-embedding.
- 📦 **Parquet export/import** — portable backups and seamless moves between embedded ⇄ MotherDuck via DuckDB's native `COPY`.
- 🧰 **Auto cascade-delete** with nonce-verified handler; orphan compactor cron sweeps stragglers.
- 🩺 **`/health` endpoint + rolling p50/p95/p99 latency metrics** for external monitors.
- 🛠️ **WP-CLI**: `wp mxchat-duckdb {test|stats|sync|reprocess|async-reprocess|compact|metrics|cache|export|import|migrate-from-pinecone}`.
- 🧪 **CI on every PR** — `php -l` matrix (PHP 8.0–8.3), `msgfmt` catalog check, PHPStan, PHPUnit smoke suite.
- 🕒 **Hourly WP-cron** for incremental sync of new content + daily orphan compaction.
- 🛡️ **Per-user-role access control** preserved from MxChat (metadata-driven).
- 🌐 **i18n-ready** — English source strings, French translation shipped, `.pot` template for additional locales.

## Architecture

The plugin connects to MxChat via two parallel integration paths (**Option A** — filter override; **Option B** — Pinecone wire-protocol proxy). Both are registered unconditionally; whichever's prerequisite is present at runtime wins.

See [**ARCHITECTURE.md**](ARCHITECTURE.md) for the full integration flowchart, a sequence diagram of the query lifecycle (cache → vector → BM25 → dedup → rerank → metrics), the file layout, and the design conventions contributors should follow.

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

## Documentation

| Doc | What's in it |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the plugin wires into MxChat (flowchart), the query lifecycle (sequence diagram), file layout, design conventions for contributors. |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Every option in `mxchat_duckdb_options`, sidecar options, where data is stored, dimension/storage change guards. |
| [docs/HOOKS.md](docs/HOOKS.md) | Every filter and action the plugin exposes, with signatures and PHP examples. |
| [docs/CLI.md](docs/CLI.md) | Full `wp mxchat-duckdb` reference with sample output. |
| [docs/USAGE.md](docs/USAGE.md) | Howtos: async reprocess, Pinecone migration, Parquet backup/restore, INT8 quantization, `/health` endpoint, end-to-end verification. |
| [CHANGELOG.md](CHANGELOG.md) | Release history. |
| [CONTRIBUTING.md](CONTRIBUTING.md) | How to file a bug, send a PR, run the test suite. |

## Roadmap

- [x] ~~Import-from-Pinecone tool~~ — shipped in v0.4.0 (`wp mxchat-duckdb migrate-from-pinecone`)
- [ ] Submit the upstream patch (`mxchat_pinecone_matches_override` filter) to MxChat
- [ ] Migrate Option B users to Option A automatically once the filter ships
- [ ] PDF / attachment reprocessing (currently only post types are covered)
- [ ] Per-bot configuration UI (multi-bot installs)
- [ ] Built-in cross-encoder reranker (Cohere Rerank / BGE-reranker) plugged into the `mxchat_duckdb_rerank_matches` hook
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
