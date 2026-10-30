-- 027_accounts.sql (2026-10-30): the accounting side (owner, an accountant himself, through Aya): trial balance, income
-- statement, balance sheet and cash flow. The accounts are derived from the records the system already keeps (sales,
-- purchases, expenses, ledgers, stock movements, cash sessions); the figures it cannot know, the owner types once:
-- the bank, the equipment, the loans and the capital at the start, here.
CREATE TABLE opening_balances (
  account VARCHAR(10) NOT NULL PRIMARY KEY,
  amount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_opening_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
