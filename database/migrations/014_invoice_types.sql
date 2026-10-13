-- 014_invoice_types.sql — Phase F4 (2026-10-13): two invoice prints. A sale is a normal invoice (the delivery note the
-- seller carries) or an official one (the company's A4 invoice with TVA, due date and signatures). The customer record
-- carries what the official invoice needs: the TVA number and the address.
ALTER TABLE sales ADD COLUMN invoice_type ENUM('normal','official') NOT NULL DEFAULT 'normal' AFTER price_level;
ALTER TABLE customers
  ADD COLUMN tva_number VARCHAR(30)  NULL AFTER phone,
  ADD COLUMN address    VARCHAR(255) NULL AFTER tva_number;
