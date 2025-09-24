# TRACE_MAP — Lightspeed Queue and Webhooks

Scope: This maps entry points, call graphs, and DB touchpoints for the queue module under `assets/services/queue`.

## Entry points (HTTP)
- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php → Queue\Lightspeed\Web::health()
- Metrics (Prometheus): https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php → Web::metrics()
- Enqueue job (POST, auth): https://staff.vapeshed.co.nz/assets/services/queue/public/job.php → Web::job()
- Manual token refresh (POST, auth): https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php → Web::manualRefresh()
- Webhook receiver (Vend): https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php → Web::webhook()
- Webhook admin (GET, auth):
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php → Web::webhookSubscriptions()
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.events.php → Web::webhookEvents()
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.health.php → Web::webhookHealthList()
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.stats.php → Web::webhookStats()
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.update.php (POST, auth) → Web::webhookSubscriptionsUpdate()
  - https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.replay.php (POST, auth) → Web::webhookReplay()

- Transfers migration (POST, auth): https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.php → Web::transferMigrate()
  - Body JSON: { "dry_run": bool, "limit": number (<=2000), "since_id": number, "since_date": "YYYY-MM-DD", "only_type": "stock|juice|staff|ST|JT|IT" , "only_types": ["ST","JT","IT","STAFF",...] }
  - Filtering: When only_type/only_types is provided, only matching transfer types are imported. Synonyms supported: ST/STOCK, JT/JUICE/JUI, IT/INTERNAL/INTER/SPECIAL, STAFF.
    - Example (stock-only dry-run): POST https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.php with {"dry_run":true, "only_type":"stock", "limit":500}
  - Behavior: creates transfer_executions, transfer_allocations, transfer_discrepancies, transfer_shipments, transfer_parcels, transfer_parcel_items, and ls_id_sequences if missing; backfills from legacy tables and populates notes, one shipment + parcel + parcel-items per transfer.
  - Response counters: backfilled.{executions,allocations,legacy_transfers,legacy_items,shipments,parcels,parcel_items,carrier_normalized,carrier_logs}
  - Preview (GET, auth): https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.preview.php → Web::transferMigratePreview()
    - Query params: limit, since_id, since_date, only_type, only_types (csv or repeated)
    - Example (stock-only preview): GET .../transfer.migrate.preview.php?only_type=stock&limit=500
  - Inspect (GET, auth): https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.inspect.php
    - Query params: id or public_id — returns transfer, items, shipments, parcels, parcel_items, notes, audit (if present)
  - Public IDs: BEFORE INSERT triggers generate per-type, per-period IDs with prefixes and check digits.
    - Tables: `ls_id_sequences` maintains per-type counters keyed by (seq_type, period).
  - Formats: TX-[TYPE]-YYYYMM-SEQ6-CD (executions), TA-[TYPE]-YYYYMM-SEQ6-CD (allocations)
  - Synonyms: STOCK/ST/STOCKTAKE/STOCK TAKE/STOCK-TAKE/STK → stock; JUICE/JUI/JT → juice; INTERNAL/INTER/SPECIAL/IT/STAFF → staff/internal.
    - Properties: collision-safe, monotonic within (type, period), portable across app instances, verifiable via mod97 check.

- Pipeline Trace (visual): https://staff.vapeshed.co.nz/assets/services/queue/public/pipeline.trace.php
  - Data API (JSON): https://staff.vapeshed.co.nz/assets/services/queue/public/pipeline.trace.data.php?trace={trace_id}&job={job_id}
  - Usage: paste trace_id from Quick Qty response (X-Trace-Id header or JSON field), watch checkpoints propagate Submit Handler → Queue → Runner → Worker. Also matches webhook events.

## CLI utilities (bin)
- https://staff.vapeshed.co.nz/assets/services/queue/bin/migrate.php (DB bootstrap, safe re-run)
- https://staff.vapeshed.co.nz/assets/services/queue/bin/run-jobs.php (worker loop)
- https://staff.vapeshed.co.nz/assets/services/queue/bin/reap-stale.php (lease cleanup)
- https://staff.vapeshed.co.nz/assets/services/queue/bin/schedule-pulls.php (scheduler)

## Core classes
- https://staff.vapeshed.co.nz/assets/services/queue/src/PdoConnection.php — PDO singleton
- https://staff.vapeshed.co.nz/assets/services/queue/src/PdoWorkItemRepository.php — queue storage API
- https://staff.vapeshed.co.nz/assets/services/queue/src/Lightspeed/Runner.php — executes jobs: create_consignment, update_consignment
- https://staff.vapeshed.co.nz/assets/services/queue/src/Lightspeed/HttpClient.php — Vend HTTP with retries/idempotency
- https://staff.vapeshed.co.nz/assets/services/queue/src/Lightspeed/ConsignmentsV20.php — create/updateFull
- https://staff.vapeshed.co.nz/assets/services/queue/src/Lightspeed/Web.php — HTTP handlers (health, job, webhook, metrics, admin)

## Database tables (created by `sql/schema.sql` or `sql/migrations.sql`)
- Queue core:
  - `ls_jobs` (status: pending/working/done/failed; leases, heartbeats, backoff)
  - `ls_job_logs` (per-job logs)
  - `ls_jobs_dlq` (dead-letter queue)
  - `ls_rate_limits` (per-route counters)
  - `ls_sync_cursors` (pull cursors for mirrors)
