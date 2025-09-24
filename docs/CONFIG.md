---
title: Configuration — Queue & Webhooks
description: All configuration labels, defaults, and security/rotation practices for the Lightspeed queue module.
---

# Configuration

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/CONFIG.md

Values are stored in the `configuration` table and accessed via `src/Config.php`.

## Core Keys

- vend_domain_prefix — Lightspeed domain prefix.
- vend_client_id, vend_client_secret — OAuth client creds.
- vend_auth_code — Initial authorization code (used once to mint tokens).
- vend_access_token — Access token (rotating).
- vend_refresh_token — Refresh token (long-lived; rotate if compromised).
- vend_token_expires_at — UTC expiry timestamp for access token.
- vend.timeout_seconds — Default HTTP timeout (int, default 30).
- vend.retry_attempts — HTTP retry attempts (int, default 3).
- LS_WEBHOOKS_ENABLED — "true"/"false"; gates webhook handling.
- ADMIN_BEARER_TOKEN — Token for admin endpoints (rotate periodically).

## Optional Keys

- queue.max_concurrency.create_consignment — default cap per type.
- queue.max_concurrency.push_inventory_adjustment — default cap per type.
- queue.paused.create_consignment — "true" to pause.
- queue.paused.push_inventory_adjustment — "true" to pause.

## Security

- Never store secrets in code; all secrets live in `configuration`.
- Rotate `ADMIN_BEARER_TOKEN` on role changes; communicate new token securely.
- Restrict admin endpoints behind VPN/MFA where possible.
- Store `vend_client_secret` and tokens with least-privilege access; audit reads.

## Rotation Procedures

Access/Refresh tokens:
1) POST https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php (admin bearer)
2) Verify token_expires_in on https://staff.vapeshed.co.nz/assets/services/queue/public/health.php

Admin bearer token:
1) Generate a strong random value and update in configuration.
2) Test an admin endpoint (e.g., https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php) with the new token.
3) Revoke the old token and communicate rotation time.
