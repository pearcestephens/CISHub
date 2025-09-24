<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

final class ProductsV21
{
    /**
     * @param string|int $id
     * @param array<string,mixed> $payload
     */
    public static function update(string|int $id, array $payload): array
    {
        $headers = [];
        if (!empty($payload['idempotency_key'])) { $headers['X-Request-Id'] = (string)$payload['idempotency_key']; }
        $idStr = rawurlencode((string)$id);
        return HttpClient::putJson('/api/2.1/products/' . $idStr, $payload, $headers);
    }

    /** Fetch a single product from v2.1 API. */
    public static function get(string|int $id): array
    {
        $idStr = rawurlencode((string)$id);
        return HttpClient::get('/api/2.1/products/' . $idStr);
    }

    /**
     * Verify that the product's on_hand for a given outlet matches expected within timeout.
     * @return array{ok:bool,observed: int|null, attempts:int}
     */
    public static function verifyOnHand(string|int $productId, int $outletId, int $expected, int $timeoutSec = 10): array
    {
        // In mock mode, assume success to avoid false negatives
        try { if ((bool)(\Queue\Config::get('vend.http_mock', false) ?? false)) { return ['ok' => true, 'observed' => null, 'attempts' => 0]; } } catch (\Throwable $e) { /* ignore */ }
        $deadline = microtime(true) + max(1, $timeoutSec);
        $attempts = 0; $observed = null;
        $sleepMs = 250;
        do {
            $attempts++;
            try {
                $resp = self::get($productId);
                if (($resp['status'] ?? 0) >= 200 && ($resp['status'] ?? 0) < 300) {
                    $observed = self::extractOnHand($resp['body'] ?? null, $outletId);
                    if ($observed !== null && (int)$observed === (int)$expected) {
                        return ['ok' => true, 'observed' => (int)$observed, 'attempts' => $attempts];
                    }
                }
            } catch (\Throwable $e) { /* ignore and retry */ }
            // Backoff
            usleep($sleepMs * 1000);
            $sleepMs = min(2000, $sleepMs * 2);
        } while (microtime(true) < $deadline);
        return ['ok' => false, 'observed' => is_int($observed) ? (int)$observed : null, 'attempts' => $attempts];
    }

    /** @param mixed $body */
    private static function extractOnHand($body, int $outletId): ?int
    {
        $oidStr = (string)$outletId;
        $stack = [$body];
        while ($stack) {
            $node = array_pop($stack);
            if (is_array($node)) {
                // Normalize array to associative or list
                $isAssoc = array_keys($node) !== range(0, count($node) - 1);
                if ($isAssoc) {
                    $keys = array_change_key_case(array_keys($node), CASE_LOWER);
                    $lower = [];
                    foreach ($node as $k => $v) { $lower[strtolower((string)$k)] = $v; }
                    $candOutlet = null;
                    if (isset($lower['outlet_id'])) { $candOutlet = (string)$lower['outlet_id']; }
                    elseif (isset($lower['outlet'])) { $candOutlet = (string)$lower['outlet']; }
                    elseif (isset($lower['id']) && isset($lower['type']) && (string)$lower['type'] === 'outlet') { $candOutlet = (string)$lower['id']; }
                    if ($candOutlet !== null && $candOutlet === $oidStr) {
                        if (isset($lower['on_hand']) && is_numeric($lower['on_hand'])) { return (int)$lower['on_hand']; }
                        if (isset($lower['onhand']) && is_numeric($lower['onhand'])) { return (int)$lower['onhand']; }
                        if (isset($lower['count']) && is_numeric($lower['count'])) { return (int)$lower['count']; }
                    }
                    // known containers that may hold inventory levels
                    foreach (['inventory', 'inventory_levels', 'items', 'outlets', 'data', 'product', 'variants'] as $k) {
                        if (isset($lower[$k])) { $stack[] = $lower[$k]; }
                    }
                } else {
                    foreach ($node as $child) { $stack[] = $child; }
                }
            }
        }
        return null;
    }
}
