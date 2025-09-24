<?php
declare(strict_types=1);

namespace Queue;

/**
 * Degrade — central feature gating and auto-safeguards for partial outages.
 * Uses Config-backed flags:
 *  - ui.readonly (bool): puts affected UIs into read-only mode
 *  - ui.disable.quick_qty (bool): disables Quick Inventory change forms/APIs
 *  - ui.banner.active (bool), ui.banner.level (info|warning|danger), ui.banner.message (string)
 *  - auto.degrade.enabled (bool): allow auto-evaluator to flip toggles
 *  - auto.degrade.pending_threshold (int): queue pending threshold to trigger degrade
 *  - auto.degrade.cb_trip (bool): trip on vend circuit open
 *  - auto.degrade.reset_after_ok_min (int): minutes of healthy state before auto-clear
 */
final class Degrade
{
    public static function isReadOnly(): bool { return Config::getBool('ui.readonly', false); }
    public static function isFeatureDisabled(string $feature): bool { return Config::getBool('ui.disable.' . $feature, false); }
    public static function disableFeature(string $feature, bool $on): void { Config::set('ui.disable.' . $feature, $on); }
    public static function setReadOnly(bool $on): void { Config::set('ui.readonly', $on); }

    /** @return array{active:bool,level:string,message:string} */
    public static function banner(): array
    {
        return [
            'active' => Config::getBool('ui.banner.active', false),
            'level' => (string) (Config::get('ui.banner.level', 'info') ?? 'info'),
            'message' => (string) (Config::get('ui.banner.message', '') ?? ''),
        ];
    }

    public static function setBanner(bool $active, string $level, string $message): void
    {
        Config::set('ui.banner.active', $active);
        Config::set('ui.banner.level', $level);
        Config::set('ui.banner.message', $message);
        Config::set('ui.banner.updated_at', time());
    }

    /** Auto-evaluate health and toggle degrade flags when needed. */
    public static function autoEvaluate(): array
    {
        $enabled = Config::getBool('auto.degrade.enabled', true);
        $threshold = (int) (Config::get('auto.degrade.pending_threshold', 500) ?? 500);
        $tripOnCB = Config::getBool('auto.degrade.cb_trip', true);
        $resetAfter = (int) (Config::get('auto.degrade.reset_after_ok_min', 10) ?? 10);
        $now = time();
        $actions = [];
        try {
            $pdo = PdoConnection::instance();
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $failed = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='failed'")->fetchColumn();
            $oldest = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()),0) FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $cb = Config::get('vend.cb', ['tripped'=>false,'until'=>0]);
            $cbOpen = is_array($cb) && !empty($cb['tripped']) && $now < (int)($cb['until'] ?? 0);

            $unhealthy = ($pending >= $threshold) || ($tripOnCB && $cbOpen);
            $reason = $cbOpen ? 'vendor_api_circuit_open' : (($pending >= $threshold) ? 'queue_backlog' : 'none');

            // Remember recovery window
            if (!$unhealthy) {
                Config::set('auto.degrade.last_healthy', $now);
            }

            if ($enabled && $unhealthy) {
                // Disable high-risk write forms first
                self::disableFeature('quick_qty', true);
                self::setReadOnly(true);
                self::setBanner(true, 'danger', 'System is degraded (' . $reason . '). Some forms are temporarily disabled to protect data.');
                $actions[] = 'disabled.quick_qty';
                $actions[] = 'readonly.on';
            } else {
                // Auto-clear after sustained healthy window
                $lastOk = (int) (Config::get('auto.degrade.last_healthy', 0) ?? 0);
                if ($lastOk > 0 && ($now - $lastOk) >= ($resetAfter * 60)) {
                    if (self::isFeatureDisabled('quick_qty')) { self::disableFeature('quick_qty', false); $actions[] = 'enabled.quick_qty'; }
                    if (self::isReadOnly()) { self::setReadOnly(false); $actions[] = 'readonly.off'; }
                    $banner = self::banner();
                    if ($banner['active']) { self::setBanner(false, 'info', ''); $actions[] = 'banner.off'; }
                }
            }
            return [
                'ok' => true,
                'metrics' => ['pending' => $pending, 'failed' => $failed, 'oldest_pending_age_s' => $oldest, 'vend_cb_open' => $cbOpen],
                'thresholds' => ['pending' => $threshold, 'cb_trip' => $tripOnCB, 'reset_after_min' => $resetAfter],
                'actions' => $actions,
                'flags' => [
                    'ui.readonly' => self::isReadOnly(),
                    'ui.disable.quick_qty' => self::isFeatureDisabled('quick_qty'),
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
