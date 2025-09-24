---
title: Queue Service Architecture (Lightspeed X-Series)
description: Components, schema, flows, priority, metrics, and security posture for the production queue and webhooks service.
---

# Queue Service — Architecture

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/ARCHITECTURE.md

## Overview

The Queue service provides a durable work-item pipeline for Lightspeed X-Series integrations:

- Job ingestion (admin-only HTTP and internal producers)
- Priority-aware claiming with fairness and backoff
- Workers (CLI) that process jobs and record metrics
- Webhook receiver (HMAC-verified) with admin tools
- Health, metrics, dashboard, and DLQ redrive

Public endpoints live under:
- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
- Status: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
- Admin controls (pause/resume/concurrency/migrate/DLQ): see https://staff.vapeshed.co.nz/assets/services/queue/GO_LIVE_RUNBOOK.md

## Code Map

- src/PdoConnection.php — PDO singleton, tx helpers, deadlock retries
- src/Config.php — K/V config via `configuration` table
- src/WorkItem.php — DTO used by workers
- src/PdoWorkItemRepository.php — enqueue/claim/heartbeat/complete/fail (DLQ)
- src/Lightspeed/*.php — HTTP clients, OAuth, Inventory/Products/Consignments APIs, Web handlers
- modules/lightspeed/* — Feature modules (Api, Core, Sync, Ui, Webhooks, sql)
- public/*.php — Thin HTTP adapters calling Web handlers

## Database Schema (primary tables)

- ls_jobs(id, type, status, priority, attempts, max_attempts, payload_json, created_at, updated_at, next_run_at, claimed_by)
- ls_job_logs(job_id, level, message, created_at)
- ls_jobs_dlq(id, type, attempts, last_error, moved_at, payload_json)
- ls_rate_limits(key, window_start, count)
- ls_sync_cursors(stream, cursor, updated_at)
- webhook_subscriptions(id, topic, active, last_event_received, events_received_today, events_received_total)
- webhook_events(id, topic, status, error_message, received_at, payload_json, headers_json, signature)
- webhook_health(id, status, updated_at, last_error)
- webhook_stats(window_start_minute, received_count, failed_count)
- transfer_validation_cache(id, key_hash, is_valid, details_json, expires_at) — owned by Stock-Transfer; only cleaned here via admin tool

Important indexes:
- ls_jobs: (status, priority, next_run_at), (updated_at), PK(id)
- ls_jobs_dlq: (moved_at), PK(id)
- webhook_events: (received_at), (topic)

## Priority & Claiming

- Priority range: 1 (highest) .. 9 (lowest). Enforced on enqueue.
- Claim ordering: `ORDER BY priority ASC, updated_at ASC` with `FOR UPDATE SKIP LOCKED` to avoid thundering herds.
- Concurrency caps per type are enforced at claim time.
- Backoff on failure is exponential with jitter; after `max_attempts`, items move to `ls_jobs_dlq`.

## Processing Flow

1) Producers call https://staff.vapeshed.co.nz/assets/services/queue/public/job.php (admin bearer) or insert via repository.
2) Worker runs: `php queue/bin/run-jobs.php --type=<type> --limit=N` under cron.
3) Worker claims batch via repository with SKIP LOCKED and begins processing.
4) On success: `complete()` marks done; on retryable failure: `fail()` schedules next run; on terminal failure: moves to DLQ.
5) Metrics and logs are emitted; dashboard and /metrics reflect state.

## Metrics (Prometheus text)

Exposed at https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php. Families include:
- ls_jobs_pending_total, ls_jobs_working_total, ls_jobs_failed_total
- ls_queue_paused{type}
- ls_job_processing_seconds_bucket/sum/count
- vend_http_latency_bucket_ms/sum/count by method and status
- webhook_events_total, webhook_failed_total

## Security & RBAC

- Mutating endpoints require `Authorization: Bearer <ADMIN_BEARER_TOKEN>` set in configuration label `ADMIN_BEARER_TOKEN`.
- Webhook receiver requires valid HMAC (vend_webhook_secret) and timestamp skew within ±5 minutes.
- No secrets in code. All credentials pulled from `configuration` table via src/Config.php.

## Configuration Labels (selected)

- vend_domain_prefix, vend_client_id, vend_client_secret, vend_refresh_token, vend_token_expires_at
- vend.timeout_seconds, vend.retry_attempts
- ADMIN_BEARER_TOKEN
- LS_WEBHOOKS_ENABLED (true/false)

## Failure Modes & Resilience

- Deadlocks retried in tx helper (bounded attempts).
- Circuit breaker for Vend API opens on repeated failures; health reflects status.
- DLQ ensures no hot loops; redrive tool re-enqueues safely and idempotently.

## Dependencies

- PHP 8+, PDO MySQL, curl/openssl for HTTPS to Lightspeed.
- MariaDB/MySQL with utf8mb4, `sql_safe_updates=0` recommended for maintenance tools.

## Observability

- Health JSON: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php

---

Change log lives at: https://staff.vapeshed.co.nz/assets/services/queue/GO_LIVE_RUNBOOK.md
