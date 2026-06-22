CREATE DATABASE IF NOT EXISTS crm_withdrawal DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE crm_withdrawal;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    real_name VARCHAR(50),
    balance DECIMAL(12,2) DEFAULT 0.00,
    role ENUM('user', 'admin', 'auditor') DEFAULT 'user',
    status TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdrawal_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(100) NOT NULL,
    min_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    max_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    daily_limit DECIMAL(10,2) DEFAULT 0.00,
    fee_rate DECIMAL(5,4) DEFAULT 0.0000,
    fee_min DECIMAL(10,2) DEFAULT 0.00,
    fee_max DECIMAL(10,2) DEFAULT 0.00,
    status TINYINT DEFAULT 1,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdrawal_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    fee DECIMAL(10,2) DEFAULT 0.00,
    actual_amount DECIMAL(12,2) DEFAULT 0.00,
    bank_name VARCHAR(100),
    bank_account VARCHAR(50),
    account_name VARCHAR(50),
    rule_id INT,
    status ENUM('pending','reviewing','approved','rejected','cancelled','completed','failed') DEFAULT 'pending',
    review_remark VARCHAR(255),
    reviewer_id INT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS withdrawal_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    transaction_no VARCHAR(64) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('processing','success','failed') DEFAULT 'processing',
    arrived_at TIMESTAMP NULL,
    fail_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS review_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    action ENUM('approve','reject') NOT NULL,
    remark VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE withdrawal_applications MODIFY COLUMN status ENUM('pending','reviewing','approved','rejected','cancelled','completed','failed') DEFAULT 'pending';
