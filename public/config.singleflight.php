<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http; use Queue\Config;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;   // POST only
if (!Http::ensureAuth()) return;   // admin bearer required

// Accept JSON or application/x-www-form-urlencoded
$raw = file_get_contents('php://input') ?: '';
$in  = [];
if ($raw !== '') {
  $tmp = json_decode($raw, true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $in = $tmp;
}
if (!$in && isset($_POST) && is_array($_POST) && array_key_exists('enabled', $_POST)) {
  $v = strtolower((string)$_POST['enabled']);
  $in['enabled'] = in_array($v, ['1','true','yes','on'], true);
}
if (!array_key_exists('enabled', $in)) {
  Http::error('bad_request','expected {"enabled": true|false}');
  return;
}

// TRUE  => DISABLE single-flight (skip advisory lock)
// FALSE => ENABLE  single-flight (take advisory lock)
Config::set('vend_queue_disable_singleflight', $in['enabled'] ? 'true' : 'false');

Http::respond(true, [
  'vend_queue_disable_singleflight' => Config::get('vend_queue_disable_singleflight'),
]);
