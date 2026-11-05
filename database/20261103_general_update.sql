-- Additive update. Select the existing Fundwise database before importing.
-- Keep foreign key checks enabled. Existing financial rows are not replaced.
CREATE TABLE IF NOT EXISTS fw_work_times (
 session_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 started_at DATETIME NOT NULL, ended_at DATETIME NOT NULL, break_minutes INT NOT NULL DEFAULT 0,
 INDEX user_time(user_id,started_at,ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_spending_labels (
 transaction_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 label VARCHAR(60) NOT NULL, INDEX owner_label(user_id,label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_lab_scopes (
 experiment_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 label VARCHAR(60) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_work_cost_links (
 expense_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 session_id INT NOT NULL, transaction_id INT NOT NULL UNIQUE,
 INDEX owner_session(user_id,session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_debts (
 debt_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 lender VARCHAR(100) NOT NULL, description VARCHAR(255) NOT NULL DEFAULT '',
 opening_principal DECIMAL(12,2) NOT NULL, opened_on DATE NOT NULL,
 next_due DATE DEFAULT NULL, scheduled_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
 version INT NOT NULL DEFAULT 1, archived TINYINT NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX owner(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_debt_payments (
 payment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, debt_id INT NOT NULL, user_id INT NOT NULL,
 paid_on DATE NOT NULL, principal DECIMAL(12,2) NOT NULL, interest DECIMAL(12,2) NOT NULL DEFAULT 0,
 fees DECIMAL(12,2) NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX owner_debt(user_id,debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_debt_alerts (
 debt_id INT NOT NULL, user_id INT NOT NULL, due_on DATE NOT NULL,
 alert_kind VARCHAR(10) NOT NULL, PRIMARY KEY(debt_id,due_on,alert_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_challenge_goals (
 challenge_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL, goal_id INT NOT NULL,
 behavior VARCHAR(160) NOT NULL, INDEX owner_goal(user_id,goal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
