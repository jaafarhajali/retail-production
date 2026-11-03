-- 029_ledger.sql (2026-11-03): a chart of accounts that grows with the business (Aya for the owner: "if this business
-- grows I don't need them static"). Every account is a row: the 18 built-in ones (system_key = their code; what feeds
-- them is fixed, their number and name are the owner's), the expense categories once numbered (system_key = 'exp:' +
-- the lower-cased category), and the owner's own accounts (system_key NULL), fed by journal entries only.
-- Journal entries are append-only (financial records): a correction is a reversing entry (reverses_id).
CREATE TABLE accounts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(10) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  type ENUM('asset','liability','equity','income','expense') NOT NULL,
  system_key VARCHAR(80) NULL,
  is_money TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_accounts_number (number),
  UNIQUE KEY uq_accounts_system (system_key),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO accounts (number, name, type, system_key, is_money, created_at, updated_at) VALUES
  ('1000', 'Cash in drawers', 'asset', '1000', 1, NOW(), NOW()),
  ('1100', 'Bank and owner''s money', 'asset', '1100', 1, NOW(), NOW()),
  ('1200', 'Customers (what they owe)', 'asset', '1200', 0, NOW(), NOW()),
  ('1300', 'Stock at cost', 'asset', '1300', 0, NOW(), NOW()),
  ('1500', 'Equipment', 'asset', '1500', 0, NOW(), NOW()),
  ('1600', 'Other assets', 'asset', '1600', 0, NOW(), NOW()),
  ('2000', 'Suppliers (what we owe)', 'liability', '2000', 0, NOW(), NOW()),
  ('2100', 'Loans', 'liability', '2100', 0, NOW(), NOW()),
  ('2200', 'Other liabilities', 'liability', '2200', 0, NOW(), NOW()),
  ('3000', 'Capital', 'equity', '3000', 0, NOW(), NOW()),
  ('3100', 'Retained earnings', 'equity', '3100', 0, NOW(), NOW()),
  ('3200', 'Profit of the period', 'equity', '3200', 0, NOW(), NOW()),
  ('3900', 'Differences', 'equity', '3900', 0, NOW(), NOW()),
  ('4000', 'Sales', 'income', '4000', 0, NOW(), NOW()),
  ('4100', 'Sales returns', 'income', '4100', 0, NOW(), NOW()),
  ('5000', 'Cost of goods sold', 'expense', '5000', 0, NOW(), NOW()),
  ('5100', 'Stock losses and corrections', 'expense', '5100', 0, NOW(), NOW()),
  ('6900', 'Cash differences at closing', 'expense', '6900', 0, NOW(), NOW());

-- the numbers and names saved since 028 (first moved aside, so a swap of two numbers cannot collide)
UPDATE accounts a JOIN account_numbers n ON n.account_key = a.system_key SET a.number = CONCAT('~', a.id);
UPDATE accounts a JOIN account_numbers n ON n.account_key = a.system_key SET a.number = n.number, a.name = IF(n.name = '', a.name, n.name);
INSERT INTO accounts (number, name, type, system_key, created_at, updated_at)
  SELECT n.number, '', 'expense', n.account_key, NOW(), NOW() FROM account_numbers n WHERE n.account_key LIKE 'exp:%';
DROP TABLE account_numbers;

CREATE TABLE journal_entries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  reverses_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_journal_date (entry_date),
  UNIQUE KEY uq_journal_reverses (reverses_id),
  CONSTRAINT fk_journal_reverses FOREIGN KEY (reverses_id) REFERENCES journal_entries(id),
  CONSTRAINT fk_journal_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_lines (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id INT UNSIGNED NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  debit_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  credit_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  KEY idx_journal_lines_account (account_id),
  CONSTRAINT fk_journal_lines_entry FOREIGN KEY (entry_id) REFERENCES journal_entries(id),
  CONSTRAINT fk_journal_lines_account FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
