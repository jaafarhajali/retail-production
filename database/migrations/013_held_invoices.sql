-- 013_held_invoices.sql — Phase F3 (2026-10-13): the delivery invoice, held on the till.
-- A held invoice has its number and its lines but no payment, no stock movement and no ledger line: the goods go out
-- with the seller (owner's decision of 2026-10-12: stock moves when the seller is back). Settling it records what came
-- back, takes the net quantities out of stock, takes the cash, puts the rest on the customer's account, and keeps the
-- original figures in sale_settlements so the printed invoice and the final one can always be compared.
ALTER TABLE sales MODIFY status ENUM('completed','voided','on_hold') NOT NULL DEFAULT 'completed';
ALTER TABLE sales
  ADD COLUMN held_at    DATETIME     NULL AFTER status,
  ADD COLUMN settled_at DATETIME     NULL AFTER held_at,
  ADD COLUMN settled_by INT UNSIGNED NULL AFTER settled_at;
ALTER TABLE sales ADD KEY idx_sales_status (status);

CREATE TABLE sale_settlements (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id      INT UNSIGNED  NOT NULL,
  original_usd DECIMAL(12,2) NOT NULL,                 -- the invoice as printed
  returned_usd DECIMAL(12,2) NOT NULL,                 -- what came back, at the invoice's prices
  net_usd      DECIMAL(12,2) NOT NULL,                 -- original − returned
  paid_usd     DECIMAL(12,2) NOT NULL,                 -- cash and card received
  balance_usd  DECIMAL(12,2) NOT NULL,                 -- on the customer's account
  settled_by   INT UNSIGNED  NOT NULL,
  settled_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes        VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settlement_sale (sale_id),
  CONSTRAINT fk_settlement_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settlement_returns (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  settlement_id INT UNSIGNED  NOT NULL,
  sale_item_id  INT UNSIGNED  NOT NULL,
  qty           DECIMAL(12,3) NOT NULL,
  base_qty      INT           NOT NULL,
  value_usd     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sr_settlement (settlement_id),
  CONSTRAINT fk_sr_settlement FOREIGN KEY (settlement_id) REFERENCES sale_settlements (id) ON DELETE RESTRICT,
  CONSTRAINT fk_sr_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
