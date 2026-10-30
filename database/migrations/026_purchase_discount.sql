-- 026_purchase_discount.sql (2026-10-30): a discount from the supplier on the whole bill (Aya). It is spread across the
-- lines in proportion, as on an invoice, so every material goes into stock at its real net cost; the purchase keeps the
-- subtotal, the discount and the net total, and the supplier's ledger takes the net.
ALTER TABLE purchases ADD COLUMN subtotal_usd DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER purchase_date;
ALTER TABLE purchases ADD COLUMN discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_usd;
UPDATE purchases SET subtotal_usd = total_usd;
ALTER TABLE purchase_items ADD COLUMN line_discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER unit_cost_usd;
