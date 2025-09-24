<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoConnection.php';
use Queue\Http; use Queue\PdoConnection;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$ns = $in['namespace'] ?? 'system.queue.lightspeed';
$enforce = !empty($in['delete_legacy_if_v2_exists']);

try {
  $pdo = PdoConnection::instance();

  // load ConfigV2
  $paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/cis-config/ConfigV2.php',
    dirname(__DIR__, 2) . '/cis-config/ConfigV2.php',
  ];
  foreach ($paths as $p) if (is_file($p)) { require_once $p; break; }
  if (!class_exists('\\ConfigV2')) { Http::error('cis_config_missing','ConfigV2 not found'); return; }

  // Legacy keys
  $legacy = [];
  try {
    $rs = $pdo->query('SELECT config_label FROM configuration');
    $legacy = $rs ? $rs->fetchAll(\PDO::FETCH_COLUMN) : [];
  } catch (\Throwable $e) {}

  $onlyLegacy = []; $both = []; $onlyV2 = [];
  foreach ($legacy as $k) {
    $v2 = \ConfigV2::get($ns, $k, '__MISSING__');
    if ($v2 === '__MISSING__') $onlyLegacy[] = $k;
    else $both[] = $k;
  }

  // V2 only keys:
  // If ConfigV2 exposes a list method, use it; otherwise we can’t enumerate. Assume empty here.
  // (If you have ConfigV2::list($ns), drop it below.)
  // $onlyV2 = \ConfigV2::list($ns);

  // Optionally delete legacy duplicates
  $deleted = [];
  if ($enforce && $both) {
    $del = $pdo->prepare('DELETE FROM configuration WHERE config_label=:k LIMIT 1');
    foreach ($both as $k) { try { $del->execute([':k'=>$k]); $deleted[] = $k; } catch(\Throwable $e) {} }
  }

  Http::respond(true, ['namespace'=>$ns,'only_legacy'=>$onlyLegacy,'in_both'=>$both,'only_v2'=>$onlyV2,'deleted'=>$deleted]);
} catch (\Throwable $e) {
  Http::error('namespace_enforce_failed', $e->getMessage(), null, 500);
}
