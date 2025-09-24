<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Degrade.php';
require_once __DIR__ . '/../src/DevFlags.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Config; use Queue\Degrade; use Queue\DevFlags; use Queue\Http;

Http::commonJsonHeaders();
$systemName = (string)(Config::get('system.name', 'CISHUB') ?? 'CISHUB');
$dev = DevFlags::active();
$banner = Degrade::banner();

$messages = [];
if ($banner['active'] && $banner['message'] !== '') { $messages[] = ['level' => $banner['level'], 'message' => $banner['message']]; }
foreach ($dev as $d) { $messages[] = ['level' => 'warning', 'message' => sprintf('%s is active: %s', $d['key'], $d['reason'])]; }

echo json_encode([
  'ok' => true,
  'system' => ['name' => $systemName],
  'dev_flags' => $dev,
  'messages' => $messages,
]);
