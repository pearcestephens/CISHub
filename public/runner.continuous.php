<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/runner.continuous.php
 * Purpose: Toggle continuous runner mode on/off via config and optionally kick a worker.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-23
 * Dependencies: Config, Http, Lightspeed\Web
 * Link: https://staff.vapeshed.co.nz/assets/services/queue/public/runner.continuous.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Config;
use Queue\Http;
use Queue\Lightspeed\Web;

if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }

$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '') { $tmp = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; } }
if (!$in) { $in = $_POST ?: []; }

$on = isset($in['on']) ? (bool)$in['on'] : (isset($in['enable']) ? (bool)$in['enable'] : null);
if ($on === null) { Http::error('bad_request', 'missing on=true|false'); return; }

try {
    Config::set('vend.queue.continuous.enabled', $on);
    // Optionally kick a runner immediately when enabling
    if ($on) { Web::kick(null); }
    Http::respond(true, [
        'vend.queue.continuous.enabled' => Config::getBool('vend.queue.continuous.enabled', false),
        'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/runner.continuous.php'
    ]);
} catch (\Throwable $e) {
    Http::error('toggle_failed', $e->getMessage());
}
