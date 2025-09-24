<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoConnection.php';
use Queue\Http; use Queue\PdoConnection;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$keys     = $in['keys']     ?? [];   // array of legacy keys to migrate
$ns       = $in['namespace']?? 'system.queue.lightspeed';
$delete   = !empty($in['delete_legacy']); // after copy
$copied   = []; $missing = []; $errors = [];

try {
  // Load cis-config
  $paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/cis-config/ConfigV2.php',
    dirname(__DIR__, 2) . '/cis-config/ConfigV2.php',
  ];
  $v2ok = false;
  foreach ($paths as $p) if (is_file($p)) { require_once $p; $v2ok = true; break; }
  if (!$v2ok || !class_exists('\\ConfigV2')) { Http::error('cis_config_missing','ConfigV2 not found'); return; }

  $pdo = PdoConnection::instance();

  foreach ((array)$keys as $k) {
    try {
      $stmt = $pdo->prepare('SELECT config_value FROM configuration WHERE config_label=:k LIMIT 1');
      $stmt->execute([':k'=>$k]);
      $val = $stmt->fetchColumn();
      if ($val === false || $val === null || $val === '') { $missing[] = $k; continue; }

      // decode JSON or scalar
      $decoded = json_decode((string)$val, true);
      $toSet = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $val;

      \ConfigV2::set($ns, $k, $toSet);
      $copied[] = $k;

      if ($delete) {
        $pdo->prepare('DELETE FROM configuration WHERE config_label=:k LIMIT 1')->execute([':k'=>$k]);
      }
    } catch (\Throwable $e) { $errors[$k] = $e->getMessage(); }
  }

  Http::respond(true, ['namespace'=>$ns,'copied'=>$copied,'missing'=>$missing,'errors'=>$errors]);
} catch (\Throwable $e) {
  Http::error('migration_failed',$e->getMessage(), null, 500);
}
