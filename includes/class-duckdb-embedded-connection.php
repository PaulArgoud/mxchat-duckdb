<?php
/**
 * Embedded DuckDB connection.
 *
 * Strategy:
 *   1. If the PECL `duckdb` extension is loaded, use the native C bindings.
 *   2. Otherwise, fall back to invoking the `duckdb` CLI via proc_open with
 *      -json output. The CLI must be present on the server (PATH or configured).
 *
 * Optional `init_sql` (passed by subclasses, e.g. MotherDuck via ATTACH) is
 * executed once at connect time for the extension path, and prepended to each
 * CLI invocation (since CLI sessions are stateless).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_DuckDB_Embedded_Connection implements MxChat_DuckDB_Connection {

    protected string $db_path;
    protected string $binary_path = '';
    protected bool $use_extension = false;
    /** @var object|null Native DuckDB handle if PECL extension is available. */
    protected $native = null;
    /** @var string[] SQL statements to run once at connect (ext) or prepend per-query (CLI). */
    protected array $init_sql = [];

    /**
     * @param array    $opts      Plugin options. Optional 'db_path' overrides resolved path
     *                            (used by MotherDuck wrapper to request ':memory:').
     * @param string[] $init_sql  Statements executed at connect time / prepended to each CLI call.
     */
    public function __construct(array $opts, array $init_sql = []) {
        $this->db_path = !empty($opts['db_path'])
            ? (string) $opts['db_path']
            : MxChat_DuckDB_Options::resolved_embedded_path();
        $this->init_sql = $init_sql;

        // Prefer the PECL extension if loaded — much faster than CLI roundtrips.
        if (extension_loaded('duckdb') && class_exists('DuckDB\\Connection')) {
            try {
                $cls = 'DuckDB\\Connection';
                $this->native = new $cls($this->db_path);
                $this->use_extension = true;
                foreach ($this->init_sql as $stmt) {
                    $this->native->query($stmt);
                }
                return;
            } catch (\Throwable $e) {
                $this->use_extension = false;
                $this->native = null;
            }
        }

        $bin = !empty($opts['embedded_binary']) ? $opts['embedded_binary'] : self::autodetect_binary();
        if (empty($bin) || !is_executable($bin)) {
            throw new RuntimeException(
                __('Embedded DuckDB mode: neither the PHP duckdb extension nor the CLI binary is available. Install ext-duckdb (PECL) or set the binary path in the plugin settings.', 'mxchat-duckdb')
            );
        }
        $this->binary_path = $bin;
    }

    public function execute(string $sql, array $params = []): array {
        $bound_sql = self::inline_params($sql, $params);
        return $this->execute_with_retry($bound_sql, $sql);
    }

    /**
     * Wraps the raw execute with a small retry loop for transient errors
     * (network blips on MotherDuck, momentary lock contention, …). Idempotent
     * SQL only (SELECT-ish). Write SQL is detected via a simple keyword sniff
     * and runs without retry to avoid double-applying.
     */
    private function execute_with_retry(string $bound_sql, string $original_sql): array {
        $max_attempts = self::looks_idempotent($original_sql)
            ? (int) apply_filters('mxchat_duckdb_max_retries', 3)
            : 1;

        $attempt = 0;
        $last_error = null;
        while ($attempt < $max_attempts) {
            $attempt++;
            try {
                if ($this->use_extension && $this->native) {
                    return $this->execute_native($bound_sql);
                }
                return $this->execute_cli($bound_sql);
            } catch (\Throwable $e) {
                $last_error = $e;
                if ($attempt >= $max_attempts || !self::is_transient_error($e)) {
                    throw $e;
                }
                // Exponential backoff with jitter: 50ms, 150ms, 350ms.
                $base_us = (int) (50000 * (1 << ($attempt - 1)));
                $jitter_us = random_int(0, 50000);
                usleep($base_us + $jitter_us);
                MxChat_DuckDB_Metrics::record('retries');
            }
        }
        throw $last_error ?? new RuntimeException('execute_with_retry: exhausted attempts');
    }

    private function execute_native(string $sql): array {
        try {
            $result = $this->native->query($sql);
            $rows = [];
            if (is_iterable($result)) {
                foreach ($result as $row) {
                    $rows[] = (array) $row;
                }
            }
            return $rows;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                __('DuckDB native extension error: ', 'mxchat-duckdb') . $e->getMessage()
            );
        }
    }

    private static function looks_idempotent(string $sql): bool {
        // Cheap regex against the first keyword. False negatives (we don't
        // retry something that's actually safe) are fine; false positives
        // (we retry an INSERT) are not — so we err conservative.
        $first = strtoupper(ltrim($sql));
        return str_starts_with($first, 'SELECT')
            || str_starts_with($first, 'WITH')
            || str_starts_with($first, 'PRAGMA')
            || str_starts_with($first, 'SHOW')
            || str_starts_with($first, 'DESCRIBE')
            || str_starts_with($first, 'EXPLAIN');
    }

    private static function is_transient_error(\Throwable $e): bool {
        $msg = strtolower($e->getMessage());
        $needles = ['timeout', 'temporarily', 'connection reset', 'connection refused',
                    'could not connect', 'eof', 'broken pipe', '503', '502', 'rate limit',
                    'network', 'tls handshake'];
        foreach ($needles as $n) {
            if (str_contains($msg, $n)) return true;
        }
        return false;
    }

    public function ping(): bool {
        try {
            $rows = $this->execute('SELECT 1 AS ok');
            return !empty($rows) && (int) ($rows[0]['ok'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function identifier(): string {
        return 'embedded:' . $this->db_path . ($this->use_extension ? ' (ext)' : ' (cli)');
    }

    /**
     * Invokes the DuckDB CLI with -json output. Init SQL (if any) is prepended
     * to the script piped through stdin, because CLI sessions are stateless.
     *
     * Uses non-blocking pipes + stream_select with a deadline so that a hung
     * CLI (file lock, runaway query, broken pipe) doesn't freeze the PHP-FPM
     * worker indefinitely. The timeout is overridable via the
     * `mxchat_duckdb_cli_timeout_seconds` filter (default 30s).
     *
     * @throws RuntimeException on timeout, non-zero exit, or stderr content.
     */
    private function execute_cli(string $sql): array {
        // Array form of proc_open (PHP 7.4+) — no shell interpolation, no escaping needed.
        $cmd = [$this->binary_path, '-json', '-bail', $this->db_path];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes, null, null);
        if (!is_resource($proc)) {
            throw new RuntimeException(
                __('Failed to launch the DuckDB CLI binary.', 'mxchat-duckdb')
            );
        }

        $script = '';
        foreach ($this->init_sql as $stmt) {
            $script .= rtrim($stmt, ";\n ") . ";\n";
        }
        $script .= rtrim($sql, ";\n ") . ";\n";

        fwrite($pipes[0], $script);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $timeout = max(1, (int) apply_filters('mxchat_duckdb_cli_timeout_seconds', 30));
        $deadline = microtime(true) + $timeout;
        $stdout = '';
        $stderr = '';
        $open = [$pipes[1], $pipes[2]];
        $timed_out = false;

        while (!empty($open)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $timed_out = true;
                break;
            }
            $read = $open;
            $write = $except = null;
            $sec = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false) break;        // signal interrupt
            if ($ready === 0)     continue;     // tick, recheck deadline
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk !== false && $chunk !== '') {
                    if ($stream === $pipes[1]) $stdout .= $chunk;
                    else                       $stderr .= $chunk;
                }
                if (feof($stream)) {
                    $open = array_filter($open, static fn($s) => $s !== $stream);
                }
            }
        }

        if ($timed_out) {
            foreach ($open as $s) { if (is_resource($s)) fclose($s); }
            proc_terminate($proc, 9);
            proc_close($proc);
            throw new RuntimeException(sprintf(
                /* translators: %d = timeout seconds */
                __('DuckDB CLI timed out after %d seconds.', 'mxchat-duckdb'),
                $timeout
            ));
        }

        foreach ([$pipes[1], $pipes[2]] as $s) {
            if (is_resource($s)) fclose($s);
        }
        $exit_code = proc_close($proc);

        if ($exit_code !== 0 || !empty($stderr)) {
            throw new RuntimeException(sprintf(
                /* translators: 1: exit code, 2: stderr content */
                __('DuckDB CLI failed (exit code %1$d): %2$s', 'mxchat-duckdb'),
                $exit_code,
                trim((string) $stderr) ?: __('no error output', 'mxchat-duckdb')
            ));
        }

        if ($stdout === '') {
            return []; // DDL / non-SELECT
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private static function autodetect_binary(): string {
        $candidates = ['/usr/local/bin/duckdb', '/usr/bin/duckdb', '/opt/homebrew/bin/duckdb'];
        foreach ($candidates as $c) {
            if (is_executable($c)) return $c;
        }
        if (function_exists('shell_exec')) {
            $which = trim((string) @shell_exec('command -v duckdb 2>/dev/null'));
            if ($which !== '' && is_executable($which)) return $which;
        }
        return '';
    }

    /**
     * Probe whether the given path is genuinely the DuckDB CLI. Runs a
     * `SELECT 'marker'` over the candidate binary in `-json` mode under a
     * 2-second cap and checks the marker round-tripped. The `-json` flag is
     * DuckDB-specific, so any unrelated binary (e.g. /bin/sh pasted by mistake)
     * fails fast — no false positives. Returns false on any error / timeout;
     * never throws. Used by the settings sanitiser to surface mis-pointed
     * paths before queries start failing cryptically at runtime.
     */
    public static function looks_like_duckdb_binary(string $path): bool {
        if ($path === '' || !is_executable($path)) return false;

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open([$path, '-json'], $descriptors, $pipes, null, null);
        if (!is_resource($proc)) return false;

        fwrite($pipes[0], "SELECT 'mxd_duckdb_probe' AS marker;\n");
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 2.0;
        $stdout = '';
        while (true) {
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                @fclose($pipes[1]); @fclose($pipes[2]);
                proc_close($proc);
                return false;
            }
            $status = proc_get_status($proc);
            $chunk = stream_get_contents($pipes[1]);
            if ($chunk !== false) $stdout .= $chunk;
            if (!$status['running']) {
                $rest = stream_get_contents($pipes[1]);
                if ($rest !== false) $stdout .= $rest;
                break;
            }
            usleep(20_000);
        }
        @fclose($pipes[1]); @fclose($pipes[2]);
        proc_close($proc);

        return str_contains($stdout, 'mxd_duckdb_probe');
    }

    /**
     * Inline ?-parameters as DuckDB literals. Used only for the CLI path.
     * Handles scalars and arrays of floats (embedding vectors).
     *
     * @throws RuntimeException on non-numeric values inside a numeric array
     *         (would silently corrupt embedding vectors otherwise).
     */
    private static function inline_params(string $sql, array $params): string {
        if (empty($params)) return $sql;

        $i = 0;
        return preg_replace_callback('/\?/', function () use (&$i, $params) {
            if (!array_key_exists($i, $params)) {
                $i++;
                return 'NULL';
            }
            $val = $params[$i++];
            return self::to_literal($val);
        }, $sql);
    }

    private static function to_literal($val): string {
        if (is_null($val)) return 'NULL';
        if (is_bool($val)) return $val ? 'TRUE' : 'FALSE';
        if (is_int($val) || is_float($val)) return (string) $val;
        if (is_array($val)) {
            $parts = [];
            foreach ($val as $v) {
                if (is_int($v) || is_float($v)) {
                    $parts[] = (string) $v;
                } elseif (is_numeric($v)) {
                    $parts[] = (string) (float) $v;
                } else {
                    throw new RuntimeException(
                        __('Non-numeric value in array parameter: ', 'mxchat-duckdb')
                        . var_export($v, true)
                    );
                }
            }
            return '[' . implode(',', $parts) . ']';
        }
        return "'" . str_replace("'", "''", (string) $val) . "'";
    }
}
