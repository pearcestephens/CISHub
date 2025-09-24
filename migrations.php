<?php
declare(strict_types=1);

/**
 * assets/services/queue/migrations.php
 *
 * File-system backed SQL migrations manager.
 * - Place .sql files in ./migrations/pending
 * - "Dry Run" parses and reports statements & errors without executing
 * - "Apply" wraps all statements in a single transaction; on error rolls back and
 *   moves the file to ./migrations/failed with an error report .err.json
 * - Successful applies move to ./migrations/applied with a .log.json report
 * - Apply is gated by either env MIGRATIONS_ALLOW_APPLY=1 or a ".allow_apply" file
 */

if (session_status() !== PHP_SESSION_ACTIVE) { @session_name('queue_migrations'); @session_start(); }

$baseDir   = __DIR__;
$migDir    = $baseDir . '/migrations';
$dirPending= $migDir . '/pending';
$dirApplied= $migDir . '/applied';
$dirFailed = $migDir . '/failed';

@mkdir($dirPending, 0775, true);
@mkdir($dirApplied, 0775, true);
@mkdir($dirFailed, 0775, true);

function h(?string $s): string {
  return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function respond_json(int $code, array $body): never {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}
function mig_csrf_token(): string {
  if (empty($_SESSION['mig_csrf'])) $_SESSION['mig_csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['mig_csrf'];
}
function mig_csrf_validate(string $token): bool {
  return hash_equals($_SESSION['mig_csrf'] ?? '', $token);
}
function allow_apply(string $migDir): bool {
  $env = getenv('MIGRATIONS_ALLOW_APPLY');
  if ($env !== false && (string)$env === '1') return true;
  return is_file(rtrim($migDir, '/') . '/.allow_apply');
}

/**
 * Robust SQL splitter (quotes + comments aware).
 * Returns array of ['sql','start_line','end_line']
 */
function split_sql_statements(string $sql): array {
  $len = strlen($sql);
  $buf = '';
  $out = [];
  $inS = false; $inD = false;
  $inLineC = false; $inBlockC = false;
  $line = 1; $startLine = 1;

  for ($i=0; $i<$len; $i++) {
    $ch = $sql[$i];
    $nx = $i+1 < $len ? $sql[$i+1] : '';

    if ($ch === "\n") $line++;

    if (!$inS && !$inD) {
      if (!$inBlockC && !$inLineC && $ch === '-' && $nx === '-') { $inLineC = true; }
      if (!$inBlockC && !$inLineC && $ch === '/' && $nx === '*') { $inBlockC = true; }
      if ($inLineC && $ch === "\n") { $inLineC = false; }
      if ($inBlockC && $ch === '*' && $nx === '/') { $inBlockC = false; $i++; continue; }
    }
    if ($inLineC || $inBlockC) { $buf .= $ch; continue; }

    if ($ch === "'" && !$inD) { $inS = !$inS; $buf .= $ch; continue; }
    if ($ch === '"' && !$inS) { $inD = !$inD; $buf .= $ch; continue; }

    if ($ch === ';' && !$inS && !$inD) {
      $trim = trim($buf);
      if ($trim !== '') $out[] = ['sql'=>$trim, 'start_line'=>$startLine, 'end_line'=>$line];
      $buf = '';
      $startLine = $line;
      continue;
    }

    $buf .= $ch;
  }

  $trim = trim($buf);
  if ($trim !== '') $out[] = ['sql'=>$trim, 'start_line'=>$startLine, 'end_line'=>$line];

  return $out;
}

/** Very small PDO bootstrap sourced from ENV or app.php if present. */
function get_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  // Try to load /app.php to populate env constants, if present
  $appFile = null;
  $candidates = [];
  $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
  if ($docRoot !== '') {
    $candidates[] = rtrim((string)$docRoot, '/') . '/app.php';
  }
  // Common relative locations from this script
  $candidates[] = dirname(__DIR__, 3) . '/app.php';                    // /public_html/app.php
  $candidates[] = dirname(__DIR__, 4) . '/public_html/app.php';        // /…/app_root/public_html/app.php
  $candidates[] = dirname(__DIR__, 5) . '/public_html/app.php';        // in case of deeper nesting

  foreach ($candidates as $cand) {
    if ($cand && is_readable($cand)) { $appFile = $cand; break; }
  }
  if ($appFile) {
    try { require_once $appFile; } catch (\Throwable $e) { /* ignore */ }
  }

  $host = getenv('DB_HOST') ?: '127.0.0.1';
  $db   = getenv('DB_NAME') ?: '';
  $user = getenv('DB_USER') ?: '';
  $pass = getenv('DB_PASS') ?: '';
  $port = getenv('DB_PORT') ?: '3306';

  if ($db === '' || $user === '') {
    throw new \RuntimeException('Database credentials not available. Set DB_HOST, DB_NAME, DB_USER, DB_PASS.');
  }

  $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
  $opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
  ];

  $pdo = new PDO($dsn, $user, $pass, $opts);
  return $pdo;
}

