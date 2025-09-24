# BUGLOG — Lightspeed Queue Module

## [BBB-0001] Heartbeat failing on legacy table `transfer_queue`
- Severity: S2 (High) — Monitoring noise and red herring for on-call
- Confidence: 95%
- Blast Radius: Heartbeat report shows EMERGENCY though queue module is healthy; could mask real issues
- Root Cause: A global heartbeat script checks for `jcepnzzkmj.transfer_queue`, which belongs to a legacy stock transfer engine, not this module. Our queue storage is `ls_jobs`.
- Evidence:
  - https://staff.vapeshed.co.nz/logs/heartbeat.log lines show: "FAILED - Table 'jcepnzzkmj.transfer_queue' doesn't exist"
  - Code search under `assets/services/queue` finds no `transfer_queue` reference.
- Repro: Tail `logs/heartbeat.log` and run heartbeat task.
- Fix Proposal (two safe paths):
  1) Adjust heartbeat to check `ls_jobs` existence instead of `transfer_queue` for this service.
  2) Provide a compatibility shim table `transfer_queue` (empty) to satisfy heartbeat until it is updated; read-only, no triggers.
- Patch/SQL Reference:
  - See `sql/compat_transfer_queue.sql` for optional shim creation.
- Post-fix Verification Plan:
  - Re-run heartbeat; ensure status moves from EMERGENCY → OK/WARNING based on actual checks, and no table-missing errors remain.

## [BBB-0002] Duplicate schema definitions
- Severity: S4 (Low)
- Confidence: 85%
- Root Cause: Both `sql/schema.sql` and `sql/migrations.sql` define overlapping tables (`ls_jobs`, `ls_job_logs`, `ls_jobs_dlq`, webhook tables). While harmless (IF NOT EXISTS), it increases drift risk.
- Fix Proposal: Standardize on `migrations.sql` as forward-only and keep `schema.sql` as a full bootstrap; document precedence.

## [BBB-0003] Webhook failure path headers must be JSON-valid
- Severity: S3 (Medium)
- Confidence: 90%
- Root Cause: In early versions, headers were inserted as NULL causing json_valid constraint violations. Current code uses JSON_OBJECT(); verified fixed.
- Evidence: `Queue\\Lightspeed\\Web::webhook()` INSERT uses JSON_OBJECT().
- Post-fix: No action needed; note for regression tests.
