<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Degrade.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Lightspeed\Web;
use Queue\Http;

// Allow GET for simple cron probes; enforce auth if configured; rate-limit to avoid thrash.
Http::commonJsonHeaders();
if (!Http::rateLimit('queue_watchdog', 6)) { return; }
if (!Http::ensureAuth()) { return; }

[$ok, $data] = Web::watchdogRun();
Http::respond($ok, $ok ? $data : null, $ok ? null : ['code' => 'watchdog_failed', 'message' => $data['error'] ?? 'unknown']);
