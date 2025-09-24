<?php
declare(strict_types=1);

namespace Queue;

final class DevFlags
{
    /**
     * Return a list of active development/test flags with reasons.
     * @return array<int,array{key:string,value:mixed,reason:string}>
     */
    public static function active(): array
    {
        $out = [];
        $add = static function(string $key, $value, string $reason) use (&$out) {
            $out[] = ['key' => $key, 'value' => $value, 'reason' => $reason];
        };
        try {
            if (Config::getBool('vend.http_mock', false)) {
                $add('vend.http_mock', true, 'Vendor HTTP is in mock mode; no real API calls will be sent.');
            }
        } catch (\Throwable $e) {}
        try {
            if (Config::getBool('dev.mode', false)) { $add('dev.mode', true, 'Development mode is enabled.'); }
        } catch (\Throwable $e) {}
        try {
            if (Config::getBool('queue.kill_all', false)) { $add('queue.kill_all', true, 'Global queue kill switch is active; workers will no-op.'); }
        } catch (\Throwable $e) {}
        try {
            if (!FeatureFlags::webhookEnabled()) { $add('webhook.enabled', false, 'Webhook intake is disabled.'); }
        } catch (\Throwable $e) {}
        try {
            if (FeatureFlags::inventoryKillAll()) { $add('inventory.kill_all', true, 'Inventory write path is disabled.'); }
        } catch (\Throwable $e) {}
        try {
            $base = (string)(Config::get('vend.api_base', '') ?? '');
            if ($base !== '' && stripos($base, 'x-series-api.lightspeedhq.com') === false) {
                $add('vend.api_base', $base, 'Non-standard vendor API base is configured.');
            }
        } catch (\Throwable $e) {}
        return $out;
    }
}
