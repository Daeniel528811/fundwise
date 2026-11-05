-- OPTIONAL, NON-DESTRUCTIVE migration.
-- Run only if your database does not yet contain savings_allocations.
-- Existing rows and tables will NOT be deleted or modified.
USE fundwise_db;
CREATE TABLE IF NOT EXISTS savings_allocations (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    allocation_name VARCHAR(100) NOT NULL,
    allocation_percentage DECIMAL(5,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_allocation_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT chk_allocation_percentage
        CHECK (allocation_percentage > 0 AND allocation_percentage <= 100)
);
-- A 100% per-user total is enforced by the PHP Savings Allocation module.
