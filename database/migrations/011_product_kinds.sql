-- 011_product_kinds.sql — Phase F1 (2026-10-12): the factory has three kinds of product.
--   finished      what is sold (Joy 100g, Marshmallow Stick…): prices, barcodes, on the till
--   intermediate  a mix made from raw materials and used by finished products (recipes come in F2)
--   material      raw materials and packaging: bought from suppliers, consumed by production, never sold
-- Every existing product is finished. Purchases take materials only; the till sells finished products only.
ALTER TABLE products ADD COLUMN kind ENUM('finished','intermediate','material') NOT NULL DEFAULT 'finished' AFTER base_unit;
ALTER TABLE products ADD KEY idx_products_kind (kind);
