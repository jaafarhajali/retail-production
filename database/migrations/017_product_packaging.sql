-- 017_product_packaging.sql (2026-10-14): what one piece of a finished product takes out of the packaging stock.
-- "Joy 100g = 1 Box 100g + 1 Label + 10 g of pistachio paste". When Packing records "30 Joy 100g made", it takes
-- 30 boxes, 30 labels and 300 g of paste out by itself, next to the mix the user typed. A definition, not a record:
-- rewritten as a whole when the product is saved.
CREATE TABLE product_packaging (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED NOT NULL,                 -- the finished product
  material_id INT UNSIGNED NOT NULL,                 -- packaging, a raw material, or a mix
  qty_base    INT          NOT NULL,                 -- per piece (per base unit of the product), in the material's base units
  PRIMARY KEY (id),
  UNIQUE KEY uq_pp_product_material (product_id, material_id),
  KEY idx_pp_material (material_id),
  CONSTRAINT fk_pp_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_material FOREIGN KEY (material_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
