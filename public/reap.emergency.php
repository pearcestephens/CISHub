<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
use Queue\Http; use Queue\PdoConnection;
Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;
if (!Http::rateLimit('reap_emergency', 2)) return;
$in=json_decode(file_get_contents('php://input')?:'[]',true)?:[];
$older=isset($in['older_than_sec'])?max(60,(int)$in['older_than_sec']):1800;
$limit=isset($in['limit'])?max(10,min(5000,(int)$in['limit'])):2000;
try{
  $pdo=PdoConnection::instance();
  $cands=['ls_jobs','cishub_jobs','cisq_jobs','queue_jobs','jobs']; $table=null; $cols=[];
  foreach($cands as $t){ try{$st=$pdo->prepare("SHOW COLUMNS FROM `$t`");$st->execute();
    $c=$st->fetchAll(PDO::FETCH_COLUMN)?:[]; if($c && in_array('status',array_map('strtolower',$c),true)){ $table=$t; $cols=array_map('strtolower',$c); break; }
  }catch(Throwable $e){}}
  if(!$table){ Http::error('schema_error','no jobs table found'); return; }
  $has=function(string $col)use($cols){ return in_array(strtolower($col),$cols,true); };
  $pk=$has('id')?'id':($has('job_id')?'job_id':null); if($pk===null){ Http::error('schema_error',"$table has no id/job_id"); return; }
  $conds=["status IN('working','running')"];
  if($has('started_at'))   $conds[]="(started_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, started_at, NOW())>:s)";
  if($has('heartbeat_at')) $conds[]="(heartbeat_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, heartbeat_at, NOW())>:s)";
  if(!$has('started_at') && !$has('heartbeat_at') && $has('updated_at')) $conds[]="TIMESTAMPDIFF(SECOND, updated_at, NOW())>:s";
  $where=implode(' OR ',array_map(fn($c)=>"($c)",$conds));
  $sel=$pdo->prepare("SELECT `$pk` AS pk FROM `$table` WHERE $where ORDER BY `$pk` ASC LIMIT :lim");
  $sel->bindValue(':s',$older,PDO::PARAM_INT); $sel->bindValue(':lim',$limit,PDO::PARAM_INT); $sel->execute();
  $ids=$sel->fetchAll(PDO::FETCH_COLUMN)?:[]; $reset=0;
  if($ids){ $place=implode(',',array_fill(0,count($ids),'?'));
    $set="status='pending'"; if($has('heartbeat_at')) $set.=", heartbeat_at=NULL"; if($has('updated_at')) $set.=", updated_at=NOW()";
    $upd=$pdo->prepare("UPDATE `$table` SET $set WHERE `$pk` IN ($place)"); $upd->execute($ids); $reset=(int)$upd->rowCount();
  }
  Http::respond(true,['table'=>$table,'pk'=>$pk,'candidates'=>count($ids),'reset'=>$reset,'older_than_sec'=>$older,'limit'=>$limit]);
}catch(Throwable $e){ Http::error('reap_emergency_failed',$e->getMessage(),null,500); }
