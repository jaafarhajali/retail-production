-- 016_packing.sql (2026-10-14): the mix is packed into finished products with the quantities typed each time.
-- A recipe makes a mix only. Packing takes mix (and packaging) out of stock and puts finished products in, the
-- quantities being decided at that moment, never in a recipe. The cost of what was used is shared between the
-- products made, in proportion to their selling value. Recipes that made a finished product are switched off:
-- their runs stay in the history.
CREATE TABLE packings (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  pack_no    VARCHAR(20)   NOT NULL,
  cost_usd   DECIMAL(12,2) NOT NULL,                  -- everything used, at its cost of the moment
  user_id    INT UNSIGNED  NOT NULL,
  notes      VARCHAR(255)  NULL,
  created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_packings_no (pack_no),
  KEY idx_packings_created (created_at),
  CONSTRAINT fk_packings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE packing_lines (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  packing_id    INT UNSIGNED  NOT NULL,
  side          ENUM('used','made') NOT NULL,         -- used: the mix and the packaging, made: the finished products
  product_id    INT UNSIGNED  NOT NULL,
  qty_base      INT           NOT NULL,
  cost_per_base DECIMAL(12,6) NOT NULL,               -- used: the cost then, frozen. made: its share of the cost
  cost_usd      DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pkl_packing (packing_id),
  KEY idx_pkl_product (product_id),
  CONSTRAINT fk_pkl_packing FOREIGN KEY (packing_id) REFERENCES packings (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pkl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO counters (name, next_value) VALUES ('packing', 1);

UPDATE recipes r JOIN products p ON p.id = r.product_id SET r.is_active = 0, r.is_default = 0 WHERE p.kind = 'finished';
