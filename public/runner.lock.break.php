<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
use Queue\PdoConnection; use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;

try {
  $pdo = PdoConnection::instance();
  $s = $pdo->prepare('SELECT IS_USED_LOCK(:k)'); $s->execute([':k'=>'ls_runner:all']);
  $owner = $s->fetchColumn();
  if ($owner === null || $owner === false) { Http::respond(true, ['note'=>'lock already free']); return; }
  $pdo->exec('KILL CONNECTION '.(int)$owner);
  Http::respond(true, ['killed_connection'=>(int)$owner]);
} catch (\Throwable $e) { Http::error('lock_break_failed',$e->getMessage(),null,500); }
