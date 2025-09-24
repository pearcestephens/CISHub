<?php
declare(strict_types=1);
/**
 * File: public/transfer.receive.test.php
 * Purpose: Minimal tester to enqueue a RECEIVED update for a given consignment/transfer; optional immediate run in mock mode
 * Author: Queue Service
 * Last Modified: 2025-09-21
 * Dependencies: PdoConnection, Config, Http, PdoWorkItemRepository, Runner
 */
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';

use Queue\Http;
use Queue\Config;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Lightspeed\Runner;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('transfer_receive_test', 10)) { return; }

try {
    $consignmentId = isset($_POST['consignment_id']) ? (int)$_POST['consignment_id'] : (isset($_GET['consignment_id']) ? (int)$_GET['consignment_id'] : 0);
    $transferPk = isset($_POST['transfer_pk']) ? (int)$_POST['transfer_pk'] : (isset($_GET['transfer_pk']) ? (int)$_GET['transfer_pk'] : null);
    $linesParam = $_POST['lines'] ?? ($_GET['lines'] ?? '');
    $runNow = isset($_POST['run']) ? (int)$_POST['run'] : (isset($_GET['run']) ? (int)$_GET['run'] : 0);

    $lines = [];
    if (is_string($linesParam) && $linesParam !== '') {
        $tmp = json_decode($linesParam, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $lines = $tmp; }
    }
    if (!$lines) {
        $lines = [['product_id'=>1001,'qty'=>1], ['product_id'=>1002,'qty'=>2]];
    }

    if ($consignmentId <= 0) { echo json_encode(['ok'=>false,'error'=>['code'=>'bad_request','message'=>'consignment_id required']]); return; }

    $payload = [ 'consignment_id' => $consignmentId, 'status' => 'RECEIVED', 'lines' => $lines ];
    if ($transferPk) { $payload['transfer_pk'] = $transferPk; }
    $idk = 'test-recv:' . $consignmentId . ':' . substr(sha1(json_encode($lines)), 0, 8);
    $jobId = Repo::addJob('update_consignment', $payload, $idk);

    $result = ['job_id' => $jobId, 'queued' => true];

    // Optional immediate run in mock mode only
    $mock = (bool)(Config::get('vend.http_mock', false) ?? false);
    if ($runNow === 1 && $mock) {
        Runner::run(['--limit' => 5, '--type' => 'update_consignment']);
        $result['ran'] = true;
    } elseif ($runNow === 1 && !$mock) {
        $result['ran'] = false;
        $result['note'] = 'runNow skipped in non-mock mode';
    }

    echo json_encode(['ok'=>true,'data'=>$result]);
} catch (\Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>['code'=>'receive_test_failed','message'=>$e->getMessage()]]);
}
