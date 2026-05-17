# Contributing to MxChat DuckDB / MotherDuck

Thanks for thinking about contributing! Issues and pull requests are both welcome. This file documents the conventions the project follows so reviews stay short.

## Reporting bugs

Open an issue with:

1. **Plugin version**, **WordPress version**, **PHP version**, **mxchat-basic version**.
2. **Backend in use**: MotherDuck or embedded; PECL `duckdb` extension loaded or CLI fallback?
3. **What you did** and **what you expected**.
4. **The last error**, from the admin page or `wp-content/debug.log` (with `WP_DEBUG_LOG` on).
5. A `wp mxchat-duckdb stats` dump if WP-CLI is available — it captures the most useful diagnostic in one paste.

For security issues, please email **paul@argoud.net** instead of opening a public issue.

## Pull requests

1. Fork the repo, branch off `main`.
2. Keep PHP changes compatible with **PHP 8.0+**.
3. Run `php -l` on every changed file. CI doesn't ship yet but the project intends to add a `php -l` matrix; please don't break it preemptively.
4. Match the existing style: tabs are not used, indent with 4 spaces, full `<?php` opening tags, `if (!defined('ABSPATH')) exit;` guard at the top of every included file.
5. Touch user-facing strings? Run them through `__()` / `esc_html__()` with the `'mxchat-duckdb'` text domain, and update `languages/mxchat-duckdb.pot` + `mxchat-duckdb-fr_FR.po`. Recompile with `msgfmt mxchat-duckdb-fr_FR.po -o mxchat-duckdb-fr_FR.mo`.
6. Add an entry to [`CHANGELOG.md`](CHANGELOG.md) under `## [Unreleased]`. Categorise under `Added`, `Changed`, `Fixed`, or `Removed`.
7. If you add a hook (filter / action / cron / REST route), document it in [`README.md`](README.md) and write a one-line `@since` comment in the code.

## Design conventions

- **No HTTP fan-out for MotherDuck.** MotherDuck has no public SQL-execution REST API. Always go through DuckDB's native client + `ATTACH 'md:…'`.
- **Always go through `MxChat_DuckDB_Connection_Factory::current()`** for backend access. The factory caches one connection per request; constructing a connection by hand silently breaks that cache.
- **Schema changes go through a numbered migration.** Bump `MxChat_DuckDB_Vector_Store::TARGET_SCHEMA_VERSION` and add an `apply_migration()` branch. Migrations must be idempotent — they may run against an install that already has parts of the target state.
- **Cache invalidation belongs in the writer**, not the caller. `upsert()` / `delete_*()` flush the query cache themselves; new write paths must do the same (call `MxChat_DuckDB_Plugin::flush_query_cache()`).
- **Don't trust mxchat's nonce check.** AJAX handlers that mutate state must verify the nonce + capability themselves, even when chained behind a mxchat hook.
- **Errors are surfaced, not swallowed.** Use `error_log()` + the `last_error` option + the admin notice transient instead of returning empty matches silently — except where graceful degradation is explicitly documented (FTS extension missing, HNSW index unavailable, etc.).

## Running locally

A real test takes a WordPress install with mxchat-basic. There is no test
harness in the repo yet — patches that add a PHPUnit + WP_Mock setup are very
welcome. Minimum smoke test:

```bash
# Lint everything
for f in mxchat-duckdb.php uninstall.php includes/*.php admin/views/*.php; do
    php -l "$f"
done

# Translation sanity check
msgfmt --check languages/mxchat-duckdb-fr_FR.po -o /tmp/test.mo
```

## License

By contributing, you agree that your contributions will be licensed under the
[GPL v2 or later](LICENSE).
