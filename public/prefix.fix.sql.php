<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoConnection;

Http::commonJsonHeaders();
if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }

try {
    $pdo = PdoConnection::instance();
    $cur = $pdo->query("SELECT config_label, config_value FROM configuration WHERE config_label='vend_domain_prefix'")->fetch(\PDO::FETCH_ASSOC);
    $before = $cur['config_value'] ?? null;
    $stmt = $pdo->prepare("UPDATE configuration SET config_value = 'vapeshed' WHERE config_label = 'vend_domain_prefix'");
    $stmt->execute();
    echo json_encode(['ok'=>true,'before'=>$before,'after'=>'vapeshed']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
