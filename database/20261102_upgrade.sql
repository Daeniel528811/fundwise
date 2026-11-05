-- Fundwise reliability update. Import into the EXISTING InfinityFree database.
-- Additive only: does not drop, reset, or relabel existing records.
CREATE TABLE IF NOT EXISTS fw_account_security (
 user_id INT NOT NULL PRIMARY KEY,
 credential_version INT NOT NULL DEFAULT 0,
 recovery_hash CHAR(64) DEFAULT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_login_limits (
 subject_key CHAR(64) NOT NULL PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 window_start DATETIME NOT NULL,
 INDEX(window_start)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_requests (
 user_id INT NOT NULL,
 request_key CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,request_key), INDEX(created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_audit_log (
 audit_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 event_name VARCHAR(80) NOT NULL,
 entity_id BIGINT DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_budget_alerts (
 user_id INT NOT NULL,
 budget_id INT NOT NULL,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 threshold_value INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,budget_id,period_start,period_end,threshold_value)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_goal_archives (
 goal_id INT NOT NULL PRIMARY KEY,
 user_id INT NOT NULL,
 archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS work_session_expenses (
 expense_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 session_id INT NOT NULL,
 expense_description VARCHAR(160) NOT NULL,
 amount DECIMAL(12,2) NOT NULL,
 INDEX(session_id)
) ENGINE=InnoDB;
-- No conversion/backfill is necessary: old work_expenses totals and notes remain.
-- Existing sessions without expense items are labeled as legacy totals in history.

ALTER TABLE savings_challenges MODIFY status ENUM('scheduled','active','completed','failed') DEFAULT 'active';
CREATE TABLE IF NOT EXISTS fw_record_history (
 history_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id BIGINT NOT NULL,
 event_name VARCHAR(40) NOT NULL,
 before_data LONGTEXT DEFAULT NULL,
 after_data LONGTEXT DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id,entity_type,entity_id,created_at)
) ENGINE=InnoDB;
