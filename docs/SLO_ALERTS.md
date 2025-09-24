---
title: SLOs & Alerts — Queue & Webhooks
description: Targets, thresholds, and alert actions aligned with metrics and runbooks.
---

# SLOs & Alerts

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/SLO_ALERTS.md

## Targets

- Vend API error rate < 1% over 1h; P95 latency < 800ms.
- Oldest pending age < 120s during steady state.
- DLQ growth rate ~ 0; redrive within same business day.
- Webhook failure rate < 0.5% per hour.

## Alerts

1) VendErrorRateHigh
   - Expression: sum by() (increase(vend_http_latency_count{status=~"4..|5..|429"}[15m])) / sum by() (increase(vend_http_latency_count[15m])) > 0.02
   - For: 10m
   - Action: Pause non-critical job types; investigate status codes; check circuit breaker.

2) OldestPendingSustained
   - Expression: ls_oldest_pending_age_seconds > 600 for 5m
   - Action: Increase concurrency temporarily; check DB health and downstream Vend latency.

3) QueuePausedTooLong
   - Expression: ls_queue_paused == 1 for 30m
   - Action: Resume if incident resolved; validate token and Vend health.

4) WebhookFailureSpike
   - Expression: increase(webhook_failed_total[15m]) > 25
   - Action: Validate `vend_webhook_secret`, clock skew, and network health.

## Runbook References

- Operations: https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md
- Go-Live Runbook: https://staff.vapeshed.co.nz/assets/services/queue/GO_LIVE_RUNBOOK.md
