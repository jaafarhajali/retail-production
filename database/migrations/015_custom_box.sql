-- 015_custom_box.sql — Phase F6 (2026-10-13): the Customized Box and notes on a sale line.
-- A line can carry a note typed at the sale (flavours, weight, "no nuts"); it prints on the receipt, the delivery note
-- and the official invoice. A product can be sold without counting stock (track_stock = 0): the Customized Box is
-- assembled on the spot from whatever is on the shelf, so selling it moves no stock of its own.
ALTER TABLE sale_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_name;
ALTER TABLE products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER price_floats;
