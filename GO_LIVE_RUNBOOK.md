# Queue & Webhooks Go-Live Runbook (Lightspeed Consignments v2.0)

Date: 2025-09-20
Owner: Ecigdis Ltd — CIS Team
Scope: Queue service, webhooks receiver/admin, metrics, dashboard, DLQ operations

---

## 1. Public Endpoints (all JSON unless noted)

- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics (text): https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Dashboard (HTML): https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
- Verify (tables present): https://staff.vapeshed.co.nz/assets/services/queue/public/verify.php
- DB Sanity (auth): https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php

Admin-gated (Bearer token via Authorization header):
- Enqueue Job: https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
- Queue Status: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
- Pause: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.pause.php
- Resume: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.resume.php
- Update Concurrency: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.concurrency.update.php
- Migrate (forward-only): https://staff.vapeshed.co.nz/assets/services/queue/public/migrate.php
- Prefix Migrate cishub_* views: https://staff.vapeshed.co.nz/assets/services/queue/public/prefix.migrate.php
- Manual Token Refresh: https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php
- DLQ Redrive: https://staff.vapeshed.co.nz/assets/services/queue/public/dlq.redrive.php
- Webhook Admin Lists:
  - Subscriptions: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php
  - Subscriptions Update (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.update.php
  - Events: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php
  - Health: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.health.php
  - Stats: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.stats.php
  - Replay (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.replay.php
- Webhook Test Sender (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.test.php

Vend webhook receiver (validates HMAC/time):
- Receiver: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php

---

## 2. Pre-flight Checklist

1) Tables exist (Verify page):
   - ls_jobs, ls_job_logs, ls_jobs_dlq, ls_rate_limits, ls_sync_cursors,
     webhook_subscriptions, webhook_events, webhook_health, webhook_stats,
     transfer_queue (compat shim for heartbeat)
2) OAuth config values set (vend_refresh_token, vend_token_expires_at).
3) Circuit breaker should be closed (metrics vend_circuit_breaker_open = 0).
4) Dashboard renders without fatals; token shows positive seconds.
5) Metrics populated after a test call to Vend (Avg, buckets, P95 appear under vend_http_*).
6) Optional (not required now): cishub_* view prefix
  - IMPORTANT: Runtime uses only ls_* and webhook_* tables. Do not run prefix migration in production at this time.
  - These views are for future standardization/visibility and are safe to skip for go-live.
  - If explicitly needed later: POST https://staff.vapeshed.co.nz/assets/services/queue/public/prefix.migrate.php with body {"dry_run": true} to preview, then again with {"dry_run": false} to create.
7) Run DB Sanity (auth): https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php — expect ok=true, write_probe=ok, and webhook freshness keys present.

---

## 3. Minimal Smoke

- Health → expect { db: "ok", token_expires_in: > 0, jobs: counts }
- Metrics → expect ls_jobs_* totals, ls_queue_paused flags, vend_http_* averages/buckets
- Status → expect two job types with paused=false and sensible caps
- Dashboard → cards visible: Token, Jobs, Vend API SLOs, Queue SLOs, DLQ section

One-minute launch sequence (concise):
1) Open Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php — ensure { db: "ok" } and token_expires_in > 0.
2) Open Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php — confirm ls_jobs_* present.
3) Run DB Sanity (auth): https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php — ok=true, write_probe=ok. Missing cishub_* or cisq_* (optional) will not fail this check.
4) Send signed webhook (auth): POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.test.php — default inventory.update.
5) Check Events (auth): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php?limit=50 — new row with status received/processing.
6) Refresh Health: last_event_age_seconds small; Metrics shows webhook_metric received_count > 0 in last minute.

---

## 4. Operational Controls

- Pause/Resume: via Dashboard form or POST to pause/resume endpoints
- Concurrency caps: via Dashboard form (0..50) per job type
- DLQ Redrive: from Dashboard
  - Oldest N — safe: requeues with next_run_at and decremented attempts
  - Selected — same behavior for chosen IDs

Safety notes:
- Redrive uses INSERT ... ON DUPLICATE KEY UPDATE (idempotent) and schedules 1 minute later.
- Attempts decremented to avoid hot-looping.

---

## 5. Webhooks

- Receiver enforces timestamp skew ±5 minutes and HMAC signature.
- Events are inserted into webhook_events and counted in webhook_stats (1-min buckets).
- Subscriptions auto-update health counters (events_received_today, total, last_event_received).

Troubleshooting:
- Invalid signature or stale timestamp → webhook_events.status=failed, webhook_health updated to critical, webhook_stats.failed_count++.
 - If events are not appearing, verify Authorization header for admin endpoints and ensure Config key vend_webhook_secret is set. Use keys.rotate.php to rotate with overlap if needed.

---

## 6. SLO Interpretation (Dashboard)

- Vend API SLOs (current minute)
  - Avg (ms): vend_http_latency_avg_ms by method.
  - P95 (ms): derived from vend_http_latency_bucket_ms cumulative buckets.
  - Error %: (4xx+5xx+429)/total.
- Queue SLOs
  - Processing P95: 95th-percentile of completed/working durations sampled over last day.
  - Oldest Pending Age: seconds since oldest pending job created.
  - DLQ Trend: last 6-hour hourly counts (or total fallback if moved_at absent).

---

## 7. Rollback & Recovery

- To stop processing: Pause both job types.
- To drain: Leave paused=false and watch oldest pending age tick down; keep an eye on DLQ trend.
- To recover from spikes: Increase per-type caps temporarily; verify vend_circuit_breaker_open remains 0.

---

## 8. Known Good URLs

- Queue Status: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php

---

## 9. Logging & Errors

- Application errors: logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log (rotate: .1)
- Rate-limit writes land in ls_rate_limits; use metrics page to validate.
- Webhook failures: webhook_events (status, error_message), webhook_health, and webhook_stats.

Note on table names:
- The service is pinned to the normal schema names (ls_* and webhook_*). Any cishub_* or cisq_* objects are optional views only and are not referenced by operational code (enqueue/claim/complete/fail/metrics/health).

---

## 10. Post-Go-Live Watchlist (first 48h)

- vend_http error% < 1% sustained; P95 under 800ms.
- Oldest pending age stays under 2 minutes during steady load.
- DLQ does not grow; trend is flat or decreasing.
- Circuit breaker stays closed.

---

## 11. Transfer Validation Cache (optional)

Table: `transfer_validation_cache`
- Purpose: Caches validation results for Store Transfer requests (owned by Stock-Transfer pipeline).
- Ownership: Primary ownership sits with the Stock-Transfer service/team. The queue service only exposes a safe cleanup helper.
- Lifecycle: Rows expire via `expires_at` timestamp; recommended TTL policy defined by the Stock-Transfer logic.

Cleanup helper (admin, POST JSON):
- URL: https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_cache.php
- Body (optional): { "limit": 1000, "dry_run": true }
- Behavior: Deletes up to N expired rows (ordered by id). Supports dry-run.

Cautions:
- Do not delete non-expired rows; validation may be reused across retries.
- Coordinate TTL durations with Stock-Transfer team.

