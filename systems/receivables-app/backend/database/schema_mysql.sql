-- ============================================================
-- RECEIVABLES MANAGEMENT SYSTEM - DATABASE SCHEMA (MySQL)
-- Used automatically by includes/db.php when DB_DRIVER = 'mysql'
-- ============================================================

DROP TABLE IF EXISTS soa_tracker;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS monitoring_entries;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;

CREATE TABLE companies (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL UNIQUE,
    code            VARCHAR(50),
    status          VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    account_type    VARCHAR(20) NOT NULL DEFAULT 'COMPANY', -- COMPANY or HMO
    branch          VARCHAR(50),        -- owning branch (matches users.username); NULL = unassigned/shared, visible to admin only
    contact_person  VARCHAR(255),
    contact_number  VARCHAR(50),
    email_address   VARCHAR(255),
    remarks         TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ledger_entries (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    company_id          INT NOT NULL,
    company_name        VARCHAR(255),
    billing_date        VARCHAR(50),
    soa_number          VARCHAR(100),
    amount              DECIMAL(15,2) DEFAULT 0,
    tax_withheld        DECIMAL(15,2) DEFAULT 0,
    surcharge           DECIMAL(15,2) DEFAULT 0,
    rebate              DECIMAL(15,2) DEFAULT 0,
    receivable_amount   DECIMAL(15,2) DEFAULT 0,
    payment_date        VARCHAR(50),
    check_ref           VARCHAR(100),
    payment_method      VARCHAR(30),
    payment_method_bank VARCHAR(30),
    payment_method_other VARCHAR(100),
    paid_amount         DECIMAL(15,2) DEFAULT 0,
    balance             DECIMAL(15,2) DEFAULT 0,
    due_date            VARCHAR(50),
    remarks             TEXT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE monitoring_entries (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    company_id          INT NOT NULL,
    expected_date       VARCHAR(50),
    amount              DECIMAL(15,2) DEFAULT 0,
    remaining_balance   DECIMAL(15,2) DEFAULT 0,
    due_date            VARCHAR(50),
    remarks             TEXT,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE soa_tracker (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    soa_number      VARCHAR(100) NOT NULL, -- unique except for supplementary A/B copies; enforced in app code, not here
    company_id      INT,
    ledger_entry_id INT,
    status          VARCHAR(20) NOT NULL DEFAULT 'UNUSED',
    issued_date     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(100) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    full_name       VARCHAR(255),
    role            VARCHAR(50) NOT NULL DEFAULT 'admin',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_ledger_company ON ledger_entries(company_id);
CREATE INDEX idx_ledger_billing_date ON ledger_entries(billing_date);
CREATE INDEX idx_monitoring_company ON monitoring_entries(company_id);
CREATE INDEX idx_companies_branch ON companies(branch);
