# Legacy Shims — Queue Service

These legacy files now return HTTP 410 and point to the canonical /queue surfaces.

Web entrypoints → use:
- `public/dashboard.php`
- `public/health.php`
- `public/metrics.php`
- `public/job.php`
- `public/manual.refresh_token.php`
- `public/webhook.php`

Shimmed files:
- `queue.php` → `/assets/services/queue/public/dashboard.php`
- `worker.php` → `/assets/services/queue/public/dashboard.php`
- `worker_new.php` → CLI shim → `php bin/run-jobs.php --limit=200 --type=pull_products`
- `reconciler.php` → `/assets/services/queue/public/dashboard.php`
- `reconciler_new.php` → CLI shim → `php bin/reap-stale.php`
- `queue_dashboard_complete.php` → `/assets/services/queue/public/dashboard.php`
- `modules/lightspeed/**` → `/assets/services/queue/public/*`
- `cis/ls/**` → `/assets/services/queue/public/*`

CLI canonical commands:
- Run jobs: `php bin/run-jobs.php --limit=200`
- Reap stale: `php bin/reap-stale.php`
- Schedule pulls: `php bin/schedule-pulls.php`

Source of truth: https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
