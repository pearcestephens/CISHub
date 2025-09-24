<?php
declare(strict_types=1);
namespace Queue\Lightspeed;

final class InventoryV20
{
    /**
     * POST /api/2.0/inventory
     * Body: { "product_id": <id>, "outlet_id": <id>, "count": <int>, "reason": "stock_take|received|damaged|transfer|correction|other", "note": "..." }
     */
    public static function adjust(array $payload): array
    {
        // Minimal shape validation
        if (!isset($payload['product_id'], $payload['outlet_id'], $payload['count'])) {
            throw new \InvalidArgumentException('InventoryV20.adjust requires product_id,outlet_id,count');
        }
        // Reason/note are optional; set a sensible default
        $body = [
            'product_id' => (string)$payload['product_id'],
            'outlet_id'  => (string)$payload['outlet_id'],
            'count'      => (int)$payload['count'],
            'reason'     => $payload['reason'] ?? 'correction',
            'note'       => $payload['note']   ?? 'inventory.command',
        ];

        return HttpClient::postJson('/api/2.0/inventory', $body, [
            // Idempotency is supported via request-id header if you want to add it:
            // 'Idempotency-Key' => $payload['idempotency_key'] ?? null,
        ]);
    }
}
