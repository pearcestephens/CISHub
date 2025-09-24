<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

final class ConsignmentsV20
{
    /** @param array<string,mixed> $payload */
    public static function create(array $payload): array
    {
        $headers = [];
        if (!empty($payload['idempotency_key'])) {
            $headers['X-Request-Id'] = (string)$payload['idempotency_key'];
            $headers['Idempotency-Key'] = (string)$payload['idempotency_key'];
            unset($payload['idempotency_key']);
        }
        return HttpClient::postJson('/api/2.0/consignments', $payload, $headers);
    }

    /** @param array<string,mixed> $payload */
    public static function updateFull(int $id, array $payload): array
    {
        $headers = [];
        if (!empty($payload['idempotency_key'])) {
            $headers['X-Request-Id'] = (string)$payload['idempotency_key'];
            $headers['Idempotency-Key'] = (string)$payload['idempotency_key'];
            unset($payload['idempotency_key']);
        }
        return HttpClient::putJson('/api/2.0/consignments/' . $id, $payload, $headers);
    }

    /** @param array<string,mixed> $payload */
    public static function updatePartial(int $id, array $payload): array
    {
        $headers = [];
        if (!empty($payload['idempotency_key'])) {
            $headers['X-Request-Id'] = (string)$payload['idempotency_key'];
            $headers['Idempotency-Key'] = (string)$payload['idempotency_key'];
            unset($payload['idempotency_key']);
        }
        return HttpClient::patchJson('/api/2.0/consignments/' . $id, $payload, $headers);
    }

    /**
     * Add products to an existing consignment.
     * Endpoint: POST /api/2.0/consignments/{id}/products
     * Payload shape: { products: [ { product_id:int, quantity:int, sku?:string } ], idempotency_key?:string }
     *
     * @param int $id Consignment ID
     * @param array<string,mixed> $payload
     */
    public static function addProducts(int $id, array $payload): array
    {
        $headers = [];
        if (!empty($payload['idempotency_key'])) {
            $headers['X-Request-Id'] = (string)$payload['idempotency_key'];
            $headers['Idempotency-Key'] = (string)$payload['idempotency_key'];
            unset($payload['idempotency_key']);
        }
        return HttpClient::postJson('/api/2.0/consignments/' . $id . '/products', $payload, $headers);
    }
}
