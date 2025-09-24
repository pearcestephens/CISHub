# CIS Queue & Webhooks — Naming, Modules, and Packaging

Link: https://staff.vapeshed.co.nz/assets/services/queue/docs/NAMING_MODULES.md

This document defines the standard naming/prefix and the modular decomposition of this project so that code, schema, and endpoints are consistent and easy to evolve.

## Standard Project Name
- Canonical: CIS Queue & Webhooks (short: CIS Queue)
- PHP root namespace (current): Queue\
- Suggested sub-namespaces:
  - Queue\Core — Job queue engine, rate limit, health/metrics
  - Queue\Webhooks — Receiver, events, subscriptions, replay
  - Queue\Lightspeed — Vend/Lightspeed adapters, OAuth, API clients
  - Queue\Inventory — CQRS commands/guards/projections for inventory
  - Queue\Ops — Migrations, prefix tools, admin ops, maintenance

## Database Naming Standard
- Prefix: cishub_
- Rationale: clearly associates tables with the CIS Integration Hub (Queue & Webhooks) project, avoids collisions, and groups entities in schema listings.
- Mapping (views created non-disruptively via prefix.migrate.php):
   - Queue core: cishub_jobs, cishub_job_logs, cishub_jobs_dlq, cishub_rate_limits, cishub_sync_cursors
   - Webhooks: cishub_webhook_subscriptions, cishub_webhook_events, cishub_webhook_stats, cishub_webhook_health
   - Extended (optional): cishub_suppliers, cishub_purchase_orders, cishub_purchase_order_lines, cishub_stocktakes, cishub_stocktake_lines, cishub_returns, cishub_return_lines

## Core Functional Subsets (Entities)
There are 12 primary subsets that function as their own entities/modules:

1) Queue Engine (Queue\Core)
   - Scheduling, leasing, priorities, retries; ls_jobs/cishub_jobs, ls_job_logs/cishub_job_logs, ls_jobs_dlq/cishub_jobs_dlq.
2) Rate Limiter (Queue\Core)
   - Per-route/IP minute windows; ls_rate_limits/cishub_rate_limits; integrated with Http::rateLimit().
3) Health & Metrics (Queue\Core)
   - health.php, metrics.php; Prometheus counters and histograms; cursors/ages.
4) Admin Ops API (Queue\Ops)
   - pause/resume, concurrency caps, DLQ redrive, migrate, selftest, keys rotate, status, job enqueue.
5) Webhook Intake (Queue\Webhooks)
   - HMAC verification, timestamp skew guards, event persistence, queue handoff.
6) Webhook Subscriptions (Queue\Webhooks)
   - Create/list/update subscriptions, health status, counters, last-event tracking.
7) Webhook Analytics (Queue\Webhooks)
   - webhook_stats (time-bucketed metrics), webhook_health (checks/errors), recent events & detail views.
8) Lightspeed Adapters (Queue\Lightspeed)
   - OAuthClient, HttpClient, ProductsV21, InventoryV20, ConsignmentsV20, UpsertRepository.
9) Inventory CQRS (Queue\Inventory)
   - Command handling via jobs (inventory.command), guards for has_inventory, (future: outbox & projections).
   - Classes: `src/Lightspeed/InventoryV20.php` (Vend/Lightspeed inventory adapter), `src/Lightspeed/UpsertRepository.php` (upsert helpers), `src/Lightspeed/Runner.php` (invokes command jobs).
   - Job types: `inventory.command`, and `webhook.event` routes `inventory.update` to commands when applicable.
   - Endpoints/tools:
     - Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
     - Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
     - Webhook intake: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php
     - Test sender: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.test.php
10) Reconciler & Schedulers (Queue\Ops)
   - Reconciler entrypoints: https://staff.vapeshed.co.nz/assets/services/queue/reconciler.php · https://staff.vapeshed.co.nz/assets/services/queue/reconciler_new.php
   - Scheduling/maintenance: https://staff.vapeshed.co.nz/assets/services/queue/public/schedule.php · https://staff.vapeshed.co.nz/assets/services/queue/public/reap.php
   - Transfer utilities & cache cleanup: https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_cache.php · https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_metrics.php · https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.inspect.php
11) Migrations & Prefix Tools (Queue\Ops)
   - sql/migrations.sql, sql/schema.sql, public/migrate.php, public/prefix.migrate.php (cishub_* views), db.sanity.php.
12) Admin UI Widgets Integration (External cis-admin)
   - cis-admin/api/widgets.list.php: queue summary, webhooks, logs, DB connectivity widgets.

### Transfers / Consignments (Queue\\Lightspeed)
- Purpose: create/update/cancel consignment (inter-outlet transfers), edit lines, mark partials.
- Classes: `src/Lightspeed/ConsignmentsV20.php` (Vend/Lightspeed consignment API), `src/Lightspeed/Runner.php` (executes job flows).
- Job types: `create_consignment`, `update_consignment`, `cancel_consignment`, `mark_transfer_partial`, `edit_consignment_lines`, `add_consignment_products`.
- Endpoints & tools:
   - Enqueue jobs: https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
   - Observe status: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
   - Inspect/cleanup helpers: https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.inspect.php · https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_cache.php · https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_metrics.php
   - Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php

## Packaging Options
- Package: cis-queue-core
   - Core engine, rate-limit, health/metrics, admin ops, core migrations (cishub_* core tables).
- Package: cis-webhooks
  - Receiver, events, subscriptions, analytics, test sender, webhooks migrations.
- Package: cis-lightspeed-adapter
  - OAuth + API clients + vendor-specific repo; produces queue jobs.
- Package: cis-inventory-cqrs (optional)
  - Inventory commands/guards/projections; tests and fixtures.
- Package: cis-ops-tools
  - Prefix migrator, schema migrator, reconciler/schedulers, operational scripts.
- Package: cis-admin-widgets (external UI glue)
  - Widgets JSON provider and links (lives in cis-admin repo currently).

## Directory Guidance
- Keep adapter-specific UI under modules/ (e.g., modules/lightspeed/Ui/dashboard.php).
- Consider new folders for clarity:
  - modules/core/, modules/webhooks/, modules/inventory/, modules/ops/
- Public endpoints remain in https://staff.vapeshed.co.nz/assets/services/queue/public/

## Migration Plan (Zero-Downtime)
1) Create cishub_* views (already implemented)
   - POST https://staff.vapeshed.co.nz/assets/services/queue/public/prefix.migrate.php
   - Optional: {"dry_run": true}
2) Validate
   - GET https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php
   - Confirm cishub_* exists and webhook freshness.
3) Adopt new prefix in new code
   - Use cishub_* in queries and migrations going forward.
4) Optional Physical Rename (Maintenance Window)
   - RENAME TABLE ls_* → cishub_*; create ls_* compatibility views back to cishub_* for legacy reads.
   - Provide rollback script (RENAME back) and rebuild views accordingly.

## Success Criteria
- Namespaces and DB prefix consistently applied.
- All public docs index the modules page.
- Sanity checks pass with cishub_* present and healthy.

