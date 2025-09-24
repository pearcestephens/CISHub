<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/simulate.php
 * Purpose: Generate a synthetic end-to-end trace spanning UI -> queue -> Vend API logs
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 */
header('Content-Type: application/json');
try {
    $serviceRoot = __DIR__;
    $logDir = $serviceRoot . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

    $trace = bin2hex(random_bytes(8));
    $now = gmdate('c');
    $source = (string)($_GET['source'] ?? 'simulation');

    $entries = [
        ['ts'=>$now,'trace'=>$trace,'stage'=>'UI/HTTP Intake','level'=>'info','msg'=>'Form submitted','source'=>$source,'request_id'=>bin2hex(random_bytes(6))],
        ['ts'=>gmdate('c', time()+1),'trace'=>$trace,'stage'=>'Scheduler','level'=>'info','msg'=>'Job scheduled','job_id'=>bin2hex(random_bytes(6))],
        ['ts'=>gmdate('c', time()+2),'trace'=>$trace,'stage'=>'Worker: Run Jobs','level'=>'info','msg'=>'Job started','attempt'=>1],
        ['ts'=>gmdate('c', time()+3),'trace'=>$trace,'stage'=>'Vend API','level'=>'info','msg'=>'POST /api/resource','status'=>200,'code'=>0],
        ['ts'=>gmdate('c', time()+4),'trace'=>$trace,'stage'=>'Worker: Run Jobs','level'=>'success','msg'=>'Job completed'],
    ];

    // Write JSONL traces
    $jsonl = '';
    foreach ($entries as $e) { $jsonl .= json_encode($e, JSON_UNESCAPED_SLASHES) . "\n"; }
    @file_put_contents($logDir . '/trace.jsonl', $jsonl, FILE_APPEND | LOCK_EX);

    // Also write to plain logs for visibility
    @file_put_contents($logDir . '/request.log', "[$now] trace={$trace} form submit source={$source}\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($logDir . '/run-jobs.log', "[".gmdate('c', time()+2)."] trace={$trace} worker started\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($logDir . '/vend-api.log', "[".gmdate('c', time()+3)."] trace={$trace} POST /api/resource status=200\n", FILE_APPEND | LOCK_EX);

    http_response_code(200);
    echo json_encode(['ok'=>true,'trace'=>$trace,'entries'=>count($entries),'trace_url'=>"/assets/services/queue/trace.php?trace={$trace}"]);    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
