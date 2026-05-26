-- PennyWise Database Schema
-- MySQL Database for XAMPP
-- Run this SQL in phpMyAdmin or MySQL command line

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS pennywise_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pennywise_db;

-- ============================================
-- USERS TABLE
-- Stores user account information
-- ============================================
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_email (email),
    INDEX idx_username (username)
) ENGINE=InnoDB;

-- ============================================
-- ACCOUNTS TABLE
-- Stores user's financial accounts (Cash, Bank, etc.)
-- ============================================
CREATE TABLE IF NOT EXISTS accounts (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    balance DECIMAL(15, 2) DEFAULT 0.00,
    account_type ENUM('cash', 'bank', 'credit', 'savings', 'other') DEFAULT 'cash',
    icon VARCHAR(50) DEFAULT 'wallet',
    color VARCHAR(7) DEFAULT '#f48c8c',
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_accounts (user_id)
) ENGINE=InnoDB;

-- ============================================
-- CATEGORIES TABLE
-- Stores income and expense categories
-- ============================================
CREATE TABLE IF NOT EXISTS categories (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    icon VARCHAR(50) DEFAULT 'tag',
    color VARCHAR(7) DEFAULT '#f48c8c',
    budget DECIMAL(15, 2) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_categories (user_id),
    INDEX idx_category_type (type)
) ENGINE=InnoDB;

-- ============================================
-- TRANSFER CATEGORIES TABLE
-- Stores categories specific to transfers
-- Must be created BEFORE records table (FK dependency)
-- ============================================
CREATE TABLE IF NOT EXISTS transfer_categories (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT 'repeat',
    color VARCHAR(7) DEFAULT '#6366f1',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_transfer_categories (user_id)
) ENGINE=InnoDB;

-- ============================================
-- DEBTS TABLE
-- Stores user debts/loans
-- Must be created AFTER accounts table (FK dependency)
-- ============================================
CREATE TABLE IF NOT EXISTS debts (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    paid DECIMAL(15, 2) DEFAULT 0.00,
    account_id VARCHAR(36) NOT NULL,
    due_date DATE NULL,
    notes TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
    INDEX idx_user_debts (user_id)
) ENGINE=InnoDB;

-- ============================================
-- RECORDS TABLE
-- Stores all financial transactions
-- Must be created AFTER categories, transfer_categories, and accounts (FK dependencies)
-- ============================================
CREATE TABLE IF NOT EXISTS records (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    type ENUM('income', 'expense', 'transfer') NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    category_id VARCHAR(36) NULL,
    from_account_id VARCHAR(36) NULL,
    to_account_id VARCHAR(36) NULL,
    transfer_category_id VARCHAR(36) NULL,
    description VARCHAR(255) NULL,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (transfer_category_id) REFERENCES transfer_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (from_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
    INDEX idx_user_records (user_id),
    INDEX idx_record_date (date),
    INDEX idx_record_type (type)
) ENGINE=InnoDB;

-- ============================================
-- BUDGETS TABLE
-- Stores budget goals per category
-- ============================================
CREATE TABLE IF NOT EXISTS budgets (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    category_id VARCHAR(36) NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    period ENUM('weekly', 'monthly', 'yearly') DEFAULT 'monthly',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    INDEX idx_user_budgets (user_id),
    INDEX idx_budget_period (period)
) ENGINE=InnoDB;

-- ============================================
-- ACTIVITY LOG TABLE
-- Tracks user activities for auditing
-- ============================================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(36) NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_activity (user_id),
    INDEX idx_activity_date (created_at)
) ENGINE=InnoDB;

-- ============================================
-- USER SESSIONS TABLE (Optional - for remember me functionality)
-- ============================================
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(36) PRIMARY KEY,
    user_id VARCHAR(36) NOT NULL,
    token VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_token (token),
    INDEX idx_session_user (user_id)
) ENGINE=InnoDB;

-- ============================================
-- DEFAULT DATA
-- ============================================

-- Insert default categories for new users (these will be copied per user)
-- We'll handle this in PHP when a user registers

-- ============================================
-- VIEWS FOR COMMON QUERIES
-- ============================================

-- View for monthly summary
CREATE OR REPLACE VIEW monthly_summary AS
SELECT 
    user_id,
    YEAR(date) as year,
    MONTH(date) as month,
    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
    SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) as net_amount
FROM records
GROUP BY user_id, YEAR(date), MONTH(date);

-- View for category spending
CREATE OR REPLACE VIEW category_spending AS
SELECT 
    r.user_id,
    r.category_id,
    c.name as category_name,
    c.type as category_type,
    SUM(r.amount) as total_amount,
    COUNT(r.id) as transaction_count
FROM records r
LEFT JOIN categories c ON r.category_id = c.id
GROUP BY r.user_id, r.category_id, c.name, c.type;