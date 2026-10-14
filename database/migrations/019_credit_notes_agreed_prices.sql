-- 019_credit_notes_agreed_prices.sql (2026-10-14): desk invoices, step 3.
-- A credit note is a return written at the desk against a desk invoice: no cash session, the goods come back and the
-- amount is credited to the customer's account. An agreed price is what one customer pays for one unit of a product,
-- used on their desk invoices instead of the wholesale or retail price.
ALTER TABLE returns MODIFY session_id INT UNSIGNED NULL, MODIFY register_id INT UNSIGNED NULL;

CREATE TABLE customer_prices (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  customer_id     INT UNSIGNED  NOT NULL,
  product_unit_id INT UNSIGNED  NOT NULL,
  price_usd       DECIMAL(12,2) NOT NULL,
  updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cp_customer_unit (customer_id, product_unit_id),
  CONSTRAINT fk_cp_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