function list_sql_files(string $dir): array {
  if (!is_dir($dir)) return [];
  $out = [];
  foreach (glob($dir . '/*.sql') ?: [] as $p) {
    $out[] = [
      'file' => basename($p),
      'size' => (int)filesize($p),
      'mtime'=> (int)filemtime($p),
      'path' => $p,
    ];
  }
  usort($out, fn($a,$b) => strcmp($a['file'], $b['file']));
  return $out;
}

function apply_migration_file(string $path, bool $dryRun = true): array {
  $sql = @file_get_contents($path);
  if ($sql === false) {
    return ['ok'=>false,'applied_count'=>0,'total'=>0,'error'=>['message'=>'Unable to read file','file'=>basename($path)],'statements'=>[]];
  }

  $stmts = split_sql_statements($sql);
  $applied = 0;
  $error = null;

  if ($dryRun) {
    return ['ok'=>true,'applied_count'=>0,'total'=>count($stmts),'error'=>null,'statements'=>$stmts];
  }

  $pdo = get_pdo();
  $pdo->beginTransaction();
  try {
    foreach ($stmts as $idx => $st) {
      $sqlStmt = $st['sql'];
      try {
        $pdo->exec($sqlStmt);
        $applied++;
      } catch (\PDOException $e) {
        $error = [
          'message'    => $e->getMessage(),
          'code'       => (int)$e->getCode(),
          'stmt_index' => $idx,
          'start_line' => $st['start_line'],
          'end_line'   => $st['end_line'],
          'sql_excerpt'=> mb_substr($sqlStmt, 0, 400),
        ];
        $pdo->rollBack();
        return ['ok'=>false,'applied_count'=>0,'total'=>count($stmts),'error'=>$error,'statements'=>$stmts];
      }
    }
    $pdo->commit();
  } catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = ['message'=>$e->getMessage(),'code'=>(int)($e->getCode() ?: 0)];
    return ['ok'=>false,'applied_count'=>0,'total'=>count($stmts),'error'=>$error,'statements'=>$stmts];
  }

  return ['ok'=>true,'applied_count'=>$applied,'total'=>count($stmts),'error'=>null,'statements'=>$stmts];
}

function move_to(string $path, string $targetDir): string {
  $dest = rtrim($targetDir, '/') . '/' . basename($path);
  if (!@rename($path, $dest)) {
    throw new \RuntimeException('Failed to move file to ' . $targetDir);
  }
  return $dest;
}

// ---- Actions ----
$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$result  = null;
$message = '';

if ($action === 'dry_run' || $action === 'apply') {
  $file  = basename((string)($_POST['file'] ?? ''));
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
        $message = 'Apply is disabled. Set env MIGRATIONS_ALLOW_APPLY=1 or create ' . h($migDir . '/.allow_apply');
      } else {
        $started = microtime(true);
        $res     = apply_migration_file($path, $dry);
        $durMs   = (int)round((microtime(true) - $started) * 1000);
        $result  = $res + ['duration_ms'=>$durMs,'file'=>$file,'dry_run'=>$dry];

        // Write adjacent log
        @file_put_contents($dirPending . '/' . $file . '.log.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$dry) {
          if ($res['ok']) {
            try { move_to($path, $dirApplied); } catch (\Throwable $e) { $message = 'Applied but failed to move file: ' . $e->getMessage(); }
          } else {
            try { move_to($path, $dirFailed); } catch (\Throwable $e) { $message = 'Failed to move error file: ' . $e->getMessage(); }
            @rename($dirPending . '/' . $file . '.log.json', $dirFailed . '/' . $file . '.err.json');
          }
        }
      }
    }
  }
}

