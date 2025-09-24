<?php
declare(strict_types=1);

/**
 * File: assets/services/queue/modules/lightspeed/Ui/common.php
 * Purpose: Shared helpers and wiring for Lightspeed Queue UI pages (no session/app.php)
 * Links: https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/overview.php
 */

use Queue\Config;

// Do NOT start sessions or include app.php to avoid cross-system redirects

require_once __DIR__ . '/../../../src/Config.php';

function h(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mask_tail(?string $v, int $tail = 4): string {
    if ($v === null || $v === '') return '';
    $len = strlen($v); if ($len <= $tail) return str_repeat('•', $len);
    return str_repeat('•', max(0, $len-$tail)) . substr($v, -$tail);
}
function probe_url(string $url, int $timeoutSec = 3): array {
    $start = microtime(true); $code = 0; $ok = false; $err = '';
    try {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeoutSec, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        @file_get_contents($url, false, $ctx);
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $line, $m)) { $code = (int)$m[1]; break; }
        }
        $ok = $code >= 200 && $code < 400;
    } catch (\Throwable $e) { $err = $e->getMessage(); }
    $ms = (int)round((microtime(true)-$start)*1000);
    return ['ok'=>$ok,'code'=>$code,'ms'=>$ms,'err'=>$err];
}

// Config access safe wrapper
$__cfg_db_error = null;
function cfg(string $key, $default = null) {
  global $__cfg_db_error;
  try { return Config::get($key, $default); }
  catch (\Throwable $e) { if ($__cfg_db_error === null) { $__cfg_db_error = $e->getMessage(); } return $default; }
}

// Absolute base URLs
const UI_BASE   = 'https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui';
const SVC_BASE  = 'https://staff.vapeshed.co.nz/assets/services/queue';
