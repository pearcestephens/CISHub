---
title: Security & Threat Model — Queue
description: Authentication, authorization, webhook HMAC, secrets management, logging, and hardening guidance.
---

# Security & Threat Model

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/SECURITY.md

## Authentication / Authorization

- Admin endpoints require `Authorization: Bearer <ADMIN_BEARER_TOKEN>`.
- Token stored in `configuration`; rotate every 90 days or on personnel change.
- Consider IP allowlists and VPN egress for admin access.

## Webhook Integrity

- HMAC-SHA256 signature validation using `vend_webhook_secret`.
- Timestamp skew window: ±5 minutes; reject outside this range.
- Store raw headers, body, and signature for audit in `webhook_events`.

## Secrets Management

- All secrets are stored in DB (configuration table); no secrets in code.
- Least-privilege database user for the app; restrict GRANTs.
- Avoid printing secrets in logs; mask tokens if needed.

## Logging & Audit

- Application errors: https://staff.vapeshed.co.nz/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log
- Webhook failures are recorded with reason in `webhook_events` and counters in `webhook_stats`.
- Metric endpoints expose operational state without secrets.

## Hardening Tips

- Set appropriate CORS/CSRF headers on admin endpoints if accessed via browser UI.
- Rate-limit admin POST endpoints to mitigate abuse.
- Monitor `vend_circuit_breaker_open` and pause queue under incident.
- Keep PHP and dependencies updated; use utf8mb4 and strict PDO error mode.
