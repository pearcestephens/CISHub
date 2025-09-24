<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/runner.kick.php
 * Purpose: Manually kick a queue runner once (best-effort), with optional type filter.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-23
 * Link: https://staff.vapeshed.co.nz/assets/services/queue/public/runner.kick.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Lightspeed\Web;
use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('runner_kick', 12)) { return; }

$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '') { $tmp = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; } }
if (!$in) { $in = $_POST ?: []; }
$type = isset($in['type']) ? (string)$in['type'] : null;

try {
    Web::kick($type !== '' ? $type : null);
    Http::respond(true, [ 'kicked' => true, 'type' => $type ?: 'all' ]);
} catch (\Throwable $e) {
    Http::error('kick_failed', $e->getMessage());
}
