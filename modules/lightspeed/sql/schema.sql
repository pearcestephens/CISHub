-- https://staff.vapeshed.co.nz
CREATE TABLE IF NOT EXISTS ls_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  type ENUM(
    'pull_products','pull_inventory','pull_consignments',
    'create_consignment','add_consignment_products','update_consignment',
    'push_product_update','push_inventory_adjustment'
  ) NOT NULL,
  payload JSON NOT NULL,
  idempotency_key VARCHAR(128) NULL,
  status ENUM('pending','working','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY uk_jobs_idemp (idempotency_key),
  KEY idx_jobs_status_type (status,type,updated_at),
  KEY idx_jobs_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_job_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT NOT NULL,
  level ENUM('info','warn','error') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_job (job_id),
  CONSTRAINT fk_job_logs_job FOREIGN KEY (job_id) REFERENCES ls_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_products (
  product_id BIGINT PRIMARY KEY,
  sku VARCHAR(64),
  name VARCHAR(255),
  price_incl_tax DECIMAL(12,2),
  supply_price DECIMAL(12,4),
  brand_id BIGINT,
  supplier_id BIGINT,
  is_active TINYINT(1),
  updated_at DATETIME,
  KEY idx_ls_products_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_inventory (
  product_id BIGINT NOT NULL,
  outlet_id BIGINT NOT NULL,
  current_amount INT NOT NULL,
  updated_at DATETIME,
  PRIMARY KEY (product_id, outlet_id),
  KEY idx_ls_inventory_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_consignments (
  consignment_id BIGINT PRIMARY KEY,
  type ENUM('SUPPLIER','OUTLET','STOCKTAKE','RETURN') NOT NULL,
  status VARCHAR(32) NOT NULL,
  outlet_id BIGINT,
  supplier_id BIGINT,
  name VARCHAR(255),
  metadata JSON,
  updated_at DATETIME,
  KEY idx_ls_consignments_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ls_consignment_products (
  consignment_id BIGINT NOT NULL,
  product_id BIGINT NOT NULL,
  count INT NOT NULL,
  received INT DEFAULT 0,
  cost DECIMAL(12,4),
  metadata JSON,
  PRIMARY KEY (consignment_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
