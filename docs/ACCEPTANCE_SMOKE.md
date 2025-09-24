# Acceptance Smoke — /assets/services/queue

Date: 2025-09-18

0) Prechecks
- DB backup ready, PHP ≥ 8.1, DB_* reachable

1) Migrate (idempotent)
```
php bin/migrate.php
```

2) Token refresh
```
curl -s -X POST \
  -H "Authorization: Bearer $ADMIN_BEARER_TOKEN" \
  https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php | jq .
```
Expect `{ "ok": true, "expires_in": >3000 }`.

3) Schedule one of each pull (idempotent)
```
php bin/schedule-pulls.php --once
```

4) Process
```
php bin/run-jobs.php --limit=200
```
Expect `{ "ok": true, "processed": >0 }` and rows in mirror tables.

5) Webhook smoke
```
TS=$(date +%s)
BODY='{"type":"inventory.updated","data":{"product_id":123,"outlet_id":1}}'
SIG=$(php -r 'echo base64_encode(hash_hmac("sha256", getenv("TS")."." . getenv("BODY"), getenv("SHARED"), true));' TS="$TS" BODY="$BODY" SHARED="$VEND_WEBHOOK_SECRET")
curl -s -X POST https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php \
  -H "X-LS-Timestamp: $TS" -H "X-LS-Signature: $SIG" \
  -H "Content-Type: application/json" -d "$BODY" | jq .
php bin/run-jobs.php --type=pull_inventory --limit=50
```

6) Reaper
Start a run and kill it mid-flight, then:
```
php bin/reap-stale.php
```
Expect `{ "reaped": >=1 }`.

7) Dashboard & Metrics
- Visit https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
- Check https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php for new gauges.

8) Idempotency
- Create two identical push_inventory_adjustment jobs with same idempotency_key.
- Run jobs twice; confirm only one external write and 409 treated as success.

Cron (Cloudways)
```
* * * * * php /home/<app>/assets/services/queue/bin/run-jobs.php --limit=200 >> /dev/null 2>&1
*/2 * * * * php /home/<app>/assets/services/queue/bin/reap-stale.php >> /dev/null 2>&1
* * * * * php /home/<app>/assets/services/queue/bin/schedule-pulls.php >> /dev/null 2>&1
```

Config audit (configuration table)
- Required: vend_domain_prefix, vend_client_id, vend_client_secret, and either vend_refresh_token or vend_auth_code.
- Managed: vend_access_token, vend_token_expires_at.
- Pipeline: vend.retry_attempts (3), vend.timeout_seconds (30), vend_queue_runtime_business (120), vend_queue_kill_switch (false), LS_WEBHOOKS_ENABLED (true), vend_webhook_secret, ADMIN_BEARER_TOKEN.

Definition of Done
- All legacy paths 410 with JSON and link to new endpoints; no duplicate logic executes.
- Health + metrics expose DLQ, cursor age, rows/min; dashboard shows the same.
- Webhook signature works; inventory.updated → job added → processed.
- Reaper demonstrably returns expired leases; backoff + jitter visible.
- Smoke doc runs clean on a fresh env.
- Cron lines documented & enabled; kill switch honored.
- Config key unification complete (vend_token_expires_at canonical).