- Webhooks:
  - `webhook_subscriptions`, `webhook_events`, `webhook_health`, `webhook_stats`

## Notable feature flags/settings (Config)
- `vend_refresh_token`, `vend_access_token`, `vend_token_expires_at`
- `vend_webhook_secret` (HMAC signing shared secret)
- `LS_WEBHOOKS_ENABLED` (default true)

## Observed non-module dependency (legacy heartbeat)
## Quick runbook — transfers

Auth required:

- All endpoints below require an admin bearer. Set an environment variable for convenience:

```bash
export ADMIN_BEARER="<paste-your-admin-bearer-token>"
```

- Add this header on every request: `-H "Authorization: Bearer $ADMIN_BEARER"`

Getting a bearer (if you don't have one):

- Generate/rotate an admin bearer that is valid immediately:

```bash
curl -sS -X POST https://staff.vapeshed.co.nz/assets/services/queue/public/keys.rotate.php \
  -H "Content-Type: application/json" \
  -d '{"target":"admin_bearer","overlap_minutes":60, "show_secret":true}' | jq .
```

- The response includes `data.new_secret` when show_secret=true. Export it:

```bash
export ADMIN_BEARER="$(curl -sS -X POST https://staff.vapeshed.co.nz/assets/services/queue/public/keys.rotate.php \
  -H "Content-Type: application/json" \
  -d '{"target":"admin_bearer","overlap_minutes":60, "show_secret":true}' | jq -r '.data.new_secret')"
```

- Note: keys.rotate.php itself is auth-protected when ADMIN_BEARER_TOKEN is already set. If no token exists in `configuration.ADMIN_BEARER_TOKEN`, the system allows open access for bootstrapping.

Preview counts (safe):

```bash
curl -sS -G https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.preview.php \
  -H "Authorization: Bearer $ADMIN_BEARER" \
  --data-urlencode "limit=500" | jq .

curl -sS -G https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.preview.php \
  -H "Authorization: Bearer $ADMIN_BEARER" \
  --data-urlencode "only_type=stock" \
  --data-urlencode "limit=500" | jq .
```

Apply migration (idempotent, recommended in batches):

```bash
curl -sS -X POST https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.php \
  -H "Authorization: Bearer $ADMIN_BEARER" \
  -H "Content-Type: application/json" \
  -d '{"limit":200}' | jq .

curl -sS -X POST https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.migrate.php \
  -H "Authorization: Bearer $ADMIN_BEARER" \
  -H "Content-Type: application/json" \
  -d '{"dry_run":true,"limit":200,"only_type":"stock"}' | jq .
```

Inspect a transfer:

```bash
curl -sS -G https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.inspect.php \
  -H "Authorization: Bearer $ADMIN_BEARER" \
  --data-urlencode "id=12345" | jq .
```

Note: Some environments ship an older curl without `--fail-with-body`. The examples above are compatible. If you want failures to exit non-zero, add `--fail` (it will hide body on non-2xx). For explicit timeouts, append `--max-time 20`.

Troubleshooting:

- 401/403: Bearer is missing/invalid/expired. Rotate or re-fetch the admin bearer and retry.
- 429: You hit the route rate limit (preview: ~5qps, migrate: ~10qps). Back off and retry after a few seconds.
- Timeouts/hangs: add `-v` to curl for headers, or set a hard timeout `--max-time 20` to avoid lingering connections.
- Heartbeat log references missing table `transfer_queue` (legacy stock transfer pipeline). This module does not use that table; queue storage uses `ls_jobs`. See BUGLOG for remediation options.

UI: Browse transfers quickly (no auth header in browser):

- https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/transfers.php
  - Filters: since_date=YYYY-MM-DD, type=ST|JT|IT|RT, limit (query params)
  - Each row links to transfer.inspect.php for details

## Emergency pipeline shutdown (kill switches)

To immediately halt automated processing and show a red banner on the Queue Dashboard:

- Global runner kill (stops the worker loop instantly):
  - Set config key `vend.queue.kill_all=true`

- Inventory pipeline kill (forces inventory.command to no-op):
  - Set `inventory.kill_all=true`

- Pause only a specific queue type (e.g., inventory.command):
  - Set `vend_queue_pause.inventory.command=true`

- Disable webhooks intake entirely:
  - Set `LS_WEBHOOKS_ENABLED=false`

Effects:
- Dashboard will show a red banner listing which switches are active.
- Runner will refuse to process when global kill is on; inventory commands are treated as no-ops when the inventory kill is on.
- Webhook endpoint will return 403 when disabled.

Re-enable by setting the keys back to false and reload the dashboard.

## Bot bypass (temporary webhook open mode)

If upstream cannot sign webhooks or you need to test without HMAC temporarily, enable a controlled bypass window. Use with caution and timebox it.

- Enable open mode (accept unsigned): set `vend.webhook.open_mode=true`.
- Optional auto-expiry: set `vend.webhook.open_mode_until` to a future epoch seconds (e.g., `time()+900` for 15 minutes).
- Alternative hard bypass: set `webhook.auth.disabled=true` to fully disable signature checks. Prefer `vend.webhook.open_mode` with `open_mode_until` instead.

Observe current state via health flags: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php (see `data.flags`).

Return to secure mode by clearing/reverting:
- `vend.webhook.open_mode=false`, unset `vend.webhook.open_mode_until`, and `webhook.auth.disabled=false`.
