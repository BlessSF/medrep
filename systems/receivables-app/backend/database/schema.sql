-- ============================================================
-- RECEIVABLES MANAGEMENT SYSTEM - DATABASE SCHEMA
-- Compatible with SQLite (default) and MySQL (minor tweaks noted)
-- ============================================================

DROP TABLE IF EXISTS soa_tracker;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS monitoring_entries;
DROP TABLE IF EXISTS companies;
DROP TABLE IF EXISTS users;

-- Companies / HMO / Corporate accounts (mirrors each per-company tab in the original workbook)
CREATE TABLE companies (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL UNIQUE,
    code            TEXT,                       -- short code used in SOA numbering, e.g. "ICR"
    status          TEXT NOT NULL DEFAULT 'ACTIVE',  -- ACTIVE, INACTIVE, BANKRUPT
    account_type    TEXT NOT NULL DEFAULT 'COMPANY', -- COMPANY or HMO
    branch          TEXT,                       -- owning branch (matches users.username); NULL = unassigned/shared, visible to admin only
    contact_person  TEXT,
    contact_number  TEXT,
    email_address   TEXT,
    remarks         TEXT,
    created_at      TEXT DEFAULT CURRENT_TIMESTAMP
);

-- One row per billing / SOA entry, mirrors the columns found in the per-company sheets
-- (Date of Billing, SOA Number, Amount, Less Taxes Withheld, Date of Payment, Check Ref,
--  Paid, Remaining Balance, Aging, Surcharge, Rebate, Remarks)
CREATE TABLE ledger_entries (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id      INTEGER NOT NULL,
    company_name    TEXT,               -- denormalized copy of companies.name, kept in sync automatically
    billing_date    TEXT,               -- ISO date of billing / coverage month
    soa_number      TEXT,               -- statement of account number
    amount          REAL DEFAULT 0,     -- gross billed amount
    tax_withheld    REAL DEFAULT 0,     -- EWT 2% / withholding tax
    surcharge       REAL DEFAULT 0,
    rebate          REAL DEFAULT 0,
    receivable_amount REAL DEFAULT 0,   -- amount - tax_withheld (+ surcharge - rebate)
    payment_date    TEXT,               -- date payment received
    check_ref       TEXT,               -- check number / date reference
    payment_method  TEXT,               -- Cash / Card / Check
    payment_method_bank TEXT,           -- when payment_method = 'Card': Metrobank / BPI / PBB / Maya / Other
    payment_method_other TEXT,          -- free-text value when payment_method_bank = 'Other'
    paid_amount     REAL DEFAULT 0,
    balance         REAL DEFAULT 0,     -- receivable_amount - paid_amount (running)
    due_date        TEXT,               -- optional explicit due date (else computed +90 days)
    remarks         TEXT,
    created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at      TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Collection monitoring schedule (mirrors the "MONITORING" sheet: expected collection date & amount)
CREATE TABLE monitoring_entries (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id      INTEGER NOT NULL,
    expected_date   TEXT,               -- month the collection is expected/posted
    amount          REAL DEFAULT 0,
    remaining_balance REAL DEFAULT 0,
    due_date        TEXT,               -- when collection is officially due
    remarks         TEXT,
    created_at      TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- SOA number tracker (mirrors "COMP.-SOA NUM TRACKER" sheet)
CREATE TABLE soa_tracker (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    soa_number      TEXT NOT NULL, -- unique except for supplementary A/B copies; enforced in app code, not here
    company_id      INTEGER,
    ledger_entry_id INTEGER,
    status          TEXT NOT NULL DEFAULT 'UNUSED', -- UNUSED or USED
    issued_date     TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entries(id) ON DELETE SET NULL
);

-- Simple single/multi-user login
CREATE TABLE users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    full_name       TEXT,
    role            TEXT NOT NULL DEFAULT 'admin',
    created_at      TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_ledger_company ON ledger_entries(company_id);
CREATE INDEX idx_ledger_billing_date ON ledger_entries(billing_date);
CREATE INDEX idx_monitoring_company ON monitoring_entries(company_id);
CREATE INDEX idx_companies_branch ON companies(branch);
