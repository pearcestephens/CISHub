---
title: Incident Response — Queue & Webhooks
description: Triage steps, rollback, escalation paths, and communication templates for incidents.
---

# Incident Response Playbook

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/INCIDENTS.md

## 1. Triage

- Confirm scope on Dashboard and Metrics
- Check Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Identify whether issue is: Vend API errors, DB contention, Token expiry, Webhook signature errors, or code regression.

## 2. Containment

- Pause affected job types: https://staff.vapeshed.co.nz/assets/services/queue/public/queue.pause.php
- If Vend unstable: open circuit breaker (or keep paused) and reduce concurrency caps.

## 3. Diagnosis

- Inspect logs: https://staff.vapeshed.co.nz/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log
- Review DLQ last_error messages.
- Check vend_http status distribution and P95 latency.

## 4. Remediation

- Fix configuration (token refresh, keys) and test.
- Redrive DLQ safely via https://staff.vapeshed.co.nz/assets/services/queue/public/dlq.redrive.php
- Resume job types once downstream is stable.

## 5. Recovery

- Monitor KPIs for 60 minutes: error rate, oldest pending age, DLQ growth.
- Document learnings in postmortem (include metrics snapshots and timelines).

## 6. Escalation

- Business owner: CIS Team Lead
- Vendor escalation: Lightspeed support (include request IDs and timestamps)

## 7. Communication

- Status update template:
  - Issue: [summary]
  - Impact: [systems/business]
  - Actions: [pause/resume, redrive, fixes]
  - ETA next update: [time]

## References

- Operations: https://staff.vapeshed.co.nz/assets/services/queue/docs/OPERATIONS.md
- SLOs & Alerts: https://staff.vapeshed.co.nz/assets/services/queue/docs/SLO_ALERTS.md
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/docs/METRICS.md
