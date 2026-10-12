-- 012_production.sql — Phase F2 (2026-10-13): recipes and production runs.
-- A recipe says what one batch of a product (a mix or a finished product) is made of. A production run makes a
-- quantity of it: the materials (or the mix) go out of stock at their cost of the moment, the product comes in at
-- that cost, and the product's cost per unit becomes the moving average of its runs. Records are never edited.
CREATE TABLE recipes (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id  INT UNSIGNED  NOT NULL,
  name        VARCHAR(120)  NOT NULL,
  output_base INT           NOT NULL,                 -- what one batch makes, in the product's base units (1000 g, 50 pieces)
  notes       VARCHAR(255)  NULL,
  is_default  TINYINT(1)    NOT NULL DEFAULT 1,
  is_active   TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recipes_product (product_id),
  CONSTRAINT fk_recipes_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recipe_lines (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  recipe_id   INT UNSIGNED  NOT NULL,
  material_id INT UNSIGNED  NOT NULL,                 -- a raw material, packaging, or a mix
  qty_base    INT           NOT NULL,                 -- per batch, in the material's base units
  PRIMARY KEY (id),
  KEY idx_rl_recipe (recipe_id),
  CONSTRAINT fk_rl_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE CASCADE,
  CONSTRAINT fk_rl_material FOREIGN KEY (material_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE production_runs (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  run_no            VARCHAR(20)   NOT NULL,
  product_id        INT UNSIGNED  NOT NULL,
  recipe_id         INT UNSIGNED  NOT NULL,
  qty_base          INT           NOT NULL,            -- produced, in the product's base units
  batches           DECIMAL(14,4) NOT NULL,            -- qty_base ÷ the recipe's output
  material_cost_usd DECIMAL(12,2) NOT NULL,
  extra_cost_usd    DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- labour, packaging, overhead: production_costs (later)
  total_cost_usd    DECIMAL(12,2) NOT NULL,
  cost_per_base     DECIMAL(12,6) NOT NULL,
  user_id           INT UNSIGNED  NOT NULL,
  notes             VARCHAR(255)  NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_runs_no (run_no),
  KEY idx_runs_product (product_id),
  KEY idx_runs_created (created_at),
  CONSTRAINT fk_runs_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_runs_recipe FOREIGN KEY (recipe_id) REFERENCES recipes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE production_lines (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  run_id        INT UNSIGNED  NOT NULL,
  material_id   INT UNSIGNED  NOT NULL,
  qty_base      INT           NOT NULL,
  cost_per_base DECIMAL(12,6) NOT NULL,                -- the material's cost at that moment, frozen
  cost_usd      DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pl_run (run_id),
  KEY idx_pl_material (material_id),
  CONSTRAINT fk_pl_run FOREIGN KEY (run_id) REFERENCES production_runs (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pl_material FOREIGN KEY (material_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE production_costs (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  run_id     INT UNSIGNED  NOT NULL,
  kind       ENUM('labor','packaging','electricity','overhead','other') NOT NULL,
  amount_usd DECIMAL(12,2) NOT NULL,
  note       VARCHAR(255)  NULL,
  PRIMARY KEY (id),
  KEY idx_pc_run (run_id),
  CONSTRAINT fk_pc_run FOREIGN KEY (run_id) REFERENCES production_runs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_movements MODIFY type ENUM('opening','purchase','sale','sale_void','return_restock','waste','adjustment','stocktake','production_in','production_out') NOT NULL;

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('production.view', 'See recipes and production runs', 'Production', 40),
('production.manage', 'Write recipes and run production', 'Production', 41),
('report.production', 'Production report', 'Reports', 42);

INSERT INTO counters (name, next_value) VALUES ('production', 1);
