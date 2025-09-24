<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Degrade.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Degrade;
use Queue\Http;

if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }

$raw = file_get_contents('php://input') ?: '';
$in = json_decode($raw, true) ?: [];
$action = (string)($in['action'] ?? '');

switch ($action) {
  case 'set_readonly':
    Degrade::setReadOnly((bool)($in['on'] ?? false));
    Http::respond(true, ['ui.readonly' => Degrade::isReadOnly()]);
    break;
  case 'disable_feature':
    $feature = (string)($in['feature'] ?? '');
    $on = (bool)($in['on'] ?? false);
    if ($feature === '') { Http::error('invalid', 'feature required'); return; }
    Degrade::disableFeature($feature, $on);
    Http::respond(true, ['feature' => $feature, 'disabled' => Degrade::isFeatureDisabled($feature)]);
    break;
  case 'set_banner':
    $active = (bool)($in['active'] ?? false);
    $level  = (string)($in['level'] ?? 'info');
    $msg    = (string)($in['message'] ?? '');
    Degrade::setBanner($active, $level, $msg);
    Http::respond(true, ['banner' => Degrade::banner()]);
    break;
  case 'auto_eval':
    $r = Degrade::autoEvaluate();
    Http::respond(true, $r);
    break;
  default:
    Http::error('invalid_action', 'Unknown action');
}
