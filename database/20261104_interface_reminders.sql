-- Additive update. Select your existing Fundwise database. Keep foreign key checks enabled.
CREATE TABLE IF NOT EXISTS fw_notice_preferences (
 user_id INT NOT NULL PRIMARY KEY, debt_lead_days SMALLINT NOT NULL DEFAULT 3,
 budget_popup TINYINT NOT NULL DEFAULT 1, debt_popup TINYINT NOT NULL DEFAULT 0,
 overdue_repeat VARCHAR(10) NOT NULL DEFAULT 'none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_notice_events (
 user_id INT NOT NULL, event_key VARCHAR(191) NOT NULL, notification_id INT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fw_personal_reminders (
 reminder_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,
 title VARCHAR(120) NOT NULL,note VARCHAR(255) NOT NULL DEFAULT '',remind_at DATETIME NOT NULL,
 show_popup TINYINT NOT NULL DEFAULT 0,status VARCHAR(10) NOT NULL DEFAULT 'active',
 delivered_at DATETIME DEFAULT NULL,notification_id INT DEFAULT NULL,revision INT NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX due_reminders(user_id,status,delivered_at,remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
