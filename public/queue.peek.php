<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
use Queue\PdoConnection; use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) return;

$n = isset($_GET['n']) ? max(1,min(50,(int)$_GET['n'])) : 10;
$pdo = PdoConnection::instance(); $tbl=null;$cols=[];
foreach(['ls_jobs','cishub_jobs','cisq_jobs','queue_jobs','jobs'] as $t){
  try{$st=$pdo->prepare("SHOW COLUMNS FROM `$t`");$st->execute();
      $c=$st->fetchAll(PDO::FETCH_COLUMN)?:[];
      if($c && in_array('status',array_map('strtolower',$c),true)){ $tbl=$t;$cols=array_map('strtolower',$c); break;}
  }catch(\Throwable $e){}
}
if(!$tbl){ Http::respond(true,['rows'=>[],'note'=>'no jobs table']); return; }

$pk = in_array('id',$cols,true)?'id':(in_array('job_id',$cols,true)?'job_id':null);
$started = in_array('started_at',$cols,true)?'started_at':null;
$sql = "SELECT $pk AS pk, type, status, ".($started?$started:'NULL')." AS started_at,
               LEFT(payload, 300) AS payload_snip
        FROM `$tbl` WHERE status IN('working','running')
        ORDER BY ".($started?$started.' DESC, ':'')." $pk DESC LIMIT :n";
$st=$pdo->prepare($sql); $st->bindValue(':n',$n,\PDO::PARAM_INT); $st->execute();
Http::respond(true,['rows'=>$st->fetchAll(\PDO::FETCH_ASSOC)?:[],'table'=>$tbl]);
