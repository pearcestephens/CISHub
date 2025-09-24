---
title: Worker CLI — run-jobs.php
description: Usage, flags, exit codes, concurrency, and cron recommendations for the queue worker.
---

# Worker CLI — run-jobs.php

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/WORKER.md

## Usage

php queue/bin/run-jobs.php [--type=<job_type>] [--limit=<N>] [--timeout=<sec>] [--once]

Flags:
- --type: Filter to a single job type (default: all types)
- --limit: Max items to claim per run (default: 200)
- --timeout: Max seconds per run (soft cap)
- --once: Process a single batch and exit (useful for ad-hoc runs)

## Concurrency

Max concurrency per type is enforced server-side via configuration labels `queue.max_concurrency.<type>` and may be updated by POST to https://staff.vapeshed.co.nz/assets/services/queue/public/queue.concurrency.update.php

## Exit Codes

0 — Success, no unhandled errors
2 — Partial work completed; some items deferred or retried
3 — Fatal error; check logs at https://staff.vapeshed.co.nz/logs/apache_phpstack-129337-518184.cloudwaysapps.com.error.log

## Cron Examples (Cloudways)

* * * * * php /home/<app>/queue/bin/run-jobs.php --limit=200 >> /dev/null 2>&1
* * * * * php /home/<app>/queue/bin/run-jobs.php --type=create_consignment --limit=200 >> /dev/null 2>&1
* * * * * php /home/<app>/queue/bin/run-jobs.php --type=push_inventory_adjustment --limit=200 >> /dev/null 2>&1

## Observability

- Health: https://staff.vapeshed.co.nz/assets/services/queue/public/health.php
- Metrics: https://staff.vapeshed.co.nz/assets/services/queue/public/metrics.php
- Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
