<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http; use Queue\Config;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }

$pdo = \Queue\PdoConnection::instance();
$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true) ?: [];
$delete = (bool)($in['delete'] ?? false);
$patterns = $in['patterns'] ?? ['vend.%','lightspeed.%','ls_%','webhook.%','queue.%'];

$stmt = $pdo->prepare('SELECT config_label, config_value, updated_at FROM configuration WHERE ' . implode(' OR ', array_fill(0, count($patterns), 'config_label LIKE ?')) . ' ORDER BY config_label ASC');
$stmt->execute($patterns);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

// Heuristics to consider keys redundant/old
$now = time();
$toDelete = [];
foreach ($rows as $r) {
    $key = (string)$r['config_label'];
    $val = (string)$r['config_value'];
    $ageDays = 0; if (!empty($r['updated_at'])) { $ageDays = max(0, (int) floor(($now - strtotime((string)$r['updated_at'])) / 86400)); }
    $redundant = false; $reason = '';
    if (preg_match('/^(vend\.|lightspeed\.)/i', $key)) {
        // Keep only the current set we actively read from code
        $keep = [
            'vend.api_base','vend.retry_attempts','vend.timeout_seconds','vend_refresh_token','vend.http.enabled','vend.http_mock','webhook.enabled','webhook.fanout.enabled',
        ];
        if (!in_array(strtolower($key), array_map('strtolower',$keep), true)) { $redundant = true; $reason = 'legacy vendor key'; }
    }
    if (preg_match('/^(ls_|ls\.)/i', $key)) { $redundant = true; $reason = $reason ?: 'legacy ls_* key'; }
    if (stripos($key, 'deprecated') !== false) { $redundant = true; $reason = $reason ?: 'marked deprecated'; }
    if ($redundant) { $toDelete[] = ['key'=>$key,'reason'=>$reason,'age_days'=>$ageDays,'value_preview'=>substr($val,0,200)]; }
}

if ($delete && $toDelete) {
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM configuration WHERE config_label = ? LIMIT 1');
        foreach ($toDelete as $d) { $del->execute([$d['key']]); }
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); Http::error('delete_failed', $e->getMessage(), null, 500); return; }
}

echo json_encode([
  'ok' => true,
  'matched' => count($rows),
  'proposed_deletes' => $toDelete,
  'deleted' => $delete ? count($toDelete) : 0,
  'patterns' => $patterns,
]);
