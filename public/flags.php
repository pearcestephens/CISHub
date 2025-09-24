<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'flags' => \Queue\FeatureFlags::snapshot(),
  'config' => [
    'vend.http_mock' => (bool)(\Queue\Config::get('vend.http_mock', false) ?? false),
    'vend.api_base' => (string)(\Queue\Config::get('vend.api_base', 'https://x-series-api.lightspeedhq.com') ?? ''),
    'vend.queue.continuous.enabled' => (bool)(\Queue\Config::getBool('vend.queue.continuous.enabled', false)),
    'inventory.quick.allow_sync' => (bool)(\Queue\Config::getBool('inventory.quick.allow_sync', false)),
  ],
]);
