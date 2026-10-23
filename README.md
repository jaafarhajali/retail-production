<p align="center"><img src="public/assets/brand/mark.svg" width="96" height="96" alt="Retail Production"></p>

# Retail Production

Stock, production, invoicing and sales for a small factory that makes its own products and sells them to shops, supermarkets and at the door. Prices in US dollars, payments in dollars, Lebanese pounds or both, cash drawers counted at the end of every shift, and backups that run by themselves. It runs on one PC and is used from any PC, tablet or touch screen on the network, in a browser.

It is the factory edition of Retail POS: the same till, customers, cash sessions, reports and backups, plus what a factory needs. This file is the user's guide: how to install it, the first day, and how every page is used. Technical notes are at the end.

```
Suppliers → raw materials and packaging → Make (a recipe makes the mix) → Packing (the mix becomes products)
→ the till, or a delivery invoice → the seller comes back → Finish → customer accounts → dashboard, break-even
```

---

## 1. Installing

### On the factory's PC (the normal way)

1. Copy `RetailProduction-Setup-<version>.exe` to the PC and run it. Windows asks to allow changes: Yes.
2. Keep the folder it proposes, `C:\RetailProduction`.
3. Type the company name and the administrator's password (8 characters or more, write it down).
4. When it finishes it opens the browser on the sign-in page and puts **Retail Production** on the desktop.

The setup installs everything the program needs and starts it with the PC: the web server, the database, PHP and a **backup service**. No XAMPP, no internet needed. If another program already uses the web port, the setup takes the next free one (`http://localhost:8080/`) and the shortcut knows it.

### Other PCs, tablets and the seller's laptop

They need nothing installed: open `http://<the server's IP>/` in the browser (give the server a fixed IP in the router). Add the page to the desktop.

### Updating and uninstalling

Run the newer setup on the same PC, same folder: the data, the settings, the logo and the backups stay; the program is replaced and the database brought up to date. Uninstall from Windows → Settings → Apps; it asks whether to delete the data and backups too.

---

## 2. The first day

Sign in as **admin** with the password typed during the setup. Then, under **Administration**:

1. **Settings** — the company name, address, phones, email and MOF number (they print on the official invoice), the receipt texts, the LBP rounding step, the maximum discount a cashier may give without your PIN, the notes used to count a drawer, and the **logo**: upload it and it goes on the menu, the sign-in page, the receipts and both invoices. Until then the program shows its own mark.
2. **Exchange rate** — today's rate. Every payment is kept in its currency with its dollar value at that moment.
3. **Users** and **Roles & permissions** — one user per person; sellers and cashiers get the Cashier role. On the administrator's user set the **approval PIN** (4 to 6 digits) that a cashier types for anything that needs your approval.
4. **Registers** — one per till; on each till PC, **Link this device** once.
5. **Backups** — the USB stick's folder, the time of the daily backup, Google Drive if wanted. Section 9.
6. **Categories**, **Products** (raw materials and packaging first, then the finished products), **Flavours**: sections 4 to 6. `bin/seed-factory.php` can load a starting catalogue; correct it before the first real run.

---

## 3. Three kinds of product, two stocks

Every product is one of:

- **Raw material or packaging** — bought from suppliers: sugar, glucose, flavourings, jars, boxes, labels. Counted in its own units (kg, g, pieces), with a cost, never on the till.
- **Mix** — what Production makes from the raw materials, counted by weight. There is normally one mix (the marshmallow mix); its flavours are recipes of it. Its cost comes only from what went into it.
- **Finished product** — what is sold: a jar of 100 g, a box of 20 pieces, a custom box. It has selling prices, barcodes and a photo, and a **packaging per piece** list (the jar, the label, 10 g of pistachio paste…) that comes out of stock by itself when it is packed.

**Products** and **Stock** have tabs: Finished · Raw materials (and the mix). The dashboard shows low stock per kind.

---

## 4. Products

A product has a name (Arabic is fine), a kind, a category, a code, and for a finished product its **ways of selling** (Piece, Pack, Box… or by weight) each with a barcode, a retail and a wholesale price, plus the cost, the minimum stock, the opening stock, the photo and the packaging per piece. A product **assembled on the spot** (the Customized Box) moves no stock of its own; its contents do. A line on the till can carry a **note** (the flavours, the weight) that prints on the receipt and the invoice.

**Price floats** marks a product whose price moves during the day; such products are priced on **Today's prices**, every till follows within a minute, and returns and debts follow the rules in section 7.

---

## 5. Flavours and Make: production

**Flavours** (Stock → Flavours) shows the mix in stock at the top and one row per flavour. A flavour is a **recipe**: what one batch of it is made of, in any unit (3 kg of sugar, 200 g of strawberry flavouring…), and what one batch weighs. The page shows the batch's cost at today's material costs.

**Production → Make**: choose the flavour, the number of batches or the weight really obtained; the preview lists the materials that will go out. Confirm: the materials leave the stock at their cost, the mix comes in at that cost, and the run is kept with who made it. A material that is short stops the run before anything moves. Every run is listed with its cost per kilogram.

---

## 6. Packing: from the mix to products

