-- 021_category_on_till.sql (2026-10-18): a category says whether it is sold on the till. Ingredients and Packaging hold
-- raw materials only: they are needed for buying and packing, never as a tab on the till. Decided on the category's
-- page by the owner (Aya, 2026-10-18); "Active" keeps its own meaning (can be chosen for products at all).
ALTER TABLE categories ADD COLUMN on_till TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
-- what exists today: a category with no finished product in it is not for the till
UPDATE categories c SET c.on_till = 0
 WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.kind = 'finished');
