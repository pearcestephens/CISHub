<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

final class InventoryV20
{
    /**
     * DEPRECATED: Lightspeed X-Series API v2.0 does not support write adjustments for inventory.
     * Use Products v2.1 updateproduct instead (PUT /api/2.1/products/{id}) and pass the correct
     * payload per https://x-series-api.lightspeedhq.com/reference/updateproduct.
     *
     * @param array{product_id:int,outlet_id:int,count:int,reason?:string,note?:string,idempotency_key?:string} $payload
     * @throws \RuntimeException always
     */
    public static function adjust(array $payload): array
    {
        throw new \RuntimeException('InventoryV20.adjust is deprecated. Use ProductsV21::update (v2.1 updateproduct) to change inventory. See https://staff.vapeshed.co.nz/assets/services/queue/docs/VEND_ENDPOINTS.md');
    }
}
