---
title: API Reference — Queue & Webhooks
description: Public and admin endpoints, request/response formats, and error envelopes for the Lightspeed queue service.
---

# API Reference — Queue & Webhooks

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/API_REFERENCE.md

Global conventions:
- Base path: https://staff.vapeshed.co.nz/assets/services/queue/public/
- Content-Type: application/json unless otherwise noted
- Auth: Authorization: Bearer <ADMIN_BEARER_TOKEN> for admin endpoints
- Rate limits: IP-scoped per route; 429 Too Many Requests with Retry-After
- Idempotency: job enqueue accepts idempotency_key to avoid duplicates
- Related docs:
  - Operations: https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md
  - Webhooks: https://staff.vapeshed.co.nz/assets/services/queue/docs/WEBHOOKS.md
  - Metrics: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md
  - Architecture: https://staff.vapeshed.co.nz/assets/services/queue/docs/ARCHITECTURE.md
  - Security: https://staff.vapeshed.co.nz/assets/services/queue/docs/SECURITY.md

Envelope format:
{
  "success": true|false,
  "data": { ... },
  "error": { "code": "...", "message": "...", "details": { } },
  "meta": { "request_id": "...", "ts": "ISO8601" }
}

All admin endpoints require Authorization: Bearer <ADMIN_BEARER_TOKEN>.

## Health

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
Response 200:
{
  "success": true,
  "data": {
    "db": "ok",
    "token_expires_in": 12345,
    "jobs": { "pending": 0, "working": 0, "failed": 0 },
    "cursor_status": { }
  },
  "meta": { "request_id": "..." }
}

## Metrics (Prometheus text)

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
Response 200 (text/plain): lines like `ls_jobs_pending_total 42`

## Dashboard (HTML)

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php

## Verify (tables)

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/verify.php

## Queue Status (admin)

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
Response 200:
{
  "success": true,
  "data": {
    "types": [
      { "type": "create_consignment", "paused": false, "max_concurrency": 10 },
  { "type": "push_product_update", "paused": false, "max_concurrency": 10 }
    ]
  }
}

## Pause / Resume (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/queue.pause.php
- POST https://staff.vapeshed.co.nz/assets/services/queue/public/queue.resume.php
Body (JSON): { "type": "create_consignment" }
Status: 200 OK
Response: { "success": true, "data": { "paused": "create_consignment" } } (pause)
Response: { "success": true, "data": { "resumed": "create_consignment" } } (resume)
Errors: 400 bad_request (missing type), 401 unauthorized

## Update Concurrency (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/queue.concurrency.update.php
Body (JSON): { "type": "create_consignment", "cap": 25 }
Status: 200 OK
Response: { "success": true, "data": { "type": "create_consignment", "cap": 25 } }
Errors: 400 bad_request (type/cap required; cap 0..50), 401 unauthorized

## Enqueue Job (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
Headers: Content-Type: application/json; Authorization: Bearer <token>
Body:
{
  "type": "push_product_update",
  "payload": { "product_id": 123, "data": { /* v2.1 updateproduct fields including inventory-related fields */ } },
  "priority": 3,
  "idempotency_key": "product:123:update:stock"
}
Response 200:
{
  "success": true,
  "data": { "id": 98765 }
}

### Add Consignment Products (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
Headers: Content-Type: application/json; Authorization: Bearer <token>
Body:
{
  "type": "add_consignment_products",
  "payload": {
    "consignment_id": 987654,
    "lines": [ { "product_id": 12345, "qty": 4, "sku": "ABC-001" } ],
    "idempotency_key": "cons:987654:add:2025-09-20"
  },
  "priority": 3
}
Response 200: { "success": true, "data": { "id": <job_id> } }

Notes:
- Internally calls POST /api/2.0/consignments/{id}/products
- Full lifecycle logging is recorded in transfer_audit_log and transfer logs with action consignment.add_products

Errors:
- 400 bad_request (type invalid or missing; idempotency_key too long)
- 401 unauthorized (missing/invalid bearer)
- 429 rate_limited

Idempotency:
- Provide `idempotency_key` (<=128 chars) for natural de-duplication (e.g., `consignment:<transfer_public_id>`)
- Re-sending the same request with the same key will return the existing outcome (no duplicate row)
- Omit the key only if duplicates are safe for your use case (not recommended)

