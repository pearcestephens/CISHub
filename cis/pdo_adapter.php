<?php
declare(strict_types=1);

/**
 * File: assets/services/queue/cis/pdo_adapter.php
 * Purpose: Provide cis_pdo() and cis_pdo_ping() wrappers backed by Queue\PdoConnection
 * Author: Ecigdis Ltd (The Vape Shed)
 * Last Modified: 2025-09-20
 * Links: https://staff.vapeshed.co.nz/assets/services/queue
 */

use Queue\PdoConnection;
use PDO;

// Autoload PdoConnection relative to this file
$__base = dirname(__DIR__);
require_once $__base . '/src/PdoConnection.php';

if (!function_exists('cis_pdo')) {
    /**
     * Get shared PDO using Queue's robust resolver.
     */
    function cis_pdo(): PDO
    {
        return PdoConnection::instance();
    }
}

if (!function_exists('cis_pdo_ping')) {
    /**
     * Quick health check on PDO.
     */
    function cis_pdo_ping(): bool
    {
        try {
            $stmt = cis_pdo()->query('SELECT 1');
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('cis_pdo_ping failed: ' . $e->getMessage());
            return false;
        }
    }
}
