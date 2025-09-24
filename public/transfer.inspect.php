<?php
declare(strict_types=1);
/**
 * transfer.inspect.php
 * Purpose: Provide a read-only, auth-protected inspection endpoint for a transfer
 * Returns: transfers row + related items/shipments/parcels/notes + audit + logs
 * Author: Queue Service
 * Last Modified: 2025-09-20
 * Dependencies: PdoConnection, Http
 * Docs: https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.inspect.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\PdoConnection;
use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('transfer_inspect', 10)) { return; }

try {
    $pdo = PdoConnection::instance();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $publicId = isset($_GET['public_id']) ? (string)$_GET['public_id'] : '';
    if ($id <= 0 && $publicId === '') {
        echo json_encode(['ok' => false, 'error' => ['code' => 'bad_request', 'message' => 'Provide id or public_id']]);
        return;
    }

    $exists = static function(string $t) use ($pdo): bool {
        try { $st = $pdo->prepare("SHOW TABLES LIKE :t"); $st->execute([':t' => $t]); return (bool)$st->fetchColumn(); } catch (\Throwable $e) { return false; }
    };

    $out = [
        'transfer' => null,
        'items' => [],
        'shipments' => [],
        'shipment_items' => [],
        'parcels' => [],
        'notes' => [],
        'audit' => [],
        'logs' => [],
    ];

    // transfers
    if ($exists('transfers')) {
        if ($id > 0) {
            $st = $pdo->prepare('SELECT * FROM transfers WHERE id = :id LIMIT 1');
            $st->execute([':id' => $id]);
        } else {
            $st = $pdo->prepare('SELECT * FROM transfers WHERE public_id = :pid LIMIT 1');
            $st->execute([':pid' => $publicId]);
        }
        $transfer = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        $out['transfer'] = $transfer;
        if (!$transfer) {
            echo json_encode(['ok' => false, 'error' => ['code' => 'not_found', 'message' => 'Transfer not found']]);
            return;
        }
        $id = (int)$transfer['id'];
    }

    // items
    if ($id > 0 && $exists('transfer_items')) {
        $st = $pdo->prepare('SELECT * FROM transfer_items WHERE transfer_id = :id ORDER BY id ASC');
        $st->execute([':id' => $id]);
        $out['items'] = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // shipments
    if ($id > 0 && $exists('transfer_shipments')) {
        $st = $pdo->prepare('SELECT * FROM transfer_shipments WHERE transfer_id = :id ORDER BY id ASC');
        $st->execute([':id' => $id]);
        $shipments = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $out['shipments'] = $shipments;
        // shipment items
        if ($shipments && $exists('transfer_shipment_items')) {
            $ids = array_map(static fn($r) => (int)$r['id'], $shipments);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $st2 = $pdo->prepare("SELECT * FROM transfer_shipment_items WHERE shipment_id IN ($place) ORDER BY id ASC");
            $st2->execute($ids);
            $out['shipment_items'] = $st2->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
        // parcels
        if ($shipments && $exists('transfer_parcels')) {
            $ids = array_map(static fn($r) => (int)$r['id'], $shipments);
            $place = implode(',', array_fill(0, count($ids), '?'));
            $st3 = $pdo->prepare("SELECT * FROM transfer_parcels WHERE shipment_id IN ($place) ORDER BY id ASC");
            $st3->execute($ids);
            $out['parcels'] = $st3->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        }
    }

    // notes
    if ($id > 0 && $exists('transfer_notes')) {
        $st = $pdo->prepare('SELECT * FROM transfer_notes WHERE transfer_id = :id ORDER BY id ASC');
        $st->execute([':id' => $id]);
        $out['notes'] = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // audit
    if ($exists('transfer_audit_log')) {
        if ($id > 0) {
            $st = $pdo->prepare('SELECT * FROM transfer_audit_log WHERE (transfer_pk = :id OR entity_pk = :id) ORDER BY id DESC LIMIT 200');
            $st->execute([':id' => $id]);
        } else {
            $st = $pdo->prepare('SELECT * FROM transfer_audit_log WHERE transfer_id = :pid ORDER BY id DESC LIMIT 200');
            $st->execute([':pid' => $publicId]);
        }
        $out['audit'] = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // logs
    if ($id > 0 && $exists('transfer_logs')) {
        $st = $pdo->prepare('SELECT * FROM transfer_logs WHERE transfer_id = :id ORDER BY id DESC LIMIT 200');
        $st->execute([':id' => $id]);
        $out['logs'] = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode(['ok' => true, 'data' => $out]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'inspect_failed', 'message' => $e->getMessage()]]);
}
