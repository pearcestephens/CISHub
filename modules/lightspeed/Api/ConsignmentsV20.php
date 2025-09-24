<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Api;

use Modules\Lightspeed\Core\HttpClient;

/**
 * Consignments API v2.0 client
 * @link https://staff.vapeshed.co.nz
 */
final class ConsignmentsV20
{
    /** @param array<string,mixed> $payload */
    public static function create(array $payload): array
    {
        return HttpClient::request('POST', '/api/2.0/consignments', $payload);
    }

    /** @param int $id @param array<string,mixed> $payload */
    public static function addProducts(int $id, array $payload): array
    {
        return HttpClient::request('POST', '/api/2.0/consignments/' . $id . '/products', $payload);
    }

    /** @param int $id @param array<string,mixed> $payload */
    public static function update(int $id, array $payload): array
    {
        return HttpClient::request('PUT', '/api/2.0/consignments/' . $id, $payload);
    }
}
