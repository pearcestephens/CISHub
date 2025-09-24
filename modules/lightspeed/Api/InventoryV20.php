<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Api;

use Modules\Lightspeed\Core\HttpClient;

/**
 * Inventory API v2.0 client
 * @link https://staff.vapeshed.co.nz
 */
final class InventoryV20
{
    /**
     * Adjust inventory count for product/outlet.
     * @param array{product_id:int,outlet_id:int,count:int,reason?:string,note?:string} $payload
     */
    public static function adjust(array $payload): array
    {
        return HttpClient::request('POST', '/api/2.0/inventory', $payload);
    }
}