// ---- CLI Mode ----
if (PHP_SAPI === 'cli') {
  $cmd  = $argv[1] ?? '';
  $file = $argv[2] ?? '';
  if ($cmd === 'dry-run' || $cmd === 'apply') {
    if ($file === '') {
      fwrite(STDERR, "Usage: php migrations.php [dry-run|apply] <filename.sql>\n");
      exit(2);
    }
    $path = $dirPending . '/' . basename($file);
    if (!is_file($path)) {
      fwrite(STDERR, "Pending migration not found: {$file}\n");
      exit(3);
    }
    $dry = ($cmd === 'dry-run');
    if (!$dry && !allow_apply($migDir)) {
      fwrite(STDERR, "Apply is disabled. Enable with MIGRATIONS_ALLOW_APPLY=1 or create {$migDir}/.allow_apply\n");
      exit(4);
    }
    $started = microtime(true);
    $res     = apply_migration_file($path, $dry);
    $durMs   = (int)round((microtime(true) - $started) * 1000);
    $result  = $res + ['duration_ms'=>$durMs,'file'=>basename($file),'dry_run'=>$dry];

    // Write adjacent log
    @file_put_contents($dirPending . '/' . basename($file) . '.log.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    if (!$dry) {
      if ($res['ok']) {
        try { move_to($path, $dirApplied); } catch (\Throwable $e) { /* ignore in CLI */ }
      } else {
        try { move_to($path, $dirFailed); } catch (\Throwable $e) { /* ignore in CLI */ }
        @rename($dirPending . '/' . basename($file) . '.log.json', $dirFailed . '/' . basename($file) . '.err.json');
      }
    }

    // Emit JSON to stdout
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit($res['ok'] ? 0 : 1);
  }
}

// ---- Lists ----
$pending = list_sql_files($dirPending);
$applied = list_sql_files($dirApplied);
$failed  = list_sql_files($dirFailed);

