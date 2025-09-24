---
title: Queue Service Operations Guide
description: Day-2 operations for the Lightspeed queue and webhooks modules: controls, cron, DLQ, RBAC, troubleshooting.
---

# Operations — Queue & Webhooks

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md

## Quick Links

- Dashboard (HTML): https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
- Health (JSON): https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics (Prometheus): https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Status (JSON): https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php
- DLQ Redrive (POST): https://staff.vapeshed.co.nz/assets/services/queue/public/dlq.redrive.php
- Pause: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.pause.php
- Resume: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.resume.php
- Update Concurrency: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.concurrency.update.php
- Webhook Receiver: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php
- Webhook Admin: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.subscriptions.php

Admin endpoints require `Authorization: Bearer <token>`. Token is stored in configuration as `ADMIN_BEARER_TOKEN`.

## Routine Operations

1) Check Health: ensure `{ ok: true }` and `token_expires_in > 0`.
2) Review Dashboard SLOs: Vend API latency and error%, Queue P95 and oldest pending age.
3) Inspect DLQ trend: keep flat; investigate spikes via job logs and error messages.

## Cron

Cloudways crontab example (adjust path to app name):

* * * * * php /home/<app>/queue/bin/run-jobs.php --limit=200 >> /dev/null 2>&1

Multiple lines can be used for different types:

* * * * * php /home/<app>/queue/bin/run-jobs.php --type=create_consignment --limit=200 >> /dev/null 2>&1
* * * * * php /home/<app>/queue/bin/run-jobs.php --type=push_inventory_adjustment --limit=200 >> /dev/null 2>&1

## DLQ Redrive

Use the Dashboard or POST to https://staff.vapeshed.co.nz/assets/services/queue/public/dlq.redrive.php

Payload examples:

- Oldest N: `{ "mode": "oldest", "limit": 50 }`
- Selected IDs: `{ "mode": "ids", "ids": [101,102,103] }`

Safety:
- Idempotent upsert; schedules `next_run_at` in the future; decrements attempts.

## RBAC & Security

- All mutating endpoints require the admin bearer token; rotate via configuration and communicate to operators.
- Webhooks require a valid `vend_webhook_secret`; timestamp skew ±5 minutes.
- No PII in metrics; webhook payloads stored securely with headers and signature for audit.

## Troubleshooting

Logs:
- PHP errors: https://staff.vapeshed.co.nz/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log
- Rotated: https://staff.vapeshed.co.nz/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log.1

Common issues:

1) Circuit breaker open
   - Symptom: Health shows vendor_circuit_breaker_open=1; API error% spikes.
   - Action: Pause queue, investigate vend API status codes in metrics, reduce concurrency temporarily.

2) Token expired
   - Symptom: token_expires_in <= 0.
   - Action: Manually refresh via https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php (admin bearer), verify success in Health.

3) Webhook signature invalid
   - Symptom: webhook_events rows with status=failed and error_message about signature.
   - Action: Verify `vend_webhook_secret` matches Lightspeed portal; check clock skew.

4) DLQ growing
   - Symptom: DLQ trend increases.
   - Action: Inspect `last_error` in `ls_jobs_dlq`; fix root cause; redrive oldest N.

5) Missing legacy table `transfer_queue`
   - Symptom: Heartbeat warning in health or logs.
   - Action: Ensure compatibility shim view/table exists or disable legacy heartbeat; see https://staff.vapeshed.co.nz/assets/services/queue/TEST_PLAN.md step 6.

## Change Management

- Always run https://staff.vapeshed.co.nz/assets/services/queue/public/verify.php after deploying schema changes.
- Use https://staff.vapeshed.co.nz/assets/services/queue/public/migrate.php when provided; otherwise apply `sql/schema.sql` idempotent migrations.

## KPIs

- Vend API error% < 1%; P95 < 800ms
- Oldest pending age < 120s steady state
- DLQ flat or descending over 24h
