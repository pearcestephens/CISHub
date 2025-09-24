---
title: Webhooks — Lightspeed X-Series
description: Receiver validation, topics, admin tools, stats, and replay behavior for Lightspeed webhooks.
---

# Webhooks Module

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/WEBHOOKS.md

## Receiver

Primary endpoint
- URL: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php
- Method: POST, Content-Type: application/json

Required headers
- X-LS-Timestamp: ISO8601 or epoch seconds; must be within ±300s of server time
- X-LS-Signature: HMAC-SHA256 of raw HTTP request body using shared secret `vend_webhook_secret`
- X-LS-Webhook-Id: Provider event id (stored for idempotency/audit)
- X-LS-Topic or X-LS-Event-Type: Provider event/topic key (stored as `webhook_type`)

Validation algorithm (constant-time compare)
1) Reject if missing any required header; respond 400 with error envelope
2) Validate timestamp skew within ±5 minutes of server time
3) Compute expected_signature = base64(hmac_sha256(raw_body, vend_webhook_secret))
4) Compare expected_signature to X-LS-Signature using hash_equals (constant-time)
5) If validation fails → persist event with status=failed and reason; increment counters; return 401/403 depending on failure mode
6) If validation passes → persist event (status=received), enqueue internal processing if applicable; return 202 Accepted

Response envelope
```json
{ "success": false, "error": { "code": "signature_mismatch", "message": "HMAC verification failed" }, "request_id": "..." }
```
On success:
```json
{ "success": true, "data": { "status": "accepted" }, "request_id": "..." }
```

Related endpoints
- Self-test: https://staff.vapeshed.co.nz/assets/services/queue/public/selftest.php
- Verify helper (echo + HMAC guidance): https://staff.vapeshed.co.nz/assets/services/queue/public/verify.php
- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php

Alternate receiver (legacy-compatible)
- Script: https://staff.vapeshed.co.nz/assets/cron/vend-webhook_two.php
- Behavior: instant 200 ACK, HMAC/timestamp validation (if secret configured), persists raw and typed payloads to disk DLQ, logs to canonical DB tables (`webhook_events`, `webhook_stats`, `webhook_health`), optionally enqueues a queue job.
- Config: set `VEND_WEBHOOK_SECRET` in environment/config; flip `ENQUEUE_TO_QUEUE` to true to enqueue; Magento sync is intentionally disabled.

## Topics

Common topics include product changes, inventory updates, consignments, and outlet changes. The exact taxonomy is maintained by Lightspeed; we persist `webhook_type` as received and use it for filters and admin views.

## Admin Endpoints

- Subscriptions (GET): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php
- Subscriptions Update (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.update.php
- Events (GET): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php?limit=50
- Health (GET): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.health.php
- Stats (GET): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.stats.php
- Replay (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.replay.php

All admin endpoints require `Authorization: Bearer <ADMIN_BEARER_TOKEN>`.

## Storage Tables

Canonical definitions: https://staff.vapeshed.co.nz/assets/services/queue/sql/migrations.sql

- webhook_events(
  id BIGINT PK,
  webhook_id VARCHAR(128) UNIQUE,
  webhook_type VARCHAR(64),
  status ENUM('received','processing','completed','failed','replayed'),
  error_message TEXT NULL,
  received_at TIMESTAMP,
  processed_at TIMESTAMP NULL,
  processing_attempts INT UNSIGNED DEFAULT 0,
  payload LONGTEXT,
  raw_payload LONGTEXT,
  headers LONGTEXT,
  signature VARCHAR(256) NULL,
  queue_job_id VARCHAR(64) NULL
)
- webhook_stats(id BIGINT PK, recorded_at TIMESTAMP, webhook_type VARCHAR(64), metric_name VARCHAR(64), metric_value DECIMAL(10,2), time_period ENUM('1min','5min','15min','1hour','1day'))
- webhook_health(id INT PK, check_time TIMESTAMP, webhook_type VARCHAR(64), health_status ENUM('healthy','warning','critical','unknown'), ...)
- webhook_subscriptions(id INT PK, source_system VARCHAR(32), event_type VARCHAR(64), endpoint_url VARCHAR(512), is_active TINYINT(1), ...)

## Replay Semantics

Replay is intended for troubleshooting only. The system can re-enqueue processing depending on the event type.

Safety:
- All replay actions are idempotent; duplicate processing is guarded by idempotency keys and primary keys in queue tables.
- Rate-limit protections apply.

Endpoint contract
- POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.replay.php
- Body: `{ "webhook_id": "<original id>", "reason": "<text>" }`
- Effect: Marks event as `replayed`, enqueues a job for reprocessing with correlation id linking back to original.

Audit:
- `webhook_events.replayed_from` (if supported) or a log entry links the chain; see https://staff.vapeshed.co.nz/assets/services/queue/docs/INCIDENTS.md

## Troubleshooting

- Signature mismatch: verify `vend_webhook_secret` and ensure request body is unmodified when computing HMAC.
- Clock skew: ensure server NTP is healthy; compare X-LS-Timestamp to `/public/health.php` server time.
- High failure rate: inspect `webhook_events.error_message` and `webhook_stats` buckets; check network and auth to Lightspeed if downstream calls are triggered.

## Metrics & SLOs

- Metrics endpoint: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Metrics catalog: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md
- SLOs & alerts: https://staff.vapeshed.co.nz/assets/services/queue/docs/SLO_ALERTS.md

Key metrics
- webhook.received.count, webhook.failed.count per `webhook_type`
- webhook.process.ms p50/p95/p99
- backlog depth and age for webhook-derived jobs

## Security

- Secrets reside in environment/.env; never in repo. See https://staff.vapeshed.co.nz/assets/services/queue/docs/SECURITY.md
- Bearer tokens are required for admin endpoints; rate limits enforced via `ls_rate_limits`.
- PII is redacted from logs; headers/payloads stored for audit are subject to retention policy.

## Testing

- Use Verify helper: https://staff.vapeshed.co.nz/assets/services/queue/public/verify.php to validate HMAC construction against your secret.
- Demo/test harness: https://staff.vapeshed.co.nz/assets/services/queue/public/tests.demo.php emits a signed example request shape.
- Health checks: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php and https://staff.vapeshed.co.nz/assets/services/queue/public/selftest.php

## Runbooks

- Incidents & replay guidance: https://staff.vapeshed.co.nz/assets/services/queue/docs/INCIDENTS.md
- Operations: https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md
