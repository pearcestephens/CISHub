# Queue — Lightspeed X-Series Work-Item Pipeline

Production-ready job list + APIs + webhooks + dashboard.

Docs: https://staff.vapeshed.co.nz

## Configure
Set these labels in `configuration`:
- vend_domain_prefix
- vend_client_id, vend_client_secret, vend_auth_code (initial)
- vend_access_token, vend_refresh_token, vend_token_expires_at (managed)
- vend.timeout_seconds (30), vend.retry_attempts (3)
- LS_WEBHOOKS_ENABLED (true/false)
- ADMIN_BEARER_TOKEN (optional; required for POST /job and /manual.refresh_token)

## Schema
Import once:
mysql -uUSER -pPASS DBNAME < queue/sql/schema.sql

## Force token refresh
curl -s -X POST https://your-domain/queue/public/manual.refresh_token.php \
  -H 'Authorization: Bearer <ADMIN_BEARER_TOKEN>' | jq .

## Add job (inventory adjustment)
curl -s -X POST https://your-domain/queue/public/job.php \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer <ADMIN_BEARER_TOKEN>' \
  -d '{"type":"push_inventory_adjustment","payload":{"product_id":123,"outlet_id":1,"count":-2,"note":"Adj"},"idempotency_key":"inv:123:1:-2"}' | jq .

## Run jobs
php queue/bin/run-jobs.php --type=push_inventory_adjustment --limit=200

## Monitor
Open /queue/public/dashboard.php and /queue/public/health.php
Prometheus metrics: /queue/public/metrics.php

DLQ: rows that exceed max_attempts move to `ls_jobs_dlq`.

## Cron (Cloudways)
* * * * * php /home/<app>/queue/bin/run-jobs.php --limit=200 >> /dev/null 2>&1
