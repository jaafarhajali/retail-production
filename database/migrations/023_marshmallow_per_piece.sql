-- 023_marshmallow_per_piece.sql (2026-10-18): what one piece of a finished product holds, in grams of marshmallow
-- (products.fill_base, the same field as a custom box's usual weight). Packing adds it up and fills "Marshmallow used",
-- and warns when the two disagree (Aya typed 177 pieces holding 27 kg for 3 kg used). Filled here from the names the
-- products already carry ("Joy 100g" → 100, "Custom Box 0.5 kg" → 500); the owner checks them on each product's page.
UPDATE products
   SET fill_base = CASE
         WHEN name REGEXP '[0-9]+([.][0-9]+)? ?kg([^a-z]|$)'
           THEN ROUND(CAST(REGEXP_REPLACE(REGEXP_SUBSTR(name, '[0-9]+([.][0-9]+)? ?kg'), '[^0-9.]', '') AS DECIMAL(10,3)) * 1000)
         ELSE CAST(REGEXP_REPLACE(REGEXP_SUBSTR(name, '[0-9]+ ?g([^a-z]|$)'), '[^0-9]', '') AS UNSIGNED)
       END
 WHERE kind = 'finished' AND base_unit = 'piece' AND fill_base IS NULL
   AND (name REGEXP '[0-9]+([.][0-9]+)? ?kg([^a-z]|$)' OR name REGEXP '[0-9]+ ?g([^a-z]|$)');