// ---- HTML ----
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CIS Queue Migrations</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;line-height:1.4;margin:0;padding:0;background:#0b0f14;color:#e6edf3}
    a{color:#6ea8fe}.wrap{max-width:1100px;margin:24px auto;padding:0 16px}
    .card{background:#0f1721;border:1px solid #1f2b3a;border-radius:8px;margin-bottom:16px}
    .card h2{margin:0;padding:12px 16px;border-bottom:1px solid #1f2b3a;font-size:18px}
    .card .body{padding:12px 16px}
    .muted{color:#9fb0c0}table{width:100%;border-collapse:collapse}
    th,td{padding:8px;border-bottom:1px solid #1f2b3a;font-size:13px}th{text-align:left;color:#9fb0c0}
    .grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #1f2b3a}
    .ok{color:#73d13d}.err{color:#ff6b6b}.btn{background:#1a2635;color:#e6edf3;border:1px solid #2a3b52;border-radius:6px;padding:6px 10px;font-size:13px;cursor:pointer}
    .btn:hover{background:#223147}.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace}
    .note{font-size:12px;color:#9fb0c0}.danger{color:#ff6b6b}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2>SQL Migrations <span class="badge">filesystem</span>
      <a class="right" href="https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php" style="float:right">Back to Dashboard</a>
    </h2>
    <div class="body">
      <div class="grid">
        <div><div class="muted">Pending folder</div><div class="mono"><?=h($dirPending)?></div></div>
        <div><div class="muted">Applied folder</div><div class="mono"><?=h($dirApplied)?></div></div>
        <div><div class="muted">Failed folder</div><div class="mono"><?=h($dirFailed)?></div></div>
      </div>

      <p class="note">Place <strong>.sql</strong> files into <strong>pending</strong>. Use Dry Run to validate; Apply wraps all statements in a single transaction.</p>
      <p class="note"><strong>Apply gate:</strong>
        <?= allow_apply($migDir) ? '<span class="ok">ENABLED</span>' :
          '<span class="danger">DISABLED</span> (set MIGRATIONS_ALLOW_APPLY=1 or create <span class="mono">'.h($migDir).'/.allow_apply</span>)' ?>
      </p>

      <?php if ($message): ?>
        <p class="danger">⚠ <?= h($message) ?></p>
      <?php endif; ?>

      <?php if ($result): ?>
        <div class="card" style="margin-top:12px">
          <h2>Last Result</h2>
          <div class="body">
            <div>File: <span class="mono"><?=h($result['file']??'')?></span></div>
            <div>Mode: <?= ($result['dry_run']??true) ? '<span class="badge">DRY-RUN</span>' : '<span class="badge">APPLY</span>' ?></div>
            <div>Statements: <?= (int)($result['total']??0) ?> | Duration: <?= (int)($result['duration_ms']??0) ?>ms</div>
            <div>Status: <?= ($result['ok']??false) ? '<span class="ok">OK</span>' : '<span class="err">FAILED</span>' ?></div>
            <?php if (!($result['ok']??false) && isset($result['error'])): ?>
              <details open><summary class="danger">Error details</summary>
                <pre class="mono" style="white-space:pre-wrap"><?=h(json_encode($result['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))?></pre>
              </details>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Pending (<?=count($pending)?>)</h2>
      <div class="body">
        <?php if (!$pending): ?>
          <div class="muted">No pending migrations found.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($pending as $p): ?>
                <tr>
                  <td class="mono"><?=h($p['file'])?></td>
                  <td><?=number_format($p['size'])?> bytes</td>
                  <td><?=date('Y-m-d H:i:s', $p['mtime'])?></td>
                  <td>
                    <form method="post" style="display:inline" action="migrations.php">
                      <input type="hidden" name="csrf" value="<?=h(mig_csrf_token())?>">
                      <input type="hidden" name="file" value="<?=h($p['file'])?>">
                      <button class="btn" name="action" value="dry_run" title="Parse & validate">Dry Run</button>
                    </form>
                    <form method="post" style="display:inline" action="migrations.php"
                      onsubmit="return <?= allow_apply($migDir) ? 'confirm(\'Apply this migration in a single transaction?\')' : '(alert(\'Apply is disabled. See note above.\'), false)' ?>;">
                      <input type="hidden" name="csrf" value="<?=h(mig_csrf_token())?>">
                      <input type="hidden" name="file" value="<?=h($p['file'])?>">
                      <button class="btn" name="action" value="apply" <?= allow_apply($migDir) ? '' : 'disabled' ?>>Apply</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Applied (<?=count($applied)?>)</h2>
      <div class="body">
        <?php if (!$applied): ?>
          <div class="muted">No applied migrations yet.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Applied At</th></tr></thead>
            <tbody>
              <?php foreach ($applied as $a): ?>
                <tr>
                  <td class="mono"><?=h($a['file'])?></td>
                  <td><?=number_format($a['size'])?> bytes</td>
                  <td><?=date('Y-m-d H:i:s', $a['mtime'])?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2>Failed (<?=count($failed)?>)</h2>
      <div class="body">
        <?php if (!$failed): ?>
          <div class="muted">No failed migrations.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>File</th><th>Size</th><th>Failed At</th></tr></thead>
            <tbody>
              <?php foreach ($failed as $f): ?>
                <tr>
                  <td class="mono"><?=h($f['file'])?></td>
                  <td><?=number_format($f['size'])?> bytes</td>
                  <td><?=date('Y-m-d H:i:s', $f['mtime'])?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="note">Check <span class="mono">.err.json</span> in the <em>failed</em> folder for full error details.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Safety & Diagnostics</h2>
    <div class="body">
      <ul>
        <li>All statements in a file run inside a single transaction; on error the transaction is rolled back.</li>
        <li>Error reports include statement index and line range for fast fixing.</li>
        <li>Outcomes logged alongside the file as <span class="mono">.log.json</span>; failures also write <span class="mono">.err.json</span>.</li>
        <li>Apply is gated for safety — enable via env or a marker file in the migrations root.</li>
      </ul>
    </div>
  </div>
</div>
</body>
</html>
