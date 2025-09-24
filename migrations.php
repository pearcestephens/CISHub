<?php declare(strict_types=1);
/**
 * File: assets/services/queue/migrations.php
 * Purpose: Filesystem-based SQL migrations runner with detailed error handling (dry-run/apply)
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 * Dependencies: attempts to include /app.php for DB; falls back to ENV-based PDO
 *
 * Links:
 * - Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/dashboard.php
 */

// Bootstrap (optional app.php if present for DB/session/security)
// Use an isolated session namespace for migrations to avoid clashing with staff or global sessions
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_name('queue_migrations');
  @session_start();
}
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4);
$appFile = rtrim($docRoot, '/').'/app.php';
if (is_readable($appFile)) {
    try { require_once $appFile; } catch (\Throwable $e) { /* non-fatal */ }
}

// --- Utilities ---
/** HTML escape */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Mask all but last N characters */
function mask_tail(string $v, int $keep = 4): string {
    $len = strlen($v); if ($len <= $keep) return str_repeat('•', $len);
    return str_repeat('•', max(0, $len - $keep)) . substr($v, -$keep);
}

/** Simple JSON responder (for future AJAX) */
function respond_json(int $code, array $body): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * CSRF helpers (scoped for this page to avoid collisions with global security.php)
 */
function mig_csrf_token(): string {
  if (empty($_SESSION['mig_csrf'])) { $_SESSION['mig_csrf'] = bin2hex(random_bytes(16)); }
  return $_SESSION['mig_csrf'];
}
function mig_csrf_validate(string $token): bool { return hash_equals($_SESSION['mig_csrf'] ?? '', $token); }

// --- Paths ---
$baseDir = __DIR__;
$migDir  = $baseDir . '/migrations'; // this file sits alongside migrations/
$dirPending = $migDir . '/pending';
$dirApplied = $migDir . '/applied';
$dirFailed  = $migDir . '/failed';
@mkdir($dirPending, 0775, true);
@mkdir($dirApplied, 0775, true);
@mkdir($dirFailed, 0775, true);

/** Determine if Apply is allowed (safety gate) */
function allow_apply(string $migDir): bool {
  $env = getenv('MIGRATIONS_ALLOW_APPLY');
  if ($env !== false && (string)$env === '1') return true;
  if (is_file(rtrim($migDir, '/').'/.allow_apply')) return true;
  return false;
}

/**
 * List .sql files in a directory
 * @return array<int, array{file:string, path:string, size:int, mtime:int}>
 */
function list_sql_files(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (glob($dir . '/*.sql') ?: [] as $path) {
        $out[] = [
            'file' => basename($path),
            'path' => $path,
            'size' => (int)@filesize($path),
            'mtime'=> (int)@filemtime($path),
        ];
    }
    usort($out, fn($a,$b) => strcmp($a['file'], $b['file']));
    return $out;
}

/**
 * Very conservative SQL splitter: respects single/double quotes and basic --//* comments.
 * Not a full SQL parser, but adequate for migration scripts composed of standard statements.
 * @return array<int, array{sql:string, start_line:int, end_line:int}>
 */
function split_sql_statements(string $sql): array {
    $statements = [];
    $len = strlen($sql);
    $buf = '';
    $inSingle = false; $inDouble = false; $inLineComment = false; $inBlockComment = false;
    $line = 1; $stmtStartLine = 1;
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i+1] : '';
        // Track newlines for line numbers
        if ($ch === "\n") { $line++; }
        // Handle comment state transitions when not inside strings
        if (!$inSingle && !$inDouble) {
            if (!$inBlockComment && !$inLineComment && $ch === '-' && $next === '-') { $inLineComment = true; }
            if (!$inBlockComment && !$inLineComment && $ch === '/' && $next === '*') { $inBlockComment = true; }
            if ($inLineComment && ($ch === "\n")) { $inLineComment = false; }
            if ($inBlockComment && $ch === '*' && $next === '/') { $inBlockComment = false; $i++; continue; }
        }
        if ($inLineComment || $inBlockComment) { $buf .= $ch; continue; }
        // String toggles
        if ($ch === "'" && !$inDouble) { $inSingle = !$inSingle; $buf .= $ch; continue; }
        if ($ch === '"' && !$inSingle) { $inDouble = !$inDouble; $buf .= $ch; continue; }
        // Statement boundary
        if ($ch === ';' && !$inSingle && !$inDouble) {
            $trim = trim($buf);
            if ($trim !== '') {
                $endLine = $line;
                $statements[] = ['sql' => $trim, 'start_line' => $stmtStartLine, 'end_line' => $endLine];
            }
            $buf = '';
            $stmtStartLine = $line;
            continue;
        }
        $buf .= $ch;
    }
    $trim = trim($buf);
    if ($trim !== '') {
        $statements[] = ['sql' => $trim, 'start_line' => $stmtStartLine, 'end_line' => $line];
    }
    return $statements;
}

