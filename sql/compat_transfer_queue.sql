-- Optional compatibility shim for legacy heartbeat checks.
-- Creates an empty `transfer_queue` to satisfy legacy monitors.
-- Safe to run multiple times. Remove after heartbeat scripts are updated.

CREATE TABLE IF NOT EXISTS transfer_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
