#!/usr/bin/env php
<?php
declare(strict_types=1);

use Queue\PdoWorkItemRepository;
use Queue\Config;

require __DIR__ . '/../vendor/autoload.php';

$repo = new PdoWorkItemRepository();

$once = in_array('--once', $argv ?? [], true);
if (Config::getBool('vend_queue_kill_switch', false)) {
    echo json_encode(['ok'=>false,'skipped'=>true,'reason'=>'kill_switch','url'=>'https://staff.vapeshed.co.nz/assets/services/queue/bin/schedule-pulls.php'])."\n";
    exit(0);
}

$types = ['pull_products', 'pull_inventory', 'pull_consignments'];
$added = 0;
foreach ($types as $t) {
    $idem = $t . ':' . ($once ? date('YmdHis') : date('YmdHi'));
    $ok = $repo->addJob($t, ['source' => 'schedule'], $idem);
    if ($ok) { $added++; }
}

echo json_encode(['ok' => true, 'scheduled' => $added, 'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/schedule-pulls.php']) . "\n";
