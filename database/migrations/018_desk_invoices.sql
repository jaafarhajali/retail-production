-- 018_desk_invoices.sql (2026-10-14): invoices written at the desk for B2B customers.
-- Owner's decisions: no VAT, one invoice per delivery, only the owner writes them, no quotes. A desk invoice is a sale
-- with no cash session (the goods leave the stock and the whole amount goes on the customer's account), a due date
-- from the customer's payment terms, and a delivery address. Payments against it are recorded on the ledger with a
-- method and a reference (transfer, cheque) or collected in cash at the till as before.
ALTER TABLE sales MODIFY register_id INT UNSIGNED NULL, MODIFY session_id INT UNSIGNED NULL;
ALTER TABLE sales
  ADD COLUMN origin   ENUM('till','desk') NOT NULL DEFAULT 'till' AFTER invoice_type,
  ADD COLUMN due_date DATE         NULL AFTER origin,
  ADD COLUMN ship_to  VARCHAR(255) NULL AFTER due_date;
ALTER TABLE sales ADD KEY idx_sales_origin_due (origin, due_date);

ALTER TABLE customers ADD COLUMN payment_terms_days INT UNSIGNED NULL AFTER credit_limit_usd;

ALTER TABLE customer_ledger
  ADD COLUMN method    ENUM('cash','card','transfer','cheque') NULL AFTER type,
  ADD COLUMN reference VARCHAR(80) NULL AFTER method;

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('invoice.manage', 'Write desk invoices and record their payments', 'Selling', 23);
