---
title: Schema Dictionary — Queue & Webhooks
description: Tables, columns, indexes, and retention policies.
---

# Schema Dictionary

Docs root: https://staff.vapeshed.co.nz/assets/services/queue/docs/SCHEMA.md

Note: Canonical schema is in https://staff.vapeshed.co.nz/assets/services/queue/sql/schema.sql

## Naming Conventions

- Historical prefix `ls_` stands for Lightspeed and is used by base physical tables.
- For readability in application code and analytics, friendly SQL views with `vend_` prefix are created as non-breaking aliases (e.g., `vend_suppliers` maps to `ls_suppliers`).
- Prefer querying `vend_*` views in dashboards/analytics and keep writes against the `ls_*` base tables (or through the application layer) to preserve referential integrity and constraints.
- Rationale: “vend” is explicit about Lightspeed Vend X-Series and easier to recognize across the stack.

## ls_jobs

- id BIGINT PK
- type VARCHAR(64) — job type
- status ENUM('pending','working','done','failed')
- priority TINYINT — 1..9 (1 highest)
- attempts INT — current attempts
- max_attempts INT — default 5
- payload_json JSON
- idempotency_key VARCHAR(190) UNIQUE NULL
- claimed_by VARCHAR(64) NULL
- created_at, updated_at, next_run_at DATETIME

Indexes: (status, priority, next_run_at), (updated_at)
Retention: Rows move to DLQ or remain until cleaned by policy.

## ls_job_logs

- id BIGINT PK, job_id BIGINT FK->ls_jobs.id
- level VARCHAR(16)
- message TEXT
- created_at DATETIME

Index: (job_id, created_at)
Retention: 30 days recommended.

## ls_jobs_dlq

- id BIGINT PK, type VARCHAR(64)
- attempts INT
- last_error TEXT
- payload_json JSON
- moved_at DATETIME

Index: (moved_at)
Retention: 90 days recommended or until redrive.

## ls_rate_limits

- key VARCHAR(128) PK
- window_start DATETIME
- count INT

Index: (window_start)

## ls_sync_cursors

- stream VARCHAR(64) PK
- cursor VARCHAR(128)
- updated_at DATETIME

## Webhooks Tables

### webhook_subscriptions
- id BIGINT PK
- topic VARCHAR(128)
- active TINYINT(1)
- last_event_received DATETIME
- events_received_today INT
- events_received_total BIGINT

### webhook_events
- id BIGINT PK
- topic VARCHAR(128)
- status ENUM('received','failed','processed')
- error_message TEXT NULL
- received_at DATETIME
- payload_json JSON
- headers_json JSON
- signature VARCHAR(128)

Indexes: (received_at), (topic)
Retention: 30-90 days depending on volume/compliance.

### webhook_health
- id INT PK (singleton)
- status ENUM('ok','warning','critical')
- updated_at DATETIME
- last_error TEXT NULL

### webhook_stats
- window_start_minute DATETIME PK
- received_count INT
- failed_count INT

## transfer_validation_cache (owned by Stock-Transfer)
- id BIGINT PK
- key_hash VARBINARY(32)
- is_valid TINYINT(1)
- details_json JSON
- expires_at DATETIME INDEX

Retention: Expire on `expires_at`; cleanup via https://staff.vapeshed.co.nz/assets/services/queue/public/transfer.cleanup_cache.php

## New Domain Tables (Suppliers, POs, Stocktakes, Returns)

These tables are created by forward-only migrations in https://staff.vapeshed.co.nz/assets/services/queue/sql/migrations.sql to support upcoming features across suppliers, purchase orders, stocktakes, and returns. Keys and indexes are chosen for common lookups and safe joins. All tables use utf8mb4.

### ls_suppliers
- supplier_id BIGINT PK
- vend_supplier_id VARCHAR(64) UNIQUE NULL — upstream Lightspeed supplier id
- name VARCHAR(255) NOT NULL
- is_active TINYINT(1) DEFAULT 1
- created_at, updated_at TIMESTAMP

Indexes: UNIQUE(vend_supplier_id), (is_active)

### ls_purchase_orders
- po_id BIGINT PK
- vend_po_id VARCHAR(64) UNIQUE NULL
- supplier_id BIGINT FK->ls_suppliers.supplier_id
- outlet_id VARCHAR(64) NOT NULL
- status ENUM('draft','submitted','received','cancelled','partial') DEFAULT 'draft'
- ordered_at, received_at DATETIME NULL
- created_at, updated_at TIMESTAMP

Indexes: UNIQUE(vend_po_id), (supplier_id), (outlet_id,status)

### ls_purchase_order_lines
- line_id BIGINT PK
- po_id BIGINT FK->ls_purchase_orders.po_id ON DELETE CASCADE
- product_id VARCHAR(64) NOT NULL
- qty_ordered INT NOT NULL
- qty_received INT DEFAULT 0
- cost_price DECIMAL(12,4) NULL
- created_at, updated_at TIMESTAMP

Indexes: UNIQUE(po_id, product_id), (po_id)

### ls_stocktakes
- stocktake_id BIGINT PK
- outlet_id VARCHAR(64) NOT NULL
- initiated_by INT NULL (staff_id)
- status ENUM('open','counting','reconciling','applied','cancelled') DEFAULT 'open'
- started_at, completed_at DATETIME NULL
- created_at, updated_at TIMESTAMP

Indexes: (outlet_id, status)

### ls_stocktake_lines
- line_id BIGINT PK
- stocktake_id BIGINT FK->ls_stocktakes.stocktake_id ON DELETE CASCADE
- product_id VARCHAR(64) NOT NULL
- counted_qty INT NOT NULL
- system_qty INT NOT NULL
- variance INT NOT NULL

Indexes: UNIQUE(stocktake_id, product_id), (stocktake_id)

### ls_returns
- return_id BIGINT PK
- scope ENUM('supplier','outlet') DEFAULT 'outlet'
- supplier_id BIGINT NULL FK->ls_suppliers.supplier_id
- outlet_from VARCHAR(64) NULL
- outlet_to VARCHAR(64) NULL
- reason VARCHAR(255) NULL
- status ENUM('draft','submitted','in_transit','received','cancelled') DEFAULT 'draft'
- created_at, updated_at TIMESTAMP

Indexes: (scope, status), (supplier_id), (outlet_from, outlet_to)

### ls_return_lines
- line_id BIGINT PK
- return_id BIGINT FK->ls_returns.return_id ON DELETE CASCADE
- product_id VARCHAR(64) NOT NULL
- qty INT NOT NULL
- reason VARCHAR(255) NULL

Indexes: UNIQUE(return_id, product_id), (return_id)

Notes:
- These tables are queue-local and intended to coordinate with Lightspeed endpoints (consignments, POs, returns, stocktakes). They do not duplicate CIS master data and can be mapped via vend_* ids when available.
- Any enum broadening in existing transfer tables will be handled by a controlled migration given vendor constraints on ENUM alterations in MariaDB.