/** Acquire PDO connection */
function get_pdo(): \PDO {
    static $pdo = null;
    if ($pdo instanceof \PDO) return $pdo;
    // Use global if app.php provided one
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof \PDO) { $pdo = $GLOBALS['pdo']; return $pdo; }
    // Try ENV-based fallback
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $db   = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    $port = getenv('DB_PORT') ?: '3306';
    if ($db === '' || $user === '') {
        throw new \RuntimeException('Database credentials not available. Ensure app.php or ENV (DB_HOST, DB_NAME, DB_USER, DB_PASS).');
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    $opts = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
        \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];
    $pdo = new \PDO($dsn, $user, $pass, $opts);
    return $pdo;
}

/**
 * Apply a migration file (transactional). On error, rollback and capture details.
 * @return array{ok:bool, applied_count:int, total:int, error?:array, statements:array}
 */
function apply_migration_file(string $path, bool $dryRun = true): array {
    $sql = @file_get_contents($path);
    if ($sql === false) {
        return ['ok' => false, 'applied_count' => 0, 'total' => 0, 'error' => ['message' => 'Unable to read file', 'file' => basename($path)], 'statements' => []];
    }
    $stmts = split_sql_statements($sql);
    $pdo = null; $txStarted = false; $applied = 0; $errors = null;
    try {
        if (!$dryRun) {
            $pdo = get_pdo();
            $pdo->beginTransaction();
            $txStarted = true;
        }
        foreach ($stmts as $idx => $st) {
            $sqlStmt = $st['sql'];
            if ($dryRun) { continue; }
            try {
                $affected = $pdo->exec($sqlStmt);
                $applied++;
            } catch (\PDOException $e) {
                $errors = [
                    'message' => $e->getMessage(),
                    'code'    => (int)$e->getCode(),
                    'stmt_index' => $idx,
                    'start_line' => $st['start_line'],
                    'end_line'   => $st['end_line'],
                    'sql_excerpt'=> mb_substr($sqlStmt, 0, 400),
                ];
                if ($txStarted) { $pdo->rollBack(); $txStarted = false; }
                break;
            }
        }
        if (!$dryRun && $txStarted) { $pdo->commit(); $txStarted = false; }
    } catch (\Throwable $e) {
        $errors = [ 'message' => $e->getMessage(), 'code' => (int)($e->getCode() ?: 0) ];
        if ($txStarted) { try { $pdo->rollBack(); } catch (\Throwable $e2) { /* ignore */ } }
    }
    return [
        'ok' => $errors === null,
        'applied_count' => $dryRun ? 0 : $applied,
        'total' => count($stmts),
        'error' => $errors,
        'statements' => $stmts,
    ];
}

/** Move file to another dir with same basename */
function move_to(string $path, string $targetDir): string {
    $base = basename($path);
    $dest = rtrim($targetDir, '/').'/'.$base;
    if (!@rename($path, $dest)) {
        throw new \RuntimeException('Failed to move file to '.$targetDir);
    }
    return $dest;
}

