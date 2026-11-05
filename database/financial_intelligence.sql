-- FUNDWISE / Financial Intelligence add-on
-- Import once into your EXISTING fundwise_db in phpMyAdmin.
-- Does not modify users, transactions, savings, budgets or any other existing tables.

CREATE TABLE IF NOT EXISTS money_lab_experiments (
    experiment_id          INT            NOT NULL AUTO_INCREMENT
    , user_id              INT            NOT NULL
    , category_id          INT            NOT NULL
    , experiment_title     VARCHAR(120)   NOT NULL
    , start_date           DATE           NOT NULL
    , end_date             DATE           NOT NULL
    , duration_days        SMALLINT       NOT NULL
    , reduction_percent    TINYINT        NOT NULL
    , baseline_amount      DECIMAL(12,2)  NOT NULL
    , baseline_source      VARCHAR(12)    NOT NULL DEFAULT 'history'
    , created_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    , CONSTRAINT money_lab_experiments_pk PRIMARY KEY (experiment_id)
    , INDEX money_lab_owner_date_idx (user_id, start_date, end_date)
);

CREATE TABLE IF NOT EXISTS work_sessions (
    session_id             INT            NOT NULL AUTO_INCREMENT
    , user_id              INT            NOT NULL
    , session_date         DATE           NOT NULL
    , hours_worked         DECIMAL(5,2)   NOT NULL
    , gross_earnings       DECIMAL(12,2)  NOT NULL
    , work_expenses        DECIMAL(12,2)  NOT NULL DEFAULT 0
    , notes                VARCHAR(255)   NOT NULL DEFAULT ''
    , created_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    , CONSTRAINT work_sessions_pk PRIMARY KEY (session_id)
    , INDEX work_sessions_owner_date_idx (user_id, session_date)
);