**Packing** turns the mix into finished products. Type how many of each product were packed from how much mix: the mix goes out, the products come in, each product's packaging per piece (jars, labels, boxes, pastes) comes out by itself, and the cost of the mix and the packaging is shared between the products. Nothing is fixed in advance: the split is typed each time, because a packing can make single-flavour jars and mixed jars from the same pot.

---

## 7. Selling

### The two doors

- **The till** (a cash session, a drawer): sales at the door, workshops, the seller's laptop. Cash in dollars and pounds, card, credit; change in pounds rounded to 5,000 or in dollars; receipts printed from the browser (Chrome with `--kiosk-printing`, the receipt printer as default); hold a cart; returns; expenses from the drawer; the administrator's PIN for anything a cashier may not do alone.
- **Invoices** (Selling → Invoices): the owner's desk, no cash session. One invoice per delivery, regular or **official** (A4, with TVA, due date, boxes and signatures), at the customer's **agreed prices**, due after his payment terms. **Record a payment**: cash or card / Whish, in dollars or pounds, applied to the oldest invoice first. **Credit note** for goods that come back, at the invoice's own prices, back to stock or thrown away. **Statement of account** per customer, printable. **Customer debts**: who owes what and how late.

### Delivery invoices from the till

**Hold → Hold as invoice and print**: the cart becomes a numbered invoice printed as a delivery note, with no payment and no stock movement: the goods go out with the seller. The Hold dialog lists what is **out with the seller**. When he is back, **Finish**: what came back, what was paid, the rest on the customer's account; only then does the stock move.

### Returns, debts, price of the day

Returns on the till find the sale (latest sales listed, or search) or work **without an invoice** at today's price or less; the refund goes off a customer's debt first, the rest in cash. A customer's debt is collected at the till so it enters a drawer, or recorded at the desk by card. For a product whose price floats, a return refunds the lower of what was paid and today's price, and a customer who took it on credit pays the price of the day he pays.

### Cash sessions

Open a session with the money in the drawer; everything that moves cash is recorded in it; **Count and close** is a blind count note by note in both currencies; the Z report keeps expected against counted. An X report can be printed any time.

---

## 8. Money and reports

**Purchases**: raw materials and packaging from suppliers, stock up, cost as a moving average, the supplier's invoice attached; "paid now" from outside the drawer, the rest a debt to the supplier. **Suppliers**: balances and ledgers. **Expenses**: rent, electricity, salaries, from a drawer or from outside, with the receipt attached; supplier payments; **fixed costs** per month for the break-even. **Reports**: sales by day, product, customer and price level; products by month; payments; profit; stock value per kind; production and material use; customer debts by age; returns; waste. The **dashboard** shows the business as a flow: raw materials → production → finished stock → out with the seller → owed by customers, this month's sales, and the break-even point.

---

## 9. Backups: never lose the data

The **backup service** makes one backup a day at the time set on **Backups** (02:00 by default); if the PC was off, as soon as it is on. A backup is one zip: the database and the uploaded images and logo. The newest 30 daily and 24 monthly are kept. Set the **USB stick**'s folder and every backup is copied there; the dashboard turns amber when the stick is missing or the last backup is old. Connect **Google Drive** and every daily backup goes out of the factory too. **Back up now** at any moment; the bin on a file deletes it from the PC only.

**Restore**: choose a backup or upload a zip from the stick, type your password and RESTORE. Everything goes back to that moment; a safety backup is taken first; every cash session must be closed. On a new PC: install, then restore the newest zip.

---

## 10. When something goes wrong

| What you see | What to do |
|---|---|
| The browser cannot reach the program | Windows → Services on the server: `RetailProductionWeb` and `RetailProductionDB` must be running. Start them, or run the setup again. |
| "Something went wrong, error 500" | Settings → Check this installation; send its screenshot and `C:\RetailProduction\app\storage\logs\app.log`. |
| The dashboard says the backup is old | Windows → Services → `RetailProductionBackup` → Start; check the USB stick. |
| A cashier cannot approve something | No PIN on the administrator's user, or five wrong tries. |
| Receipts open as a page instead of printing | Chrome without `--kiosk-printing`, or the receipt printer is not the default. |
| A run is refused for a short material | Buy or adjust the material first; the run never moves a stock it cannot take. |

---

## 11. For developers

Plain PHP 8.2 with MariaDB 10.4, no framework, no Composer: `public/` is the only web-reachable folder, `app/routes.php` lists every URL with its permission, `app/Services/` the rules (sales, production, recipes, packing, invoices, returns, cash, dashboard), `app/Models/` the SQL, `app/Views/` the pages (`sales/invoice.php`, `sales/delivery-note.php` and the credit note are the A4 prints), `database/migrations/` the numbered schema. `docs/ROADMAP.md` is the decisions log with every rule and its date; `docs/plans/2026-10-12-factory-analysis-and-plan.md` the factory design; `docs/specs/` the core design and the owner's documents.

Development install on XAMPP: `config/app.ini` with `name = retail_production`, then `php bin/install.php --admin-password=...` and `php bin/seed-factory.php`. Tests: `php tests/run.php` on its own `retail_production_test` database and port 8190; `tests/browser/` drives the till in headless Chrome. The Windows setup: `tools/installer/build.ps1 -Product RetailProduction -DisplayName "Retail Production" -DefaultDir C:\RetailProduction -DbName retail_production`, see `tools/installer/README.md`.
