#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';

use Queue\PdoConnection;

function split_sql(string $sql): array {
    $len = strlen($sql); $out = []; $buf = ''; $inS=false; $inD=false; $inLC=false; $inBC=false; $line=1; $start=1;
    for($i=0;$i<$len;$i++){
        $ch = $sql[$i]; $nx = $i+1<$len ? $sql[$i+1] : '';
        if ($ch === "\n") $line++;
        if (!$inS && !$inD) {
            if (!$inBC && !$inLC && $ch==='-' && $nx==='-') $inLC = true;
            if (!$inBC && !$inLC && $ch==='/' && $nx==='*') $inBC = true;
            if ($inLC && $ch === "\n") $inLC = false;
            if ($inBC && $ch==='*' && $nx=== '/') { $inBC=false; $i++; continue; }
        }
        if ($inLC || $inBC) { $buf.=$ch; continue; }
        if ($ch==="'" && !$inD){ $inS=!$inS; $buf.=$ch; continue; }
        if ($ch==='"' && !$inS){ $inD=!$inD; $buf.=$ch; continue; }
        if ($ch===';' && !$inS && !$inD){ $t=trim($buf); if($t!=='') $out[]=['sql'=>$t,'start'=>$start,'end'=>$line]; $buf=''; $start=$line; continue; }
        $buf.=$ch;
    }
    $t=trim($buf); if($t!=='') $out[]=['sql'=>$t,'start'=>$start,'end'=>$line];
    return $out;
}

function usage() {
    fwrite(STDERR, "Usage: php bin/migrate-one.php /absolute/path/file.sql [--dry]\n");
    exit(1);
}

$path = $argv[1] ?? '';
$dry  = in_array('--dry', $argv, true);

if ($path === '' || !is_file($path)) usage();

$sql   = file_get_contents($path);
$stmts = split_sql($sql);
if (!$stmts) { fwrite(STDERR, "No statements found.\n"); exit(1); }

$pdo = PdoConnection::instance();
echo "[migrate-one] file: $path | stmts: ".count($stmts)." | dry: ".($dry?'yes':'no')."\n";

if ($dry) exit(0);

$pdo->beginTransaction();
try {
    foreach ($stmts as $i => $st) {
        $pdo->exec($st['sql']);
    }
    $pdo->commit();
    echo "[migrate-one] OK\n";
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "[migrate-one] FAILED: ".$e->getMessage()."\n");
    exit(2);
}
