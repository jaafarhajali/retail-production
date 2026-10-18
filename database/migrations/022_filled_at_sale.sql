-- 022_filled_at_sale.sql (2026-10-18): a box filled at the sale. The owner weighs a custom box when he fills it, at a
-- workshop or at home before a delivery; boxes may come in sizes (0.5 kg, 1 kg). Such a product keeps no stock of its
-- own: the sale takes the grams of mix out of the mix's stock, and the product's packaging per piece with them, so the
-- stock and the cost are right. "Assembled on the spot, stock not counted" (F6) becomes this.
ALTER TABLE products
  ADD COLUMN fill_mix_id INT UNSIGNED NULL AFTER track_stock,      -- the mix it is filled from (track_stock = 0)
  ADD COLUMN fill_base   INT          NULL AFTER fill_mix_id,      -- its usual weight per piece, in grams; offered at the sale, changeable
  ADD CONSTRAINT fk_products_fill_mix FOREIGN KEY (fill_mix_id) REFERENCES products (id) ON DELETE RESTRICT;

ALTER TABLE sale_items
  ADD COLUMN fill_mix_id INT UNSIGNED NULL AFTER price_overridden,  -- what the line was filled from
  ADD COLUMN fill_base   INT          NULL AFTER fill_mix_id;       -- grams of mix per piece, as weighed at the sale

-- a product sold without stock today (the Customized Box) is filled from the mix when there is exactly one mix
UPDATE products p
  JOIN (SELECT MIN(id) AS id, COUNT(*) AS n FROM products WHERE kind = 'intermediate') m ON m.n = 1
   SET p.fill_mix_id = m.id
 WHERE p.kind = 'finished' AND p.track_stock = 0;
