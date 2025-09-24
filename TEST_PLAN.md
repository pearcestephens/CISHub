# TEST PLAN — Lightspeed Queue Module

## Smoke (NO writes)
1) Health endpoint
   - GET https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
   - Expect `{ ok: true, data: { db: 'ok', jobs: {pending,working,failed}, cursor_status: {...} } }`

2) Metrics endpoint
   - GET https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
   - Expect Prometheus text with `ls_jobs_*` and `ls_cursor_age_seconds` lines.

## Auth-required (use admin bearer)
3) Webhook subs list
   - GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php
   - Expect envelope with `items` array.

4) Webhook events list
   - GET https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php?limit=5
   - Expect items with statuses.

5) Enqueue job (idempotent)
   - POST https://staff.vapeshed.co.nz/assets/services/queue/public/job.php body `{type:"create_consignment", payload:{...}, idempotency_key:"abc"}`
   - Repeat same POST; expect same logical outcome and no duplicates in `ls_jobs`.

## Heartbeat verification
6) After applying one of the fixes in BUGLOG BBB-0001, re-run heartbeat job and verify `logs/heartbeat.log` no longer shows missing table `transfer_queue`.

## Webhooks (signature required)
7) With `vend_webhook_secret` set, send a signed request to https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php with headers `X-LS-Timestamp`, `X-LS-Signature`, `X-LS-Webhook-Id` and JSON body `{type:"vend.selftest"}`. Expect `ok: true` and rows in `webhook_events` and `webhook_health`.
