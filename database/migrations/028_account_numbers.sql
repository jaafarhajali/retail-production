-- 028_account_numbers.sql (2026-11-03): the owner, an accountant, gives the accounts his own numbers and names on the
-- Opening balances page. What feeds an account stays fixed; only how it is numbered and named changes. account_key is
-- the built-in code ('1100') for a fixed account, or 'exp:' + the lower-cased category for an expense category.
CREATE TABLE account_numbers (
  account_key VARCHAR(80) NOT NULL PRIMARY KEY,
  number VARCHAR(10) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_account_number (number),
  CONSTRAINT fk_account_numbers_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
