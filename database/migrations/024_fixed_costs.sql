-- 024_fixed_costs.sql (2026-10-20): the monthly fixed costs (rent, salaries, electricity) typed on the dashboard,
-- one amount per month, by the administrator. The break-even is this amount divided by what the pieces earn;
-- a month without its own amount uses the latest earlier one. Replaces the ticked expense categories of F5
-- (setting fixed_expense_categories), which gave nothing until the bills were recorded.
CREATE TABLE fixed_costs (
  month CHAR(7) NOT NULL PRIMARY KEY,
  amount_usd DECIMAL(12,2) NOT NULL,
  user_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_fixed_costs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELETE FROM settings WHERE setting_key = 'fixed_expense_categories';
