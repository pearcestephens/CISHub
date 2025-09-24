<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/ConfigFacade.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\ConfigFacade as Config;
use Queue\PdoConnection;

Http::commonTextHeaders();
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = PdoConnection::instance();

    // Detect jobs table
    $candidates = ['ls_jobs','cishub_jobs','cisq_jobs','queue_jobs','jobs'];
    $jobsTable  = null;
    $colsLower  = [];

    foreach ($candidates as $t) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$t`");
            $st->execute();
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($cols) {
                $lc = array_map('strtolower', $cols);
                if (in_array('status', $lc, true)) { $jobsTable = $t; $colsLower = $lc; break; }
            }
        } catch (\Throwable $e) {}
    }

    if (!$jobsTable) { echo "ls_metrics_error 1\n"; exit; }

    $has = static function (string $c) use ($colsLower): bool {
        return in_array(strtolower($c), $colsLower, true);
    };

    // Guarded queries
    $pending = 0;
    try { $pending = (int)($pdo->query("SELECT COUNT(*) FROM `$jobsTable` WHERE status='pending'")->fetchColumn() ?: 0); } catch (\Throwable $e) {}

    $working = 0;
    try { $working = (int)($pdo->query("SELECT COUNT(*) FROM `$jobsTable` WHERE status IN('working','running')")->fetchColumn() ?: 0); } catch (\Throwable $e) {}

    $failed  = 0;
    try { $failed  = (int)($pdo->query("SELECT COUNT(*) FROM `$jobsTable` WHERE status='failed'")->fetchColumn() ?: 0); } catch (\Throwable $e) {}

    $dlq = 0;
    try { $dlq = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs_dlq")->fetchColumn() ?: 0); } catch (\Throwable $e) {}

    $oldestPendingAge = 0;
    if ($has('created_at')) {
        try {
            $oldestPendingAge = (int)($pdo->query(
                "SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()),0)
                 FROM `$jobsTable` WHERE status='pending'"
            )->fetchColumn() ?: 0);
        } catch (\Throwable $e) {}
    }

    $longestWorkingAge = 0;
    if ($has('started_at')) {
        try {
            $longestWorkingAge = (int)($pdo->query(
                "SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(started_at), NOW()),0)
                 FROM `$jobsTable` WHERE status IN('working','running')"
            )->fetchColumn() ?: 0);
        } catch (\Throwable $e) {}
    }

    // Emit (names unchanged)
    echo "ls_jobs_pending_total $pending\n";
    echo "ls_jobs_working_total $working\n";
    echo "ls_jobs_failed_total $failed\n";
    echo "ls_jobs_dlq_total $dlq\n";
    echo "ls_oldest_pending_age_seconds $oldestPendingAge\n";
    echo "ls_longest_working_age_seconds $longestWorkingAge\n";

    // Vend CB metrics via namespace-first config
    $cb = Config::get('vend.cb', ['tripped'=>false,'until'=>0]);
    $cb = is_array($cb) ? $cb : ['tripped'=>false,'until'=>0];
    $tripped = !empty($cb['tripped']) ? 1 : 0;
    $until   = (int)($cb['until'] ?? 0);

    echo "vend_circuit_breaker_open $tripped\n";
    echo "vend_circuit_breaker_until_epoch $until\n";
} catch (\Throwable $e) {
    echo "ls_metrics_error 1\n";
}
