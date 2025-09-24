<?php declare(strict_types=1);
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoWorkItemRepository;

if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('schedule')) { return; }
if (\Queue\FeatureFlags::killAll() || !\Queue\FeatureFlags::runnerEnabled()) { Http::error('schedule_disabled', 'Scheduling is disabled'); return; }

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true) ?: [];
$once = isset($in['once']) ? (bool)$in['once'] : false;

$repo = new PdoWorkItemRepository();
$types = ['pull_products', 'pull_inventory', 'pull_consignments'];
$added = 0;
foreach ($types as $t) {
    $suffix = $once ? (string) time() : date('YmdHi');
    $idem = $t . ':' . $suffix;
    $id = $repo->addJob($t, ['source' => 'manual'], $idem);
    if ($id) { $added++; }
}
Http::respond(true, ['scheduled' => $added]);
