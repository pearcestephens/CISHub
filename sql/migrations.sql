-- Forward-only migrations for /queue module
-- Creates ls_sync_cursors and ensures core tables exist.

CREATE TABLE IF NOT EXISTS ls_sync_cursors (
  entity VARCHAR(64) NOT NULL,
  `cursor` VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure base tables mentioned in repo exist (no-op if already created)
CREATE TABLE IF NOT EXISTS ls_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(64) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  payload JSON NULL,
  idempotency_key VARCHAR(128) NULL,
  status ENUM('pending','working','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  leased_until DATETIME NULL,
  heartbeat_at DATETIME NULL,
  next_run_at DATETIME NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_idem (idempotency_key),
  KEY idx_status_type (status, type, updated_at),
  KEY idx_status_next (status, next_run_at),
  KEY idx_leased (status, leased_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Forward-only: add priority column/index to existing ls_jobs if missing
-- Column/index ensurement handled in PHP migrator for wider MariaDB compatibility

CREATE TABLE IF NOT EXISTS ls_jobs_dlq (
  id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  type VARCHAR(64) NOT NULL,
  payload JSON NULL,
  idempotency_key VARCHAR(128) NULL,
  fail_code VARCHAR(64) NULL,
  fail_message TEXT NULL,
  attempts INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Rate limit counters (per route/IP, per-minute window)
CREATE TABLE IF NOT EXISTS ls_rate_limits (
  rl_key VARCHAR(191) NOT NULL,
  window_start DATETIME NOT NULL,
  counter INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (rl_key),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_job_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id BIGINT UNSIGNED NULL,
  level VARCHAR(16) NOT NULL,
  message VARCHAR(255) NOT NULL,
  correlation_id VARCHAR(64) NULL,
  context JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job (job_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhook tables (align with global schema; forward-only, rerunnable)
CREATE TABLE IF NOT EXISTS webhook_subscriptions (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  source_system VARCHAR(32) NOT NULL DEFAULT 'vend' COMMENT 'Source system (vend, website, wholesale)',
  event_type VARCHAR(64) NOT NULL COMMENT 'Event type pattern (sale.*, product.update, etc.)',
  endpoint_url VARCHAR(512) NOT NULL COMMENT 'Our webhook endpoint URL',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether subscription is active',
  secret_key VARCHAR(256) DEFAULT NULL COMMENT 'Secret key for signature verification',
  external_subscription_id VARCHAR(128) DEFAULT NULL COMMENT 'ID in external system',
  created_in_external_system TIMESTAMP NULL DEFAULT NULL COMMENT 'When created in external system',
  last_verified TIMESTAMP NULL DEFAULT NULL COMMENT 'Last time subscription was verified',
  events_received_today INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Events received today',
  events_received_total BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total events received',
  last_event_received TIMESTAMP NULL DEFAULT NULL COMMENT 'When last event was received',
  health_status ENUM('healthy','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
  health_message TEXT DEFAULT NULL COMMENT 'Health status description',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY unique_subscription (source_system,event_type,endpoint_url),
  KEY idx_active_subscriptions (is_active,source_system),
  KEY idx_health_monitoring (health_status,last_event_received)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook subscription management and health monitoring';

CREATE TABLE IF NOT EXISTS webhook_health (
  id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  check_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  webhook_type VARCHAR(64) NOT NULL,
  health_status ENUM('healthy','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
  response_time_ms INT(10) UNSIGNED DEFAULT NULL COMMENT 'Processing response time in milliseconds',
  error_rate_pct DECIMAL(5,2) DEFAULT NULL COMMENT 'Error percentage over last hour',
  throughput_per_hour INT(10) UNSIGNED DEFAULT NULL COMMENT 'Webhooks processed per hour',
  last_successful_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful webhook processing',
  consecutive_failures INT(10) UNSIGNED NOT NULL DEFAULT 0,
  health_details LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detailed health check information',
  PRIMARY KEY (id),
  KEY idx_type_time (webhook_type,check_time),
  KEY idx_health_status (health_status,check_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook system health monitoring';

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id VARCHAR(128) NOT NULL COMMENT 'Unique webhook identifier',
  webhook_type VARCHAR(64) NOT NULL COMMENT 'Type of webhook (sale.update, product.update, etc.)',
  payload LONGTEXT NOT NULL COMMENT 'Processed webhook payload',
  raw_payload LONGTEXT NOT NULL COMMENT 'Original raw webhook data',
  source_ip VARCHAR(45) DEFAULT NULL COMMENT 'IP address of webhook sender',
  user_agent TEXT DEFAULT NULL COMMENT 'User agent of webhook sender',
  headers LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'HTTP headers from webhook request',
  status ENUM('received','processing','completed','failed','replayed') NOT NULL DEFAULT 'received',
  received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() COMMENT 'When webhook was received',
  processed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When webhook processing completed',
  processing_attempts INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of processing attempts',
  processing_result LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Result data from successful processing',
  error_message TEXT DEFAULT NULL COMMENT 'Error message if processing failed',
  queue_job_id VARCHAR(64) DEFAULT NULL COMMENT 'Associated queue job ID',
  replayed_from VARCHAR(128) DEFAULT NULL COMMENT 'Original webhook_id if this is a replay',
  replay_reason VARCHAR(255) DEFAULT NULL COMMENT 'Reason for manual replay',
  replayed_by_user INT(10) UNSIGNED DEFAULT NULL COMMENT 'User who triggered replay',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY webhook_id (webhook_id),
  KEY idx_webhook_type_status (webhook_type,status),
  KEY idx_received_at (received_at),
  KEY idx_status_processing (status,processing_attempts),
  KEY idx_source_tracking (source_ip,received_at),
  KEY idx_queue_job (queue_job_id),
  KEY idx_replay_chain (replayed_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook events audit trail and replay system';

CREATE TABLE IF NOT EXISTS webhook_stats (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  webhook_type VARCHAR(64) NOT NULL,
  metric_name VARCHAR(64) NOT NULL COMMENT 'received_count, processed_count, failed_count, avg_processing_time',
  metric_value DECIMAL(10,2) NOT NULL,
  time_period ENUM('1min','5min','15min','1hour','1day') NOT NULL DEFAULT '5min',
  PRIMARY KEY (id),
  UNIQUE KEY unique_metric_period (recorded_at,webhook_type,metric_name,time_period),
  KEY idx_time_lookup (recorded_at,webhook_type,metric_name),
  KEY idx_webhook_period (webhook_type,time_period,recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Webhook processing performance metrics';

-- === Extensions: Suppliers, Purchase Orders (PO), Stocktakes, Returns ===
-- Forward-only and idempotent (CREATE TABLE IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS ls_suppliers (
  supplier_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vend_supplier_id VARCHAR(64) DEFAULT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (supplier_id),
  UNIQUE KEY uniq_vend_supplier (vend_supplier_id),
  KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Suppliers referenced by POs and returns';

CREATE TABLE IF NOT EXISTS ls_purchase_orders (
  po_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vend_po_id VARCHAR(64) DEFAULT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  outlet_id VARCHAR(64) NOT NULL,
  status ENUM('draft','submitted','received','cancelled','partial') NOT NULL DEFAULT 'draft',
  ordered_at DATETIME NULL,
  received_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (po_id),
  UNIQUE KEY uniq_vend_po (vend_po_id),
  KEY idx_supplier (supplier_id),
  KEY idx_outlet_status (outlet_id,status),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES ls_suppliers(supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PO headers for vendor ordering';

CREATE TABLE IF NOT EXISTS ls_purchase_order_lines (
  line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id BIGINT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  qty_ordered INT NOT NULL,
  qty_received INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(12,4) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (line_id),
  UNIQUE KEY uniq_po_product (po_id,product_id),
  KEY idx_po (po_id),
  CONSTRAINT fk_pol_po FOREIGN KEY (po_id) REFERENCES ls_purchase_orders(po_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='PO line items';

CREATE TABLE IF NOT EXISTS ls_stocktakes (
  stocktake_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  outlet_id VARCHAR(64) NOT NULL,
  initiated_by INT NULL,
  status ENUM('open','counting','reconciling','applied','cancelled') NOT NULL DEFAULT 'open',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (stocktake_id),
  KEY idx_outlet_status (outlet_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Outlet stocktake sessions and status';

CREATE TABLE IF NOT EXISTS ls_stocktake_lines (
  line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  stocktake_id BIGINT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  counted_qty INT NOT NULL,
  system_qty INT NOT NULL,
  variance INT NOT NULL,
  PRIMARY KEY (line_id),
  UNIQUE KEY uniq_stocktake_product (stocktake_id,product_id),
  KEY idx_stocktake (stocktake_id),
  CONSTRAINT fk_stl_st FOREIGN KEY (stocktake_id) REFERENCES ls_stocktakes(stocktake_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-product stocktake counts and variance';

CREATE TABLE IF NOT EXISTS ls_returns (
  return_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope ENUM('supplier','outlet') NOT NULL DEFAULT 'outlet',
  supplier_id BIGINT UNSIGNED NULL,
  outlet_from VARCHAR(64) NULL,
  outlet_to VARCHAR(64) NULL,
  reason VARCHAR(255) NULL,
  status ENUM('draft','submitted','in_transit','received','cancelled') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (return_id),
  KEY idx_scope_status (scope,status),
  KEY idx_supplier (supplier_id),
  KEY idx_outlets (outlet_from,outlet_to),
  CONSTRAINT fk_ret_supplier FOREIGN KEY (supplier_id) REFERENCES ls_suppliers(supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Return headers: to supplier or inter-outlet returns';

CREATE TABLE IF NOT EXISTS ls_return_lines (
  line_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  qty INT NOT NULL,
  reason VARCHAR(255) NULL,
  PRIMARY KEY (line_id),
  UNIQUE KEY uniq_return_product (return_id,product_id),
  KEY idx_return (return_id),
  CONSTRAINT fk_rtl_ret FOREIGN KEY (return_id) REFERENCES ls_returns(return_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Return line items';

-- Friendly views with vend_* prefix for clarity (non-breaking aliases)
CREATE OR REPLACE VIEW vend_suppliers AS
  SELECT supplier_id, vend_supplier_id, name, is_active, created_at, updated_at FROM ls_suppliers;

CREATE OR REPLACE VIEW vend_purchase_orders AS
  SELECT po_id, vend_po_id, supplier_id, outlet_id, status, ordered_at, received_at, created_at, updated_at FROM ls_purchase_orders;

CREATE OR REPLACE VIEW vend_purchase_order_lines AS
  SELECT line_id, po_id, product_id, qty_ordered, qty_received, cost_price, created_at, updated_at FROM ls_purchase_order_lines;

CREATE OR REPLACE VIEW vend_stocktakes AS
  SELECT stocktake_id, outlet_id, initiated_by, status, started_at, completed_at, created_at, updated_at FROM ls_stocktakes;

CREATE OR REPLACE VIEW vend_stocktake_lines AS
  SELECT line_id, stocktake_id, product_id, counted_qty, system_qty, variance FROM ls_stocktake_lines;

CREATE OR REPLACE VIEW vend_returns AS
  SELECT return_id, scope, supplier_id, outlet_from, outlet_to, reason, status, created_at, updated_at FROM ls_returns;

CREATE OR REPLACE VIEW vend_return_lines AS
  SELECT line_id, return_id, product_id, qty, reason FROM ls_return_lines;
