-- Select your EXISTING database first. Additive migration; keep FK checks enabled.
-- Does not replace existing debts, payments, transactions or credentials.
CREATE TABLE IF NOT EXISTS fw_loan_installments (
 installment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL, debt_id INT NOT NULL, sequence_no INT NOT NULL,
 due_on DATE NOT NULL, principal DECIMAL(12,2) NOT NULL,
 interest DECIMAL(12,2) NOT NULL DEFAULT 0, fees DECIMAL(12,2) NOT NULL DEFAULT 0,
 UNIQUE KEY debt_sequence(debt_id,sequence_no), INDEX owner_due(user_id,due_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_loan_allocations (
 payment_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 debt_id INT NOT NULL, installment_id INT NOT NULL,
 INDEX installment_owner(installment_id,user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_loan_notice_settings (
 debt_id INT NOT NULL PRIMARY KEY, user_id INT NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 1, lead_days INT NOT NULL DEFAULT 3,
 show_popup TINYINT NOT NULL DEFAULT 0, overdue_repeat VARCHAR(10) NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
