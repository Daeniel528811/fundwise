-- Fundwise clean installation schema, recovered November 5, 2026.
-- Structure only: no accounts, passwords, receipts or financial records.
-- Select an EMPTY database first. Keep foreign key checks enabled.
-- For an existing site, use the documented additive migrations instead.
SET NAMES utf8mb4;

CREATE TABLE `fw_account_security` (
  `user_id` int(11) NOT NULL,
  `credential_version` int(11) NOT NULL DEFAULT 0,
  `recovery_hash` char(64) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_audit_log` (
  `audit_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_name` varchar(80) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `user_id` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_budget_alerts` (
  `user_id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `threshold_value` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`budget_id`,`period_start`,`period_end`,`threshold_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_challenge_goals` (
  `challenge_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `goal_id` int(11) NOT NULL,
  `behavior` varchar(160) NOT NULL,
  PRIMARY KEY (`challenge_id`),
  KEY `owner_goal` (`user_id`,`goal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_debt_alerts` (
  `debt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `due_on` date NOT NULL,
  `alert_kind` varchar(10) NOT NULL,
  PRIMARY KEY (`debt_id`,`due_on`,`alert_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_debt_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `debt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `paid_on` date NOT NULL,
  `principal` decimal(12,2) NOT NULL,
  `interest` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fees` decimal(12,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `owner_debt` (`user_id`,`debt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_debts` (
  `debt_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `lender` varchar(100) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `opening_principal` decimal(12,2) NOT NULL,
  `opened_on` date NOT NULL,
  `next_due` date DEFAULT NULL,
  `scheduled_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `version` int(11) NOT NULL DEFAULT 1,
  `archived` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`debt_id`),
  KEY `owner` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_goal_archives` (
  `goal_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`goal_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_lab_days` (
  `experiment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `recording_kind` varchar(12) NOT NULL,
  `recorded_amount` decimal(14,2) NOT NULL,
  `recorded_count` int(11) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`experiment_id`,`work_date`),
  KEY `user_id` (`user_id`,`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_lab_details` (
  `experiment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `behavior_plan` varchar(255) NOT NULL DEFAULT '',
  `baseline_start` date NOT NULL,
  `baseline_end` date NOT NULL,
  `baseline_count` int(11) DEFAULT NULL,
  `baseline_confirmed` tinyint(4) NOT NULL DEFAULT 0,
  `practical_choice` varchar(12) NOT NULL DEFAULT 'unset',
  `review_notes` varchar(255) NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`experiment_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_lab_scopes` (
  `experiment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`experiment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_loan_allocations` (
  `payment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `debt_id` int(11) NOT NULL,
  `installment_id` int(11) NOT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `installment_owner` (`installment_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_loan_installments` (
  `installment_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `debt_id` int(11) NOT NULL,
  `sequence_no` int(11) NOT NULL,
  `due_on` date NOT NULL,
  `principal` decimal(12,2) NOT NULL,
  `interest` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fees` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`installment_id`),
  UNIQUE KEY `debt_sequence` (`debt_id`,`sequence_no`),
  KEY `owner_due` (`user_id`,`due_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_loan_notice_settings` (
  `debt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `lead_days` int(11) NOT NULL DEFAULT 3,
  `show_popup` tinyint(4) NOT NULL DEFAULT 0,
  `overdue_repeat` varchar(10) NOT NULL DEFAULT 'none',
  PRIMARY KEY (`debt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_login_limits` (
  `subject_key` char(64) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`subject_key`),
  KEY `window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_notice_events` (
  `user_id` int(11) NOT NULL,
  `event_key` varchar(191) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_notice_preferences` (
  `user_id` int(11) NOT NULL,
  `debt_lead_days` smallint(6) NOT NULL DEFAULT 3,
  `budget_popup` tinyint(4) NOT NULL DEFAULT 1,
  `debt_popup` tinyint(4) NOT NULL DEFAULT 0,
  `overdue_repeat` varchar(10) NOT NULL DEFAULT 'none',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_personal_reminders` (
  `reminder_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  `remind_at` datetime NOT NULL,
  `show_popup` tinyint(4) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `delivered_at` datetime DEFAULT NULL,
  `notification_id` int(11) DEFAULT NULL,
  `revision` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`reminder_id`),
  KEY `due_reminders` (`user_id`,`status`,`delivered_at`,`remind_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_purchase_scenarios` (
  `scenario_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `purchase_title` varchar(80) NOT NULL,
  `purchase_date` date NOT NULL,
  `currency_code` char(3) NOT NULL,
  `expected_amount` decimal(12,2) NOT NULL,
  `inputs_json` longtext NOT NULL,
  `result_json` longtext NOT NULL,
  `outcome` varchar(12) NOT NULL DEFAULT 'considering',
  `actual_amount` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`scenario_id`),
  KEY `user_id` (`user_id`,`scenario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_record_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` bigint(20) NOT NULL,
  `event_name` varchar(40) NOT NULL,
  `before_data` longtext DEFAULT NULL,
  `after_data` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `user_id` (`user_id`,`entity_type`,`entity_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_requests` (
  `user_id` int(11) NOT NULL,
  `request_key` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`request_key`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_spending_labels` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(60) NOT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `owner_label` (`user_id`,`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_work_cost_links` (
  `expense_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_id` int(11) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  PRIMARY KEY (`expense_id`),
  UNIQUE KEY `transaction_id` (`transaction_id`),
  KEY `owner_session` (`user_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fw_work_expense_kinds` (
  `expense_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `cost_kind` varchar(20) NOT NULL DEFAULT 'other',
  PRIMARY KEY (`expense_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_work_session_details` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `work_source` varchar(100) NOT NULL DEFAULT '',
  `extra_minutes` smallint(6) NOT NULL DEFAULT 0,
  `payment_status` varchar(12) NOT NULL DEFAULT 'unknown',
  `received_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `received_date` date DEFAULT NULL,
  `income_transaction_id` int(11) DEFAULT NULL,
  `record_version` int(11) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `fw_work_income_once` (`income_transaction_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `fw_work_times` (
  `session_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime NOT NULL,
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`session_id`),
  KEY `user_time` (`user_id`,`started_at`,`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `money_lab_experiments` (
  `experiment_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `experiment_title` varchar(120) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `duration_days` smallint(6) NOT NULL,
  `reduction_percent` tinyint(4) NOT NULL,
  `baseline_amount` decimal(12,2) NOT NULL,
  `baseline_source` varchar(12) NOT NULL DEFAULT 'history',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`experiment_id`),
  KEY `money_lab_owner_date_idx` (`user_id`,`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `preferred_currency` varchar(10) DEFAULT 'PHP',
  `theme` varchar(10) DEFAULT 'light',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_session_expenses` (
  `expense_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `expense_description` varchar(160) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`expense_id`),
  KEY `session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `work_sessions` (
  `session_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `session_date` date NOT NULL,
  `hours_worked` decimal(5,2) NOT NULL,
  `gross_earnings` decimal(12,2) NOT NULL,
  `work_expenses` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`session_id`),
  KEY `work_sessions_owner_date_idx` (`user_id`,`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `category_type` enum('income','expense') NOT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`category_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings_allocations` (
  `allocation_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `allocation_name` varchar(100) NOT NULL,
  `allocation_percentage` decimal(5,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`allocation_id`),
  KEY `fk_allocation_user` (`user_id`),
  CONSTRAINT `fk_allocation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings_challenges` (
  `challenge_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `challenge_name` varchar(100) NOT NULL,
  `target_amount` decimal(12,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `current_amount` decimal(12,2) DEFAULT 0.00,
  `status` enum('scheduled','active','completed','failed') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`challenge_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `savings_challenges_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings_goals` (
  `goal_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `goal_name` varchar(100) NOT NULL,
  `target_amount` decimal(12,2) NOT NULL,
  `target_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`goal_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `savings_goals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `transaction_type` enum('income','expense') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `receipt_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`transaction_id`),
  KEY `user_id` (`user_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `budgets` (
  `budget_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `budget_limit` decimal(12,2) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`budget_id`),
  KEY `user_id` (`user_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `budgets_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings_contributions` (
  `contribution_id` int(11) NOT NULL AUTO_INCREMENT,
  `goal_id` int(11) NOT NULL,
  `contribution_type` enum('deposit','withdrawal') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `contribution_date` date NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contribution_id`),
  KEY `goal_id` (`goal_id`),
  CONSTRAINT `savings_contributions_ibfk_1` FOREIGN KEY (`goal_id`) REFERENCES `savings_goals` (`goal_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

