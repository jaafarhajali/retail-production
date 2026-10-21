-- 025_custom_order.sql (2026-10-21): the custom order. The owner: "neither the weight of the marshmallow nor the box is
-- standard; I enter the cost for each order". One built-in product, "Custom order", never listed, carries the line: at
-- each sale the price, the grams of marshmallow (out of the mix), the packaging taken from the stock and the other costs
-- are typed. sale_item_packaging keeps what each such line took, per piece, so a held invoice finishes right.
-- The products that were "filled at the sale" (Customized Box) are switched off: the button on the till replaces them.
ALTER TABLE products ADD COLUMN is_custom_order TINYINT(1) NOT NULL DEFAULT 0 AFTER fill_base;
CREATE TABLE sale_item_packaging (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sale_item_id INT UNSIGNED NOT NULL,
  material_id INT UNSIGNED NOT NULL,
  qty_base BIGINT NOT NULL,
  cost_per_base DECIMAL(16,6) NOT NULL,
  CONSTRAINT fk_sip_item FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  CONSTRAINT fk_sip_material FOREIGN KEY (material_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE products SET is_active = 0 WHERE fill_mix_id IS NOT NULL AND is_custom_order = 0;
INSERT INTO products (name, description, internal_code, base_unit, kind, cost_per_base, stock_base, allow_price_override, show_on_pos_grid, is_active, track_stock, is_custom_order, fill_mix_id)
SELECT 'Custom order', 'A box made to the customer''s wish: price, marshmallow, packaging and extras typed at each sale', 'CUSTOM', 'piece', 'finished', 0, 0, 1, 0, 1, 0, 1, (SELECT MIN(id) FROM products WHERE kind = 'intermediate' AND is_active = 1)
WHERE NOT EXISTS (SELECT 1 FROM products WHERE is_custom_order = 1);
INSERT INTO product_units (product_id, name, factor, retail_price, wholesale_price, allows_fraction, is_default_sale, is_default_purchase, is_display)
SELECT p.id, 'Piece', 1, NULL, NULL, 0, 1, 0, 1 FROM products p WHERE p.is_custom_order = 1 AND NOT EXISTS (SELECT 1 FROM product_units u WHERE u.product_id = p.id);
