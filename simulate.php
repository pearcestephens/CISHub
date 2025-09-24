<?php
declare(strict_types=1);

/**
 * assets/services/queue/simulate.php
 *
 * Generates a synthetic trace sequence for E2E sanity.
 * Writes JSONL logs to /logs and returns a link to the trace viewer.
 */

header('Content-Type: application/json');

try {
    $serviceRoot = __DIR__;
    $logDir = $serviceRoot . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

    $trace = bin2hex(random_bytes(8));
    $now   = gmdate('c');
    $source= (string)($_GET['source'] ?? 'simulation');

    $seq = [
      ['ts'=>$now,                        'stage'=>'UI/HTTP Intake','level'=>'info',   'msg'=>'Form submitted','source'=>$source,'request_id'=>bin2hex(random_bytes(6))],
      ['ts'=>gmdate('c', time()+1),       'stage'=>'Scheduler',      'level'=>'info',   'msg'=>'Job scheduled','job_id'=>bin2hex(random_bytes(6))],
      ['ts'=>gmdate('c', time()+2),       'stage'=>'Worker:Run Jobs','level'=>'info',   'msg'=>'Job started','attempt'=>1],
      ['ts'=>gmdate('c', time()+3),       'stage'=>'Vend API',       'level'=>'info',   'msg'=>'POST /api/resource','status'=>200],
      ['ts'=>gmdate('c', time()+4),       'stage'=>'Worker:Run Jobs','level'=>'success','msg'=>'Job completed'],
    ];

    $jsonl = '';
    foreach ($seq as $e) {
        $jsonl .= json_encode(['trace'=>$trace] + $e, JSON_UNESCAPED_SLASHES) . "\n";
    }

    @file_put_contents($logDir . '/trace.jsonl', $jsonl, FILE_APPEND | LOCK_EX);
    @file_put_contents($logDir . '/request.log', "[".gmdate('c')."] trace={$trace} source={$source}\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($logDir . '/run-jobs.log', "[".gmdate('c')."] trace={$trace} worker started\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($logDir . '/vend-api.log', "[".gmdate('c')."] trace={$trace} POST /api/resource status=200\n", FILE_APPEND | LOCK_EX);

    echo json_encode(['ok'=>true,'trace'=>$trace,'entries'=>count($seq),'trace_url'=>"/assets/services/queue/pipeline.trace.php?trace={$trace}"]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
