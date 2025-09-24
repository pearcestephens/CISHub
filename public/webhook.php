<?php
declare(strict_types=1);

/**
 * assets/services/queue/public/webhook.php
 *
 * Hardened webhook intake:
 * - Verifies HMAC-SHA256 signatures with timestamp tolerance
 * - Supports previous secret overlap for key rotation
 * - Inserts into webhook_events then enqueues webhook.event (idempotent)
 * - Updates stats/health tables for observability
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;
use Queue\FeatureFlags;
use Queue\PdoConnection;
use Queue\PdoWorkItemRepository as Repo;

Http::commonJsonHeaders();

// Kill switch: webhook intake disabled
if (FeatureFlags::isDisabled(FeatureFlags::webhookEnabled())) {
  http_response_code(503);
  echo json_encode([
    'ok'    => false,
    'error' => ['code' => 'webhook_disabled', 'message' => 'Webhook intake is currently disabled.'],
    'meta'  => ['flags' => FeatureFlags::snapshot()],
  ]);
  exit;
}

// --- Config & helpers ---
$secret        = (string)(Config::get('vend_webhook_secret', '') ?? '');
if ($secret === '') {
  // Fallback to client secret if present (legacy)
  $secret = (string)(Config::get('vend.client_secret', '') ?? '');
}
$prevSecret    = (string)(Config::get('vend_webhook_secret_prev', '') ?? '');
$prevSecretExp = (int)(Config::get('vend_webhook_secret_prev_expires_at', 0) ?? 0);

$tolerance     = (int)(Config::get('vend.webhook.tolerance_s', 300) ?? 300);
$openMode      = (bool)(Config::getBool('vend.webhook.open_mode', false) || Config::getBool('webhook.auth.disabled', false));
$openUntil     = (int)(Config::get('vend.webhook.open_mode_until', 0) ?? 0);
$openActive    = $openMode && ($openUntil === 0 || time() <= $openUntil);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  Http::error('method_not_allowed', 'POST required', null, 405);
  exit;
}

// Read the raw body ONCE
$rawBody     = file_get_contents('php://input') ?: '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$headers     = [];
foreach ($_SERVER as $k => $v) {
  if (strpos($k, 'HTTP_') === 0 || in_array($k, ['CONTENT_TYPE','CONTENT_LENGTH'], true)) {
    $headers[$k] = is_string($v) ? $v : json_encode($v);
  }
}

// Decode payload (JSON or form payload=<json>)
$payload = [];
if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
  $form = [];
  parse_str($rawBody, $form);
  $payloadStr = isset($form['payload']) ? (string)$form['payload'] : '';
  if ($payloadStr !== '') {
    $tmp = json_decode($payloadStr, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
      $payload = $tmp;
      // For signature, most vendors sign the *full* HTTP body (payload=...), so leave $rawBody as-is.
    }
  }
} else {
  $tmp = json_decode($rawBody, true);
  if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
    $payload = $tmp;
  }
}

// Extract metadata hints
$tsHdr    = $_SERVER['HTTP_X_LS_TIMESTAMP'] ?? ($_SERVER['HTTP_X_VEND_TIMESTAMP'] ?? '');
$wid      = $_SERVER['HTTP_X_LS_WEBHOOK_ID'] ?? null;
$eventHdr = $_SERVER['HTTP_X_LS_EVENT_TYPE'] ?? ($_SERVER['HTTP_X_LS_TOPIC'] ?? ($_SERVER['HTTP_X_VEND_TOPIC'] ?? ''));
$event    = (string)($payload['type'] ?? $eventHdr ?? '');

// --- Signature verification ---
$authState = 'none';
$now       = time();

$haveSecret = ($secret !== '');
$requireAuth = !$openActive && $haveSecret;

// Read signatures from headers
$sigHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$lsSig     = $_SERVER['HTTP_X_LS_SIGNATURE'] ?? '';
$parsedSig = null;
$algorithm = 'HMAC-SHA256';

if ($sigHeader !== '' && strpos($sigHeader, 'signature=') !== false) {
  $parts = [];
  foreach (explode(',', $sigHeader) as $kv) {
    $kvp = array_map('trim', explode('=', $kv, 2));
    if (count($kvp) === 2) {
      $parts[strtolower($kvp[0])] = trim($kvp[1], " \t\n\r\0\x0B\"'");
    }
  }
  $parsedSig = $parts['signature'] ?? null;
  $algorithm = strtoupper($parts['algorithm'] ?? 'HMAC-SHA256');
} elseif ($lsSig !== '') {
  $parsedSig = trim($lsSig);
}

$verified = false;
$whyFail  = null;

$makeCandidates = function(string $secretUse) use ($rawBody, $tsHdr): array {
  $cands = [];
  // raw body
  $cands[] = base64_encode(hash_hmac('sha256', $rawBody, $secretUse, true));
  $cands[] = hash_hmac('sha256', $rawBody, $secretUse, false);
  // timestamp + "." + body
  if ($tsHdr !== '') {
    $combo = $tsHdr . '.' . $rawBody;
    $cands[] = base64_encode(hash_hmac('sha256', $combo, $secretUse, true));
    $cands[] = hash_hmac('sha256', $combo, $secretUse, false);
  }
  return $cands;
};

if ($requireAuth) {
  if (!is_string($parsedSig) || $parsedSig === '') {
    $whyFail = 'missing_signature';
  } elseif ($algorithm !== 'HMAC-SHA256') {
    $whyFail = 'unsupported_algorithm';
  } else {
    // Optional timestamp tolerance
    if ($tsHdr !== '') {
      $tsVal = (int)$tsHdr;
      if ($tsVal <= 0 || abs($now - $tsVal) > max(0, $tolerance)) {
        $whyFail = 'stale_timestamp';
      }
    }

    if ($whyFail === null) {
      $cands = $makeCandidates($secret);
      $match = false;
      foreach ($cands as $cand) {
        if (hash_equals($cand, $parsedSig)) { $match = true; break; }
      }
      // try previous secret within grace window
      if (!$match && $prevSecret !== '' && ($prevSecretExp === 0 || $now <= $prevSecretExp)) {
        $c2 = $makeCandidates($prevSecret);
        foreach ($c2 as $cand) {
          if (hash_equals($cand, $parsedSig)) { $match = true; break; }
        }
      }
      $verified = $match;
      if (!$verified) $whyFail = 'signature_mismatch';
    }
  }
} else {
  $authState = $openActive ? 'open' : 'no_secret';
  $verified  = true; // allow through
}

if (!$verified) {
  // Record a health warning and a failed stat, then deny
  try {
    $pdo = PdoConnection::instance();
    $pdo->prepare(
      "INSERT INTO webhook_health
       (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details)
       VALUES (NOW(), 'vend.webhook', 'warning', 0, 1, JSON_OBJECT('reason', :r))"
    )->execute([':r' => (string)$whyFail]);
    $pdo->prepare(
      "INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period)
       VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP()-MOD(UNIX_TIMESTAMP(),60)), 'vend.webhook', 'failed_count', 1, '1min')
       ON DUPLICATE KEY UPDATE metric_value = metric_value + 1"
    )->execute();
  } catch (\Throwable $e) {}
  Http::error('unauthorized', 'Webhook signature verification failed: ' . $whyFail, null, 401);
  exit;
}

// --- Dedup + persistence ---
$webhookId = is_string($wid) && $wid !== '' ? $wid : sha1(($tsHdr !== '' ? $tsHdr : (string)$now) . '.' . $rawBody);
$eventType = (string)($event ?: 'vend.webhook');

$ip = $_SERVER['REMOTE_ADDR']   ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

try {
  $pdo = PdoConnection::instance();

  // Try to insert event (ignore if already there)
  $ins = $pdo->prepare(
    "INSERT INTO webhook_events
       (webhook_id, webhook_type, payload, raw_payload, source_ip, user_agent, headers, status, received_at, created_at, updated_at)
     VALUES
       (:id, :type, :pl, :raw, :ip, :ua, :hd, 'received', NOW(), NOW(), NOW())
     ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)"
  );
  $ins->execute([
    ':id'  => $webhookId,
    ':type'=> $eventType,
    ':pl'  => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ':raw' => $rawBody,
    ':ip'  => $ip,
    ':ua'  => $ua,
    ':hd'  => json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
  ]);

  // Stats: received
  try {
    $pdo->prepare(
      "INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period)
       VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP()-MOD(UNIX_TIMESTAMP(),60)), :t, 'received_count', 1, '1min')
       ON DUPLICATE KEY UPDATE metric_value = metric_value + 1"
    )->execute([':t'=>$eventType]);
  } catch (\Throwable $e) {}

  // Enqueue handler job (idempotent)
  $idk   = 'webhook:' . $webhookId;
  $jobId = Repo::addJob('webhook.event', [
    'webhook_id'   => $webhookId,
    'webhook_type' => $eventType,
  ], $idk);

  // Flip to processing and link job
  try {
    $pdo->prepare(
      "UPDATE webhook_events
         SET status='processing', queue_job_id=:jid, updated_at=NOW(),
             processed_at = IFNULL(processed_at, NULL)
       WHERE webhook_id=:wid"
    )->execute([':jid'=>(string)$jobId, ':wid'=>$webhookId]);
  } catch (\Throwable $e) {}

  // Health: mark healthy
  try {
    $pdo->prepare(
      "INSERT INTO webhook_health
         (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details)
       VALUES (NOW(), 'vend.webhook', 'healthy', 0, 0, JSON_OBJECT('reason','intake_ok'))
       ON DUPLICATE KEY UPDATE check_time=VALUES(check_time), health_status='healthy', consecutive_failures=0"
    )->execute();
  } catch (\Throwable $e) {}

  // Optional auto-kick runner
  try {
    if (Config::getBool('vend.webhook.enqueue', true) && Config::getBool('vend.queue.auto_kick.enabled', true)) {
      // Let the runner pick up promptly; we don't block here.
      // Your auto-kicker already exists in Lightspeed\Web::kickRunnerIfNeeded(),
      // but we avoid a heavy include here for speed.
    }
  } catch (\Throwable $e) {}

  Http::respond(true, [
    'received'   => true,
    'type'       => $eventType,
    'webhook_id' => $webhookId,
    'job_id'     => $jobId,
  ]);
} catch (\Throwable $e) {
  Http::error('webhook_intake_failed', $e->getMessage(), null, 500);
}
