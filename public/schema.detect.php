<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoConnection;

Http::commonJsonHeaders();

try {
  $pdo = PdoConnection::instance();

  // Find the active jobs table by looking for a table that has a "status" column and a payload column
  $candidates = ['ls_jobs','cishub_jobs','cisq_jobs','jobs','queue_jobs'];
  $found = null;

  foreach ($candidates as $t) {
    try {
      $s = $pdo->prepare("SHOW COLUMNS FROM `$t`");
      $s->execute();
      $cols = $s->fetchAll(\PDO::FETCH_COLUMN) ?: [];
      $lc = array_map('strtolower', $cols);
      if ($cols && in_array('status',$lc,true) && (in_array('payload',$lc,true) || in_array('job_data',$lc,true))) {
        $found = ['table'=>$t,'cols'=>$cols];
        break;
      }
    } catch (\Throwable $e) {}
  }

  // Fallback: scan information_schema for *jobs tables
  if (!$found) {
    $db = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    $s = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME LIKE :p ORDER BY TABLE_NAME");
    foreach (['%_jobs','jobs','%jobs%'] as $pat) {
      $s->execute([':db'=>$db, ':p'=>$pat]);
      $names = $s->fetchAll(\PDO::FETCH_COLUMN) ?: [];
      foreach ($names as $t) {
        try {
          $x = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
          $lc = array_map('strtolower',$x);
          if ($x && in_array('status',$lc,true)) {
            $found = ['table'=>$t,'cols'=>$x];
            break 2;
          }
        } catch (\Throwable $e) {}
      }
    }
  }

  if (!$found) {
    Http::error('schema_not_found','No jobs table with a status column detected'); exit;
  }

  $lc = array_map('strtolower',$found['cols']);
  $pk = in_array('id',$lc,true) ? 'id' : (in_array('job_id',$lc,true) ? 'job_id' : null);
  $has = fn(string $c) => in_array(strtolower($c), $lc, true);

  Http::respond(true, [
    'jobs_table'   => $found['table'],
    'pk'           => $pk,
    'columns'      => $found['cols'],
    'has_started'  => $has('started_at'),
    'has_heartbeat'=> $has('heartbeat_at'),
    'has_updated'  => $has('updated_at'),
    'has_leased'   => $has('leased_until'),
  ]);
} catch (\Throwable $e) {
  Http::error('schema_detect_failed', $e->getMessage(), null, 500);
}
