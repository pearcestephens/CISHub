<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Sync;

use Modules\Lightspeed\Core\Logger;
use Modules\Lightspeed\Core\WorkItems;
use Modules\Lightspeed\Core\Config;
use Modules\Lightspeed\Core\DB;
use Modules\Lightspeed\Api\InventoryV20;
use Modules\Lightspeed\Api\ProductsV21;
use Modules\Lightspeed\Api\ConsignmentsV20;

require_once __DIR__ . '/../Core/bootstrap.php';

/**
 * Runner CLI for Lightspeed work items
 * @link https://staff.vapeshed.co.nz
 */
final class Runner
{
    public static function main(array $argv): int
    {
        $args = self::parseArgs($argv);
        $limit = (int) ($args['--limit'] ?? 100);
        $type = $args['--type'] ?? null;

        Logger::info('Runner starting', ['meta' => ['limit' => $limit, 'type' => $type]]);

        $processed = 0;
        while ($processed < $limit) {
            $batch = WorkItems::claim(min(50, $limit - $processed), $type);
            if ($batch === []) {
                usleep(200 * 1000); // small backoff
                break;
            }
            foreach ($batch as $job) {
                $processed++;
                $id = (int) $job['id'];
                $jType = (string) $job['type'];
                $payload = (array) $job['payload'];
                try {
                    self::process($jType, $payload, $id);
                    WorkItems::complete($id);
                } catch (\Throwable $e) {
                    Logger::error('Job failed', ['job_id' => $id, 'meta' => ['err' => $e->getMessage()]]);
                    WorkItems::fail($id, $e->getMessage());
                }
            }
        }

        Logger::info('Runner finished', ['meta' => ['processed' => $processed]]);
        echo json_encode(['ok' => true, 'processed' => $processed]) . "\n";
        return 0;
    }

    /**
     * Route jobs to API handlers.
     * @param string $type
     * @param array<string,mixed> $payload
     */
    private static function process(string $type, array $payload, int $jobId): void
    {
        Logger::info('Processing job', ['job_id' => $jobId, 'meta' => ['type' => $type]]);
        switch ($type) {
            case 'push_inventory_adjustment':
                $resp = InventoryV20::adjust([
                    'product_id' => (int) $payload['product_id'],
                    'outlet_id' => (int) $payload['outlet_id'],
                    'count' => (int) $payload['count'],
                    'reason' => $payload['reason'] ?? null,
                    'note' => $payload['note'] ?? null,
                ]);
                // Optionally update mirrors here
                Logger::info('Inventory adjust done', ['job_id' => $jobId, 'meta' => ['status' => $resp['status'] ?? null]]);
                break;

            case 'push_product_update':
                $resp = ProductsV21::update((int) $payload['product_id'], (array) $payload['data']);
                Logger::info('Product update done', ['job_id' => $jobId, 'meta' => ['status' => $resp['status'] ?? null]]);
                break;

            case 'create_consignment':
                $resp = ConsignmentsV20::create((array) $payload);
                Logger::info('Consignment create done', ['job_id' => $jobId, 'meta' => ['status' => $resp['status'] ?? null]]);
                break;

            case 'add_consignment_products':
                $resp = ConsignmentsV20::addProducts((int) $payload['id'], (array) $payload['data']);
                Logger::info('Consignment add products done', ['job_id' => $jobId, 'meta' => ['status' => $resp['status'] ?? null]]);
                break;

            case 'update_consignment':
                $resp = ConsignmentsV20::update((int) $payload['id'], (array) $payload['data']);
                Logger::info('Consignment update done', ['job_id' => $jobId, 'meta' => ['status' => $resp['status'] ?? null]]);
                break;

            case 'pull_products':
            case 'pull_inventory':
            case 'pull_consignments':
                // Stubs for future pulls
                Logger::info('Pull task stub complete', ['job_id' => $jobId, 'meta' => ['type' => $type]]);
                break;

            default:
                throw new \InvalidArgumentException('Unknown job type: ' . $type);
        }
    }

    /**
     * @return array<string,string>
     */
    private static function parseArgs(array $argv): array
    {
        $out = [];
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
                [$k, $v] = explode('=', $arg, 2);
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    exit(Runner::main($argv));
}
