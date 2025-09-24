-- Migration: Align delivery_mode/status enums and create ls_id_sequences + triggers
-- Author: Copilot
-- Date: 2025-09-23

-- Ensure ls_id_sequences exists
CREATE TABLE IF NOT EXISTS `ls_id_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seq_type` varchar(64) NOT NULL,
  `period` varchar(10) NOT NULL,
  `next_value` bigint(20) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_seq` (`seq_type`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create/replace transfers public id trigger
DROP TRIGGER IF EXISTS `bi_transfers_public_id`;
DELIMITER $$
CREATE TRIGGER `bi_transfers_public_id` BEFORE INSERT ON `transfers` FOR EACH ROW
BEGIN
  DECLARE v_period VARCHAR(10); DECLARE v_assigned BIGINT UNSIGNED; DECLARE v_seq BIGINT UNSIGNED; DECLARE v_num BIGINT UNSIGNED; DECLARE v_cd INT; DECLARE v_type VARCHAR(32); DECLARE v_code VARCHAR(6);
  IF NEW.public_id IS NULL OR NEW.public_id = '' THEN
    SET v_period = DATE_FORMAT(NOW(), '%Y%m');
    SET v_type = UPPER(IFNULL(NEW.type, 'GENERIC'));
    SET v_code = UPPER(REPLACE(SUBSTRING(v_type,1,3), ' ', ''));
    INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES ('transfer', v_period, 2)
      ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW();
    SET v_seq = LAST_INSERT_ID();
    SET v_assigned = IF(v_seq > 1, v_seq - 1, 1);
    SET v_num = CAST(CONCAT(v_period, LPAD(v_assigned,6,'0')) AS UNSIGNED);
    SET v_cd = (98 - MOD(v_num, 97)); IF v_cd = 98 THEN SET v_cd = 0; END IF;
    SET NEW.public_id = CONCAT('TR-', v_code, '-', v_period, '-', LPAD(v_assigned,6,'0'), '-', LPAD(v_cd,2,'0'));
  END IF;
END$$
DELIMITER ;

-- No-op: shipment enums already aligned in app code; this file centralizes DDL for audit
