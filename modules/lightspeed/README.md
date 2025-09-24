# Lightspeed X-Series Pipeline (CIS Module)

Production-grade job-list/API/webhook/monitoring for Lightspeed X-Series.

Docs: https://staff.vapeshed.co.nz

## Config Keys (configuration table)
- vend_domain_prefix
- vend_client_id, vend_client_secret
- vend_auth_code (initial)
- vend_access_token, vend_refresh_token, vend_expires_at (managed)
- vend.timeout_seconds (default 30)
- vend.retry_attempts (default 3)
- vend.rate_limit_requests (reserved)
- LS_WEBHOOKS_ENABLED (true/false)
- vend_webhook_secret (header X-Lightspeed-Signature)

## SQL
Run `modules/lightspeed/sql/schema.sql` to create tables.

## Runner (CLI)
php modules/lightspeed/Sync/Runner.php --limit=200 --type=pull_inventory

## Internal APIs
- POST cis/ls/job.php {type,payload,idempotency_key?}
- POST cis/ls/manual.refresh_token.php
- GET cis/ls/health.php

## Webhook
- modules/lightspeed/Webhooks/Receiver.php (enable with LS_WEBHOOKS_ENABLED)

## UI
- modules/lightspeed/Ui/dashboard.php

## Smoke
1) Insert config (vend_domain_prefix, client id/secret, auth code)
2) Call manual.refresh_token to obtain tokens
3) POST cis/ls/job.php with type=push_inventory_adjustment and payload
4) Run Runner and verify jobs transitioned and logs appended
