# Database dictionary

Generated from the clean structure-only schema. Keys are actual column keys; application relationships can exist without physical constraints. No row data is included.

## fw_account_security

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | - |
| `credential_version` | `int(11)` | NO | - | - |
| `recovery_hash` | `char(64)` | YES | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## fw_audit_log

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `audit_id` | `bigint(20)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `event_name` | `varchar(80)` | NO | - | - |
| `entity_id` | `bigint(20)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_budget_alerts

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | - |
| `budget_id` | `int(11)` | NO | PRI | - |
| `period_start` | `date` | NO | PRI | - |
| `period_end` | `date` | NO | PRI | - |
| `threshold_value` | `int(11)` | NO | PRI | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_challenge_goals

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `challenge_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `goal_id` | `int(11)` | NO | - | - |
| `behavior` | `varchar(160)` | NO | - | - |

## fw_debt_alerts

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `debt_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | - | - |
| `due_on` | `date` | NO | PRI | - |
| `alert_kind` | `varchar(10)` | NO | PRI | - |

## fw_debt_payments

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `payment_id` | `int(11)` | NO | PRI | auto_increment |
| `debt_id` | `int(11)` | NO | - | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `paid_on` | `date` | NO | - | - |
| `principal` | `decimal(12,2)` | NO | - | - |
| `interest` | `decimal(12,2)` | NO | - | - |
| `fees` | `decimal(12,2)` | NO | - | - |
| `note` | `varchar(255)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_debts

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `debt_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `lender` | `varchar(100)` | NO | - | - |
| `description` | `varchar(255)` | NO | - | - |
| `opening_principal` | `decimal(12,2)` | NO | - | - |
| `opened_on` | `date` | NO | - | - |
| `next_due` | `date` | YES | - | - |
| `scheduled_amount` | `decimal(12,2)` | NO | - | - |
| `version` | `int(11)` | NO | - | - |
| `archived` | `tinyint(4)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_goal_archives

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `goal_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `archived_at` | `timestamp` | NO | - | - |

## fw_lab_days

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `experiment_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `work_date` | `date` | NO | PRI | - |
| `recording_kind` | `varchar(12)` | NO | - | - |
| `recorded_amount` | `decimal(14,2)` | NO | - | - |
| `recorded_count` | `int(11)` | NO | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## fw_lab_details

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `experiment_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `behavior_plan` | `varchar(255)` | NO | - | - |
| `baseline_start` | `date` | NO | - | - |
| `baseline_end` | `date` | NO | - | - |
| `baseline_count` | `int(11)` | YES | - | - |
| `baseline_confirmed` | `tinyint(4)` | NO | - | - |
| `practical_choice` | `varchar(12)` | NO | - | - |
| `review_notes` | `varchar(255)` | NO | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## fw_lab_scopes

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `experiment_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | - | - |
| `label` | `varchar(60)` | NO | - | - |

## fw_loan_allocations

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `payment_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | - | - |
| `debt_id` | `int(11)` | NO | - | - |
| `installment_id` | `int(11)` | NO | MUL | - |

## fw_loan_installments

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `installment_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `debt_id` | `int(11)` | NO | MUL | - |
| `sequence_no` | `int(11)` | NO | - | - |
| `due_on` | `date` | NO | - | - |
| `principal` | `decimal(12,2)` | NO | - | - |
| `interest` | `decimal(12,2)` | NO | - | - |
| `fees` | `decimal(12,2)` | NO | - | - |

## fw_loan_notice_settings

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `debt_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | - | - |
| `enabled` | `tinyint(4)` | NO | - | - |
| `lead_days` | `int(11)` | NO | - | - |
| `show_popup` | `tinyint(4)` | NO | - | - |
| `overdue_repeat` | `varchar(10)` | NO | - | - |

## fw_login_limits

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `subject_key` | `char(64)` | NO | PRI | - |
| `attempts` | `int(11)` | NO | - | - |
| `window_start` | `datetime` | NO | MUL | - |

## fw_notice_events

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | - |
| `event_key` | `varchar(191)` | NO | PRI | - |
| `notification_id` | `int(11)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_notice_preferences

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | - |
| `debt_lead_days` | `smallint(6)` | NO | - | - |
| `budget_popup` | `tinyint(4)` | NO | - | - |
| `debt_popup` | `tinyint(4)` | NO | - | - |
| `overdue_repeat` | `varchar(10)` | NO | - | - |

## fw_personal_reminders

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `reminder_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `title` | `varchar(120)` | NO | - | - |
| `note` | `varchar(255)` | NO | - | - |
| `remind_at` | `datetime` | NO | - | - |
| `show_popup` | `tinyint(4)` | NO | - | - |
| `status` | `varchar(10)` | NO | - | - |
| `delivered_at` | `datetime` | YES | - | - |
| `notification_id` | `int(11)` | YES | - | - |
| `revision` | `int(11)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_purchase_scenarios

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `scenario_id` | `bigint(20)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `purchase_title` | `varchar(80)` | NO | - | - |
| `purchase_date` | `date` | NO | - | - |
| `currency_code` | `char(3)` | NO | - | - |
| `expected_amount` | `decimal(12,2)` | NO | - | - |
| `inputs_json` | `longtext` | NO | - | - |
| `result_json` | `longtext` | NO | - | - |
| `outcome` | `varchar(12)` | NO | - | - |
| `actual_amount` | `decimal(12,2)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## fw_record_history

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `history_id` | `bigint(20)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `entity_type` | `varchar(40)` | NO | - | - |
| `entity_id` | `bigint(20)` | NO | - | - |
| `event_name` | `varchar(40)` | NO | - | - |
| `before_data` | `longtext` | YES | - | - |
| `after_data` | `longtext` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## fw_requests

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | - |
| `request_key` | `char(64)` | NO | PRI | - |
| `created_at` | `timestamp` | NO | MUL | - |