// --- Handle Actions ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$result = null; $message = '';
if ($action === 'dry_run' || $action === 'apply') {
    $file = basename((string)($_POST['file'] ?? ''));
  $token = (string)($_POST['csrf'] ?? '');
  if (!mig_csrf_validate($token)) {
        $message = 'Invalid CSRF token.';
    } else {
        $path = $dirPending . '/' . $file;
        if (!is_file($path)) {
            $message = 'File not found in pending: ' . h($file);
        } else {
      $dry = ($action === 'dry_run');
      if (!$dry && !allow_apply($migDir)) {
        $message = 'Apply is disabled. Set environment MIGRATIONS_ALLOW_APPLY=1 or create ' . h($migDir . '/.allow_apply');
      } else {
            $startedAt = microtime(true);
            $res = apply_migration_file($path, $dry);
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $result = $res + ['duration_ms' => $durationMs, 'file' => $file, 'dry_run' => $dry];
            // Persist outcome logs
            $log = $dirPending . '/' . $file . '.log.json';
            @file_put_contents($log, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            if (!$dry) {
                if ($res['ok']) {
                    try { move_to($path, $dirApplied); } catch (\Throwable $e) { $message = 'Applied but failed to move file: '.$e->getMessage(); }
                } else {
                    try { move_to($path, $dirFailed); } catch (\Throwable $e) { $message = 'Failed to move error file: '.$e->getMessage(); }
                    // Copy error log alongside failed file for audit
                    @rename($log, $dirFailed . '/' . $file . '.err.json');
                }
            }
      }
        }
    }
}

$pending = list_sql_files($dirPending);
$applied = list_sql_files($dirApplied);
$failed  = list_sql_files($dirFailed);

// --- Render UI ---
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CIS Queue Migrations</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;line-height:1.4;margin:0;padding:0;background:#0b0f14;color:#e6edf3}
    a{color:#6ea8fe}
    .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:#0f1721;border:1px solid #1f2b3a;border-radius:8px;margin-bottom:16px}
    .card h2{margin:0;padding:12px 16px;border-bottom:1px solid #1f2b3a;font-size:18px}
    .card .body{padding:12px 16px}
    .muted{color:#9fb0c0}
    table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid #1f2b3a;font-size:13px}
    th{text-align:left;color:#9fb0c0}
    .row{display:flex;gap:16px;flex-wrap:wrap}
    .col{flex:1 1 360px}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #1f2b3a}
    .ok{color:#73d13d}
    .err{color:#ff6b6b}
    .btn{background:#1a2635;color:#e6edf3;border:1px solid #2a3b52;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer}
    .btn:hover{background:#223147}
    .right{float:right}
    .note{font-size:12px;color:#9fb0c0}
    .danger{color:#ff6b6b}
    .mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace}
    .grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
    @media (max-width:900px){.grid{grid-template-columns:1fr}}
    pre{background:#0b121a;border:1px solid #1f2b3a;border-radius:6px;padding:8px;overflow:auto}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
  <h2>SQL Migrations <span class="badge">filesystem</span> <a class="right" href="https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php">Back to Dashboard</a></h2>
    <div class="body">
      <div class="grid">
        <div>
          <div class="muted">Pending folder</div>
          <div class="mono"><?php echo h($dirPending); ?></div>
        </div>
        <div>
          <div class="muted">Applied folder</div>
          <div class="mono"><?php echo h($dirApplied); ?></div>
        </div>
        <div>
          <div class="muted">Failed folder</div>
          <div class="mono"><?php echo h($dirFailed); ?></div>
        </div>
      </div>
      <p class="note">Place .sql files into <strong>pending</strong>. Use Dry Run to validate; Apply wraps all statements in a single transaction. On error, the transaction is rolled back and the file is moved to <strong>failed</strong> with an error report.</p>
      <p class="note"><strong>Apply gate:</strong> <?php echo allow_apply($migDir) ? '<span class=\'ok\'>ENABLED</span>' : '<span class=\'danger\'>DISABLED</span> (set env MIGRATIONS_ALLOW_APPLY=1 or create <span class=\'mono\'>'.h($migDir).'/.allow_apply</span>)'; ?></p>
      <?php if ($message): ?>
        <p class="danger">⚠ <?php echo h($message); ?></p>
      <?php endif; ?>
      <?php if ($result): ?>
        <div class="card" style="margin-top:12px">
          <h2>Last Result</h2>
          <div class="body">
            <div>File: <span class="mono"><?php echo h($result['file'] ?? ''); ?></span></div>
            <div>Mode: <?php echo ($result['dry_run'] ?? true) ? '<span class="badge">DRY-RUN</span>' : '<span class="badge">APPLY</span>'; ?></div>
            <div>Statements: <?php echo (int)($result['total'] ?? 0); ?> | Duration: <?php echo (int)($result['duration_ms'] ?? 0); ?> ms</div>
            <div>Status: <?php echo ($result['ok'] ?? false) ? '<span class="ok">OK</span>' : '<span class="err">FAILED</span>'; ?></div>
            <?php if (!($result['ok'] ?? false) && isset($result['error'])): ?>
              <details open>
                <summary class="danger">Error details</summary>
                <pre><?php echo h(json_encode($result['error'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre>
              </details>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row">
    <div class="card col">
      <h2>Pending (<?php echo count($pending); ?>)</h2>
      <div class="body">
        <?php if (!$pending): ?>
          <div class="muted">No pending migrations found.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
              <tr>
                <td class="mono"><?php echo h($p['file']); ?></td>
                <td><?php echo number_format($p['size']); ?> bytes</td>
                <td><?php echo date('Y-m-d H:i:s', $p['mtime']); ?></td>
                <td>
                  <form method="post" style="display:inline" action="https://staff.vapeshed.co.nz/assets/services/queue/migrations.php">
                    <input type="hidden" name="csrf" value="<?php echo h(mig_csrf_token()); ?>">
                    <input type="hidden" name="file" value="<?php echo h($p['file']); ?>">
                    <button class="btn" name="action" value="dry_run" title="Parse and validate without executing">Dry Run</button>
                  </form>
                  <form method="post" style="display:inline" action="https://staff.vapeshed.co.nz/assets/services/queue/migrations.php" onsubmit="return <?php echo allow_apply($migDir) ? 'confirm(\'Apply this migration in a single transaction?\')' : '(alert(\'Apply is disabled. See Apply gate note above.\'), false)'; ?>;">
                    <input type="hidden" name="csrf" value="<?php echo h(mig_csrf_token()); ?>">
                    <input type="hidden" name="file" value="<?php echo h($p['file']); ?>">
                    <button class="btn" name="action" value="apply" <?php echo allow_apply($migDir) ? '' : 'disabled'; ?>>Apply</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card col">
      <h2>Applied (<?php echo count($applied); ?>)</h2>
      <div class="body">
        <?php if (!$applied): ?>
          <div class="muted">No applied migrations yet.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Applied At</th></tr></thead>
            <tbody>
            <?php foreach ($applied as $a): ?>
              <tr>
                <td class="mono"><?php echo h($a['file']); ?></td>
                <td><?php echo number_format($a['size']); ?> bytes</td>
                <td><?php echo date('Y-m-d H:i:s', $a['mtime']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card col">
      <h2>Failed (<?php echo count($failed); ?>)</h2>
      <div class="body">
        <?php if (!$failed): ?>
          <div class="muted">No failed migrations.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Failed At</th></tr></thead>
            <tbody>
            <?php foreach ($failed as $f): ?>
              <tr>
                <td class="mono"><?php echo h($f['file']); ?></td>
                <td><?php echo number_format($f['size']); ?> bytes</td>
                <td><?php echo date('Y-m-d H:i:s', $f['mtime']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p class="note">Check for a corresponding <span class="mono">.err.json</span> file in the failed folder for full error details.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Safety & Diagnostics</h2>
    <div class="body">
      <ul>
        <li>All statements run inside a single transaction. On error, the transaction is rolled back.</li>
        <li>Errors report the statement index and line range to speed up fixes.</li>
        <li>Outcomes logged adjacent to migration file as <span class="mono">.log.json</span> or moved with <span class="mono">.err.json</span> on failure.</li>
        <li>Database connection is sourced from <span class="mono">/app.php</span> if available; otherwise from environment variables.</li>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
