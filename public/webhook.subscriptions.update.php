<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
\Queue\Lightspeed\Web::webhookSubscriptionsUpdate();
