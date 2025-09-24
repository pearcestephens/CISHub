<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('inventory_quick_qty_test', 5)) { return; }

$pid = isset($_GET['product_id']) ? (int)$_GET['product_id'] : (isset($_POST['product_id']) ? (int)$_POST['product_id'] : 1001);
$oid = isset($_GET['outlet_id']) ? (int)$_GET['outlet_id'] : (isset($_POST['outlet_id']) ? (int)$_POST['outlet_id'] : 1);
$qty = isset($_GET['new_qty']) ? (int)$_GET['new_qty'] : (isset($_POST['new_qty']) ? (int)$_POST['new_qty'] : 5);
$mode = isset($_GET['mode']) ? (string)$_GET['mode'] : (isset($_POST['mode']) ? (string)$_POST['mode'] : 'queue');

$body = [ 'product_id' => $pid, 'outlet_id' => $oid, 'new_qty' => $qty, 'mode' => $mode, 'idempotency_key' => 'invqtest:' . $pid . ':' . $oid . ':' . $qty ];

// Forward internally to the main endpoint (require_once ensures same process, so we'll just include the file).
// Safer: use include and set $_POST.
$_POST = $body; $_SERVER['REQUEST_METHOD'] = 'POST';
include __DIR__ . '/inventory.quick_qty.php';
