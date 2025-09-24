<?php
/**
 * File: assets/services/queue/public/keys.rotate.php
 * Purpose: Admin endpoint to rotate ADMIN_BEARER_TOKEN or vend_webhook_secret with overlap window.
 * Author: Automated assistant
 * Last Modified: 2025-09-20
 * Dependencies: PdoConnection, Config, Http, Queue\Lightspeed\Web
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
\Queue\Lightspeed\Web::keysRotate();
