<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\PdoConnection;
use PDO;

final class UpsertRepository
{
    public static function upsertProduct(array $p): void
    {
        $pdo = PdoConnection::instance();
        $pdo->prepare('CREATE TABLE IF NOT EXISTS ls_products (
            product_id BIGINT PRIMARY KEY,
            name VARCHAR(255) NULL,
            sku VARCHAR(128) NULL,
            price DECIMAL(16,4) NULL,
            brand VARCHAR(128) NULL,
            supplier VARCHAR(128) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NULL,
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $pdo->prepare('INSERT INTO ls_products (product_id,name,sku,price,brand,supplier,is_active,updated_at)
            VALUES (:id,:name,:sku,:price,:brand,:supplier,:active,:updated)
            ON DUPLICATE KEY UPDATE name=VALUES(name), sku=VALUES(sku), price=VALUES(price), brand=VALUES(brand), supplier=VALUES(supplier), is_active=VALUES(is_active), updated_at=VALUES(updated_at)')
            ->execute([
                ':id' => (int)($p['id'] ?? $p['product_id'] ?? 0),
                ':name' => (string)($p['name'] ?? ''),
                ':sku' => (string)($p['sku'] ?? ($p['handle'] ?? '')),
                ':price' => isset($p['price']) ? (string)$p['price'] : null,
                ':brand' => (string)($p['brand'] ?? ''),
                ':supplier' => (string)($p['supplier'] ?? ''),
                ':active' => !empty($p['active']) ? 1 : 0,
                ':updated' => (string)($p['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
    }

    public static function upsertInventory(array $i): void
    {
        $pdo = PdoConnection::instance();
        $pdo->prepare('CREATE TABLE IF NOT EXISTS ls_inventory (
            product_id BIGINT NOT NULL,
            outlet_id BIGINT NOT NULL,
            quantity INT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (product_id, outlet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $pdo->prepare('INSERT INTO ls_inventory (product_id,outlet_id,quantity,updated_at)
            VALUES (:pid,:oid,:qty,:updated)
            ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), updated_at=VALUES(updated_at)')
            ->execute([
                ':pid' => (int)($i['product_id'] ?? 0),
                ':oid' => (int)($i['outlet_id'] ?? 0),
                ':qty' => isset($i['quantity']) ? (int)$i['quantity'] : (int)($i['current_amount'] ?? 0),
                ':updated' => (string)($i['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
    }

    public static function upsertConsignment(array $c): void
    {
        $pdo = PdoConnection::instance();
        $pdo->prepare('CREATE TABLE IF NOT EXISTS ls_consignments (
            consignment_id BIGINT PRIMARY KEY,
            status VARCHAR(32) NULL,
            outlet_from BIGINT NULL,
            outlet_to BIGINT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $pdo->prepare('INSERT INTO ls_consignments (consignment_id,status,outlet_from,outlet_to,created_at,updated_at)
            VALUES (:id,:status,:from,:to,:created,:updated)
            ON DUPLICATE KEY UPDATE status=VALUES(status), outlet_from=VALUES(outlet_from), outlet_to=VALUES(outlet_to), updated_at=VALUES(updated_at)')
            ->execute([
                ':id' => (int)($c['id'] ?? $c['consignment_id'] ?? 0),
                ':status' => (string)($c['status'] ?? ''),
                ':from' => (int)($c['outlet_from'] ?? 0),
                ':to' => (int)($c['outlet_to'] ?? 0),
                ':created' => (string)($c['created_at'] ?? date('Y-m-d H:i:s')),
                ':updated' => (string)($c['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
    }

    public static function upsertConsignmentLine(array $l): void
    {
        $pdo = PdoConnection::instance();
        $pdo->prepare('CREATE TABLE IF NOT EXISTS ls_consignment_products (
            consignment_id BIGINT NOT NULL,
            product_id BIGINT NOT NULL,
            qty INT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (consignment_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        $pdo->prepare('INSERT INTO ls_consignment_products (consignment_id,product_id,qty,updated_at)
            VALUES (:cid,:pid,:qty,:updated)
            ON DUPLICATE KEY UPDATE qty=VALUES(qty), updated_at=VALUES(updated_at)')
            ->execute([
                ':cid' => (int)($l['consignment_id'] ?? 0),
                ':pid' => (int)($l['product_id'] ?? 0),
                ':qty' => (int)($l['qty'] ?? ($l['quantity'] ?? 0)),
                ':updated' => (string)($l['updated_at'] ?? date('Y-m-d H:i:s')),
            ]);
    }

    public static function setCursor(string $entity, string $cursor): void
    {
        $pdo = PdoConnection::instance();
        $pdo->prepare('INSERT INTO ls_sync_cursors (entity, cursor, updated_at) VALUES (:e,:c,NOW())
            ON DUPLICATE KEY UPDATE cursor=VALUES(cursor), updated_at=VALUES(updated_at)')->execute([':e' => $entity, ':c' => $cursor]);
    }

    public static function getCursor(string $entity): ?string
    {
        $pdo = PdoConnection::instance();
        $s = $pdo->prepare('SELECT cursor FROM ls_sync_cursors WHERE entity=:e');
        $s->execute([':e' => $entity]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? (string)$r['cursor'] : null;
    }
}
