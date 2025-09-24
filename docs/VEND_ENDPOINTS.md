---
title: Lightspeed (Vend X-Series) Endpoints — This Release
description: Enumeration of upstream endpoints used, how to access them, counts, auth, idempotency, and rate limits.
---

# Lightspeed (Vend X-Series) — Endpoints Used

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/VEND_ENDPOINTS.md

## Summary (count)

This release integrates with 6 upstream endpoint families (writes happen only via 2.0 consignments and 2.1 products):

1) OAuth Token
   - POST https://{vend_domain_prefix}.retail.lightspeed.app/api/1.0/token

2) Consignments v2.0
   - POST /api/2.0/consignments — create
   - PUT  /api/2.0/consignments/{id} — full update (status/products)
   - PATCH /api/2.0/consignments/{id} — partial update (status/products)
  - POST /api/2.0/consignments/{id}/products — add products to consignment

3) Inventory v2.0 (read/list only)
  - GET endpoints for listing inventory records
  - Note: v2.0 does not support write adjustments; updates must use Products v2.1 (updateproduct)

4) Products v2.1
   - PUT /api/2.1/products/{id} — product updates (optional path for data sync)

5) Pagination helper (read endpoints)
   - GET /api/2.1/products?page=..|after=..|page_info=..
   - GET variant forms depending on Lightspeed pagination flavor

6) Webhooks (admin portal-configured)
   - Incoming only (we do not call an LS API for webhooks configuration in this release)

Total unique HTTP methods/paths invoked by our service in this release: 6

## How we access them

- Base URL: https://x-series-api.lightspeedhq.com (overrideable via `vend.api_base` in configuration)
- Auth: OAuth 2.0 Bearer tokens stored in configuration (vend_access_token; auto-refreshed via vend_refresh_token)
- User agent/headers: Authorization: Bearer <token>, Accept: application/json; Content-Type: application/json for JSON bodies
- Idempotency headers: X-Request-Id, Idempotency-Key forwarded when available (create/update/patch consignments; inventory adjusts)
- Retries: Transient errors (429/5xx) are retried with exponential backoff and jitter; circuit breaker prevents storming
- Timeouts: vend.timeout_seconds (default 30s)

## Endpoints in detail

1) OAuth Token (v1.0)
- POST https://{vend_domain_prefix}.retail.lightspeed.app/api/1.0/token
- Body (x-www-form-urlencoded): grant_type=authorization_code|refresh_token, code|refresh_token, client_id, client_secret
- Response: { access_token, refresh_token, expires_in }
- Config keys: vend_client_id, vend_client_secret, vend_auth_code, vend_refresh_token, vend_access_token, vend_token_expires_at

2) Consignments (v2.0)
- POST /api/2.0/consignments
  - Purpose: create transfer consignments (type TRANSFER)
  - Idempotency: X-Request-Id + Idempotency-Key used to avoid dupes; 409 considered safe-duplicate
- PUT /api/2.0/consignments/{id}
  - Purpose: update status/products (e.g., SENT/RECEIVED quantities)
  - Idempotency: X-Request-Id/Idempotency-Key forwarded if provided
- PATCH /api/2.0/consignments/{id}
  - Purpose: partial edit lines or cancel (status=CANCELLED)
  - Idempotency: same as above
 - POST /api/2.0/consignments/{id}/products
   - Purpose: add one or more products (lines) to an existing consignment
   - Idempotency: X-Request-Id/Idempotency-Key forwarded if provided

3) Inventory (v2.0)
- Read-only inventory listing endpoints (see Lightspeed docs). No write adjustments supported in this version.

4) Products (v2.1)
- PUT /api/2.1/products/{id}
  - Purpose: product field updates (used optionally by sync modules)
  - Idempotency: X-Request-Id forwarded if provided

5) Pagination patterns (read utilities)
- Paginate via page, after, or page_info depending on LS response; helper normalizes common patterns.

## Accessing Vend APIs via our service

We abstract these endpoints behind the queue workers and do not expose raw vendor calls publicly. To trigger them safely:

- Enqueue a job (admin): https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
  - Types: create_consignment, update_consignment, cancel_consignment, edit_consignment_lines
  - Provide `idempotency_key` to de-duplicate vendor calls
- Worker executes the appropriate LS call using our `HttpClient` with retries and metrics.
- Monitor via metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php (vend_http_* families)

## Rate limits and circuit breaker

- 429 Too Many Requests triggers exponential backoff retry
- We record per-minute counters (requests_total, latency buckets) and compute avg + percentiles in metrics
- Circuit breaker trips after a failure threshold within a window and cools down before allowing new calls (config key `vend.cb` state)

## Security and idempotency

- All secrets remain in configuration; never hard-coded
- Use `idempotency_key` per transfer or logical action to ensure vendor-side de-duplication
- On 409 Conflict from LS after a duplicate create, we treat as success where appropriate

## Examples

Create consignment (via queue):
POST https://staff.vapeshed.co.nz/assets/services/queue/public/job.php
Body:
{
  "type": "create_consignment",
  "payload": {
    "source_outlet_id": 1,
    "dest_outlet_id": 2,
    "lines": [{"product_id": 12345, "qty": 4}],
    "idempotency_key": "transfer:TR-001"
  }
}

Cancel consignment:
POST job with type "cancel_consignment" and payload { consignment_id, idempotency_key? }

Inventory update:
Use job type "push_product_update" mapping to PUT /api/2.1/products/{id} and include the correct inventory-related fields per updateproduct. The previous idea of POST /api/2.0/inventory for adjustments is not supported by the vendor API and is deprecated.
