-- https://staff.vapeshed.co.nz
-- Re-runnable schema; includes DLQ and metrics helpful indexes.
CREATE TABLE IF NOT EXISTS ls_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'pull_products','pull_inventory','pull_consignments',
    'create_consignment','add_consignment_products','update_consignment',
    'push_product_update','push_inventory_adjustment'
  ) NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
  payload JSON NOT NULL,
  idempotency_key VARCHAR(191) NULL,
  status ENUM('pending','working','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 6,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  leased_until DATETIME NULL,
  heartbeat_at DATETIME NULL,
  next_run_at DATETIME NULL,
  UNIQUE KEY uk_jobs_idemp (idempotency_key),
  KEY idx_jobs_status_type (status,type,updated_at),
  KEY idx_jobs_status_priority (status,priority,updated_at),
  KEY idx_jobs_next (status,next_run_at),
  KEY idx_jobs_lease (status,leased_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_job_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  level ENUM('info','warn','error') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  correlation_id VARCHAR(191) NULL,
  KEY idx_job (job_id),
  CONSTRAINT fk_job_logs_job FOREIGN KEY (job_id) REFERENCES ls_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_jobs_dlq (
  id BIGINT UNSIGNED PRIMARY KEY,
  created_at DATETIME NOT NULL,
  type VARCHAR(80) NOT NULL,
  payload JSON NOT NULL,
  idempotency_key VARCHAR(191) NULL,
  fail_code VARCHAR(64) NULL,
  fail_message TEXT NULL,
  attempts INT UNSIGNED NOT NULL,
  moved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dlq_moved (moved_at),
  KEY idx_dlq_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhook subscription management and health (mirrors migrations)
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
