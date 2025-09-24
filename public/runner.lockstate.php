<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
use Queue\PdoConnection; use Queue\Http;

Http::commonJsonHeaders();
try {
  $pdo = PdoConnection::instance();
  $s1=$pdo->prepare('SELECT IS_FREE_LOCK(:k)'); $s1->execute([':k'=>'ls_runner:all']);
  $free = ((int)($s1->fetchColumn() ?: 0) === 1);
  $s2=$pdo->prepare('SELECT IS_USED_LOCK(:k)'); $s2->execute([':k'=>'ls_runner:all']);
  $owner = $s2->fetchColumn();
  Http::respond(true, ['free'=>$free,'owner_connection_id'=>$owner]);
} catch (\Throwable $e) { Http::error('lock_state_failed',$e->getMessage(),null,500); }
