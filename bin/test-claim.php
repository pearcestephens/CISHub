#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';

$batch = \Queue\PdoWorkItemRepository::claimBatch(10, $argv[1] ?? null);
$f = function($j){ return ['id'=>$j->id,'type'=>$j->type]; };
$rows = array_map($f, $batch);
echo json_encode(['count'=>count($rows),'rows'=>$rows], JSON_PRETTY_PRINT), "\n";
