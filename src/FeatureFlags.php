<?php
declare(strict_types=1);

namespace Queue;

/**
 * Centralized feature flags and kill-switches for the queue services.
 * Flags are backed by the CIS `configuration` table via Queue\Config.
 * All flags default to safe-off for write paths and safe-on for read/metrics.
 */
final class FeatureFlags
{
    public static function killAll(): bool { return Config::getBool('queue.kill_all', false); }

    // Webhook intake
    public static function webhookEnabled(): bool { return Config::getBool('webhook.enabled', true); }

    // Runner processing
    public static function runnerEnabled(): bool { return Config::getBool('queue.runner.enabled', true); }

    // Fanout of webhook events into child jobs
    public static function fanoutEnabled(): bool { return Config::getBool('webhook.fanout.enabled', true); }

    // Outbound HTTP to Lightspeed
    public static function httpEnabled(): bool { return Config::getBool('vend.http.enabled', true); }

    // Inventory command writes to vendor
    public static function inventoryCommandEnabled(): bool { return Config::getBool('vend.inventory.enable_command', false); }

    // Inventory pipeline global controls (shared convention with inventory module)
    public static function inventoryKillAll(): bool { return Config::getBool('inventory.kill_all', false); }
    public static function inventoryPipelineEnabled(): bool { return Config::getBool('inventory.pipeline.enabled', true); }

    /** True if either global kill or flag is explicitly disabled. */
    public static function isDisabled(bool $componentEnabled): bool
    {
        return self::killAll() || !$componentEnabled;
    }

    /** Return a snapshot of important flags for quick diagnostics. */
    public static function snapshot(): array
    {
        return [
            'kill_all' => self::killAll(),
            'webhook.enabled' => self::webhookEnabled(),
            'queue.runner.enabled' => self::runnerEnabled(),
            'webhook.fanout.enabled' => self::fanoutEnabled(),
            'vend.http.enabled' => self::httpEnabled(),
            'vend.inventory.enable_command' => self::inventoryCommandEnabled(),
            'inventory.kill_all' => self::inventoryKillAll(),
            'inventory.pipeline.enabled' => self::inventoryPipelineEnabled(),
        ];
    }
}
