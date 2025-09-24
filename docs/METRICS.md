---
title: Metrics Catalog — Queue & Webhooks
description: Available Prometheus metrics, labels, units, and alert suggestions.
---

# Metrics Catalog

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md

Endpoint: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php (text/plain)

## Queue Metrics

- ls_jobs_pending_total{type}
- ls_jobs_working_total{type}
- ls_jobs_failed_total{type}
- ls_queue_paused{type} — 0/1
- ls_job_processing_seconds_bucket{type,le}
- ls_job_processing_seconds_sum{type}
- ls_job_processing_seconds_count{type}
- ls_oldest_pending_age_seconds{type}

## Vend API Metrics

- vend_http_latency_bucket_ms{method,status,le}
- vend_http_latency_sum_ms{method,status}
- vend_http_latency_count{method,status}
- vend_circuit_breaker_open — 0/1

## Webhook Metrics

- webhook_events_total{topic,status}
- webhook_failed_total{topic}
- webhook_receive_latency_ms_bucket{topic,le}

## Example Scrape Output

ls_jobs_pending_total{type="create_consignment"} 12
ls_queue_paused{type="create_consignment"} 0
vend_circuit_breaker_open 0

## Alert Suggestions

- Alert: QueuePausedTooLong
  - Condition: ls_queue_paused{type} == 1 for 10m
- Alert: OldestPendingHigh
  - Condition: ls_oldest_pending_age_seconds > 600 for 5m
- Alert: VendErrorRate
  - Condition: sum by() (increase(vend_http_latency_count{status=~"4..|5..|429"}[5m])) / sum by() (increase(vend_http_latency_count[5m])) > 0.02
- Alert: WebhookFailures
  - Condition: increase(webhook_failed_total[10m]) > 10
