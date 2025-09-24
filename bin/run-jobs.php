#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/WorkItem.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Lightspeed/OAuthClient.php';
require_once __DIR__ . '/../src/Lightspeed/HttpClient.php';
require_once __DIR__ . '/../src/Lightspeed/InventoryV20.php';
require_once __DIR__ . '/../src/Lightspeed/ProductsV21.php';
require_once __DIR__ . '/../src/Lightspeed/ConsignmentsV20.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';

function parse_args(array $argv): array {
    $out = [];
    foreach ($argv as $a) if (strpos($a,'--')===0 && strpos($a,'=')!==false) { [$k,$v]=explode('=',$a,2); $out[$k]=$v; }
    return $out;
}

exit(\Queue\Lightspeed\Runner::run(parse_args($argv)));