## fw_spending_labels

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `transaction_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `label` | `varchar(60)` | NO | - | - |

## fw_work_cost_links

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `expense_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `session_id` | `int(11)` | NO | - | - |
| `transaction_id` | `int(11)` | NO | UNI | - |

## fw_work_expense_kinds

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `expense_id` | `bigint(20)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `cost_kind` | `varchar(20)` | NO | - | - |

## fw_work_session_details

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `session_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `work_source` | `varchar(100)` | NO | - | - |
| `extra_minutes` | `smallint(6)` | NO | - | - |
| `payment_status` | `varchar(12)` | NO | - | - |
| `received_amount` | `decimal(12,2)` | NO | - | - |
| `received_date` | `date` | YES | - | - |
| `income_transaction_id` | `int(11)` | YES | UNI | - |
| `record_version` | `int(11)` | NO | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## fw_work_times

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `session_id` | `int(11)` | NO | PRI | - |
| `user_id` | `int(11)` | NO | MUL | - |
| `started_at` | `datetime` | NO | - | - |
| `ended_at` | `datetime` | NO | - | - |
| `break_minutes` | `int(11)` | NO | - | - |

## money_lab_experiments

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `experiment_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `category_id` | `int(11)` | NO | - | - |
| `experiment_title` | `varchar(120)` | NO | - | - |
| `start_date` | `date` | NO | - | - |
| `end_date` | `date` | NO | - | - |
| `duration_days` | `smallint(6)` | NO | - | - |
| `reduction_percent` | `tinyint(4)` | NO | - | - |
| `baseline_amount` | `decimal(12,2)` | NO | - | - |
| `baseline_source` | `varchar(12)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## users

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `user_id` | `int(11)` | NO | PRI | auto_increment |
| `full_name` | `varchar(100)` | NO | - | - |
| `email` | `varchar(100)` | NO | UNI | - |
| `password` | `varchar(255)` | NO | - | - |
| `preferred_currency` | `varchar(10)` | YES | - | - |
| `theme` | `varchar(10)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## work_session_expenses

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `expense_id` | `bigint(20)` | NO | PRI | auto_increment |
| `session_id` | `int(11)` | NO | MUL | - |
| `expense_description` | `varchar(160)` | NO | - | - |
| `amount` | `decimal(12,2)` | NO | - | - |

## work_sessions

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `session_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `session_date` | `date` | NO | - | - |
| `hours_worked` | `decimal(5,2)` | NO | - | - |
| `gross_earnings` | `decimal(12,2)` | NO | - | - |
| `work_expenses` | `decimal(12,2)` | NO | - | - |
| `notes` | `varchar(255)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## categories

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `category_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `category_name` | `varchar(50)` | NO | - | - |
| `category_type` | `enum('income','expense')` | NO | - | - |
| `is_default` | `tinyint(1)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## notifications

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `notification_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `message` | `varchar(255)` | NO | - | - |
| `is_read` | `tinyint(1)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## savings_allocations

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `allocation_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `allocation_name` | `varchar(100)` | NO | - | - |
| `allocation_percentage` | `decimal(5,2)` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |
| `updated_at` | `timestamp` | NO | - | on update current_timestamp() |

## savings_challenges

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `challenge_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `challenge_name` | `varchar(100)` | NO | - | - |
| `target_amount` | `decimal(12,2)` | NO | - | - |
| `start_date` | `date` | NO | - | - |
| `end_date` | `date` | NO | - | - |
| `current_amount` | `decimal(12,2)` | YES | - | - |
| `status` | `enum('scheduled','active','completed','failed')` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## savings_goals

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `goal_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `goal_name` | `varchar(100)` | NO | - | - |
| `target_amount` | `decimal(12,2)` | NO | - | - |
| `target_date` | `date` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## transactions

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `transaction_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `category_id` | `int(11)` | NO | MUL | - |
| `transaction_type` | `enum('income','expense')` | NO | - | - |
| `amount` | `decimal(12,2)` | NO | - | - |
| `description` | `varchar(255)` | YES | - | - |
| `transaction_date` | `date` | NO | - | - |
| `receipt_image` | `varchar(255)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## budgets

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `budget_id` | `int(11)` | NO | PRI | auto_increment |
| `user_id` | `int(11)` | NO | MUL | - |
| `category_id` | `int(11)` | NO | MUL | - |
| `budget_limit` | `decimal(12,2)` | NO | - | - |
| `start_date` | `date` | NO | - | - |
| `end_date` | `date` | NO | - | - |
| `created_at` | `timestamp` | NO | - | - |

## savings_contributions

| Field | SQL type | Nullable | Key | Extra |
| --- | --- | --- | --- | --- |
| `contribution_id` | `int(11)` | NO | PRI | auto_increment |
| `goal_id` | `int(11)` | NO | MUL | - |
| `contribution_type` | `enum('deposit','withdrawal')` | NO | - | - |
| `amount` | `decimal(12,2)` | NO | - | - |
| `contribution_date` | `date` | NO | - | - |
| `notes` | `varchar(255)` | YES | - | - |
| `created_at` | `timestamp` | NO | - | - |

## Physical foreign keys

| Table / column | Referenced table / column |
| --- | --- |
| budgets.user_id | users.user_id |
| budgets.category_id | categories.category_id |
| categories.user_id | users.user_id |
| notifications.user_id | users.user_id |
| savings_allocations.user_id | users.user_id |
| savings_challenges.user_id | users.user_id |
| savings_contributions.goal_id | savings_goals.goal_id |
| savings_goals.user_id | users.user_id |
| transactions.user_id | users.user_id |
| transactions.category_id | categories.category_id |