## DLQ Redrive (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/dlq.redrive.php
Body examples:
{ "mode": "oldest", "limit": 100 }
{ "mode": "ids", "ids": [101, 102] }
Response 200: { "success": true, "data": { "requeued": 98 } }
Errors: 401 unauthorized, 429 rate_limited, 400 bad_request

## Migrate (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/migrate.php
Status: 200 OK
Response: { "success": true, "data": { "message": "Migrations applied", "files": ["..."], "url": "https://staff.vapeshed.co.nz/assets/services/queue/public/migrate.php" } }
Errors: 401 unauthorized, 429 rate_limited, 400/500 on migration_failed

## Manual Token Refresh (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php
Status: 200 OK
Response: { "success": true, "data": { "expires_at": 1699999999, "expires_in": 3600 } }
Errors: 401 unauthorized, 429 rate_limited

## Webhook Receiver (Vend)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php
Headers: X-LS-Timestamp, X-LS-Signature, X-LS-Webhook-Id
Body: JSON payload from Lightspeed
Response 200: { "success": true }
Errors: 401 unauthorized (signature_mismatch or stale timestamp); 403 disabled (LS_WEBHOOKS_ENABLED=false)

More details: https://staff.vapeshed.co.nz/assets/services/queue/docs/WEBHOOKS.md

## Webhook Admin (admin)

- GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php
- POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.update.php
- GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php?limit=50
- GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.health.php
- GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.stats.php
- POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.replay.php

Schemas:
- webhook.subscriptions (GET) → { rows: [ { id, source_system, event_type, endpoint_url, is_active, last_event_received, events_received_today, events_received_total, health_status, health_message, updated_at } ] }
- webhook.subscriptions.update (POST) → body may include one or more of: { id, is_active, endpoint_url, event_type }
- webhook.events (GET) → query: limit (1..500), type(optional) → { rows: [ { id, webhook_id, webhook_type, status, received_at, processed_at, error_message } ] }
- webhook.health (GET) → { rows: [ { id, check_time, webhook_type, health_status, response_time_ms, consecutive_failures } ] }
- webhook.stats (GET) → { rows: [ { recorded_at, webhook_type, metric_name, metric_value, time_period } ] }
- webhook.replay (POST) → body: { ids: [..], reason?: string } → { updated: N }
Errors: 401 unauthorized, 429 rate_limited, 400 bad_request

Idempotency for replay:
- `webhook.replay` marks events as replayed and records `replayed_from` and `replay_reason` to ensure traceability
- If invoked multiple times for the same IDs, the operation is effectively idempotent (status remains 'replayed')

## Transfer Validation Cache (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_cache.php
Body: { "limit": 1000, "dry_run": true }
Response 200: { "success": true, "data": { "deleted": 0, "dry_run": true } }
Errors: 401 unauthorized, 429 rate_limited

## Keys rotate (admin)

- POST https://staff.vapeshed.co.nz/assets/services/queue/public/keys.rotate.php
Body: { "target": "admin_bearer|vend_webhook", "overlap_minutes": 60, "new_secret"?: "string", "show_secret"?: true }
Response 200: { "success": true, "data": { "rotated": "admin_bearer", "overlap_minutes": 60, "prev_expires_at": 1700000000, "new_secret": "..." } }
Errors: 401 unauthorized, 429 rate_limited, 400 bad_request, 500 rotation_failed

## Curl Examples

Enqueue job:
curl -s -X POST \
  -H 'Authorization: Bearer $TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"type":"create_consignment","payload":{"transfer_id":12345},"idempotency_key":"consignment:12345"}' \
  https://staff.vapeshed.co.nz/assets/services/queue/public/job.php | jq .

Pause a type:
curl -s -X POST \
  -H 'Authorization: Bearer $TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"type":"create_consignment"}' \
  https://staff.vapeshed.co.nz/assets/services/queue/public/queue.pause.php | jq .

Set concurrency cap:
curl -s -X POST \
  -H 'Authorization: Bearer $TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"type":"create_consignment","cap":25}' \
  https://staff.vapeshed.co.nz/assets/services/queue/public/queue.concurrency.update.php | jq .

More examples:
- DLQ redrive: see https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md#dlq-redrive
- Metrics exploration: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md
- Security model: https://staff.vapeshed.co.nz/assets/services/queue/docs/SECURITY.md

## Error Model

Errors follow the envelope with HTTP 4xx/5xx codes and machine-actionable `error.code` values.
