<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

final class CustomerUtil
{
    /**
     * Shape-safe unwrapping of a customer object from various Vend API responses (v2.0/v2.1).
     * Accepts array or object; handles {customer:{...}}, {data:{customer:{...}|customers:[...]}} or raw {...}.
     * Returns associative array on success, or null.
     *
     * @param mixed $response
     * @return array<string,mixed>|null
     */
    public static function unwrap($response): ?array
    {
        $obj = \is_array($response)
            ? $response
            : (\is_object($response) ? (array)$response : []);

        $cand = null;
        if (isset($obj['customer']) && \is_array($obj['customer'])) {
            $cand = $obj['customer'];
        } elseif (isset($obj['data']) && \is_array($obj['data'])) {
            $data = $obj['data'];
            if (isset($data['customer']) && \is_array($data['customer'])) {
                $cand = $data['customer'];
            } elseif (isset($data['customers']) && \is_array($data['customers']) && \count($data['customers']) > 0) {
                $cand = $data['customers'][0];
            } else {
                $cand = $data;
            }
        } elseif (!empty($obj)) {
            $cand = $obj;
        }

        return \is_array($cand) ? $cand : null;
    }
}

// Convenience namespaced function if used procedurally in this module
if (!\function_exists(__NAMESPACE__ . '\\vend_unwrap_customer')) {
    /** @param mixed $response */
    function vend_unwrap_customer($response): ?array
    {
        return CustomerUtil::unwrap($response);
    }
}
