-- Fundwise Financial Intelligence v2. Select your EXISTING database before importing.
-- Run after financial_intelligence.sql and 20261102_upgrade.sql from the earlier update.
-- Repeatable, additive migration: no DROP, DELETE, UPDATE, ALTER or foreign-key changes.
CREATE TABLE IF NOT EXISTS fw_lab_details (
 experiment_id INT NOT NULL PRIMARY KEY,
 user_id INT NOT NULL,
 behavior_plan VARCHAR(255) NOT NULL DEFAULT '',
 baseline_start DATE NOT NULL,
 baseline_end DATE NOT NULL,
 baseline_count INT DEFAULT NULL,
 baseline_confirmed TINYINT NOT NULL DEFAULT 0,
 practical_choice VARCHAR(12) NOT NULL DEFAULT 'unset',
 review_notes VARCHAR(255) NOT NULL DEFAULT '',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_lab_days (
 experiment_id INT NOT NULL,
 user_id INT NOT NULL,
 work_date DATE NOT NULL,
 recording_kind VARCHAR(12) NOT NULL,
 recorded_amount DECIMAL(14,2) NOT NULL,
 recorded_count INT NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(experiment_id,work_date), INDEX(user_id,work_date)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_purchase_scenarios (
 scenario_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 purchase_title VARCHAR(80) NOT NULL,
 purchase_date DATE NOT NULL,
 currency_code CHAR(3) NOT NULL,
 expected_amount DECIMAL(12,2) NOT NULL,
 inputs_json LONGTEXT NOT NULL,
 result_json LONGTEXT NOT NULL,
 outcome VARCHAR(12) NOT NULL DEFAULT 'considering',
 actual_amount DECIMAL(12,2) DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(user_id,scenario_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_work_session_details (
 session_id INT NOT NULL PRIMARY KEY,
 user_id INT NOT NULL,
 work_source VARCHAR(100) NOT NULL DEFAULT '',
 extra_minutes SMALLINT NOT NULL DEFAULT 0,
 payment_status VARCHAR(12) NOT NULL DEFAULT 'unknown',
 received_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 received_date DATE DEFAULT NULL,
 income_transaction_id INT DEFAULT NULL,
 record_version INT NOT NULL DEFAULT 1,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY fw_work_income_once (income_transaction_id), INDEX(user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS fw_work_expense_kinds (
 expense_id BIGINT NOT NULL PRIMARY KEY,
 user_id INT NOT NULL,
 cost_kind VARCHAR(20) NOT NULL DEFAULT 'other',
 INDEX(user_id)
) ENGINE=InnoDB;
