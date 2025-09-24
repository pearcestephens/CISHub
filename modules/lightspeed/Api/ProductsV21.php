<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Api;

use Modules\Lightspeed\Core\HttpClient;

/**
 * Products API v2.1 client
 * @link https://staff.vapeshed.co.nz
 */
final class ProductsV21
{
    /**
     * Update product by ID with minimal payload.
     * @param int $id
     * @param array<string,mixed> $payload
     */
    public static function update(int $id, array $payload): array
    {
        return HttpClient::request('PUT', '/api/2.1/products/' . $id, $payload);
    }
}
