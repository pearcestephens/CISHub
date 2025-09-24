<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Degrade.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Degrade;
use Queue\Http;

Http::commonJsonHeaders();
echo json_encode([
  'ok' => true,
  'flags' => [
    'ui.readonly' => Degrade::isReadOnly(),
    'ui.disable.quick_qty' => Degrade::isFeatureDisabled('quick_qty'),
  ],
  'banner' => Degrade::banner(),
]);
