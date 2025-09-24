#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * File: bin/webhook-emergency-on.php
 * Purpose: Immediate switch to real-time webhook processing (bypass queue) to restore service
 * Author: GitHub Copilot
 * Last Modified: 2025-09-23
 * Dependencies: src/PdoConnection.php, src/Config.php
 */
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';

use Queue\Config;

// Flip to emergency real-time mode: process inline, do not enqueue new jobs
Config::set('LS_WEBHOOKS_ENABLED', true);
Config::set('webhook.enabled', true);
Config::set('vend.webhook.realtime', true);
Config::set('vend.webhook.enqueue', false);
// Keep runner auto-kick on for any other types
Config::set('vend.queue.auto_kick.enabled', true);

$snapshot = [
  'LS_WEBHOOKS_ENABLED' => Config::getBool('LS_WEBHOOKS_ENABLED', false),
  'webhook.enabled' => Config::getBool('webhook.enabled', false),
  'vend.webhook.realtime' => Config::getBool('vend.webhook.realtime', false),
  'vend.webhook.enqueue' => Config::getBool('vend.webhook.enqueue', true),
  'vend.queue.auto_kick.enabled' => Config::getBool('vend.queue.auto_kick.enabled', true),
];
echo json_encode(['ok'=>true,'mode'=>'realtime_only','flags'=>$snapshot], JSON_PRETTY_PRINT), "\n";
