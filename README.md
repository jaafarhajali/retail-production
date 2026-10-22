# Retail Production

Stock, production and sales system for a small food factory that makes its own products and delivers them to shops
and supermarkets (Malika Marshmallow, Lebanon). USD prices, USD + LBP payments, cash sessions with X/Z reports.

It is the factory edition of Retail POS: the same till, customers, cash and reports, plus what a factory needs.

```
Suppliers → raw materials → Production (recipe → mix) → Packing (mix → products, the split typed each time) → the till, or a delivery invoice
held on the till → the seller visits the customer → Finish: what came back, what was paid, the rest on
the customer's account → dashboard, products by month, break-even
```

- **Three kinds of product**: finished (sold), mix (made, used by finished products), raw material or packaging (bought).
  Two separate stocks, two tabs everywhere.
- **Recipes and production**: a recipe says what one batch of the mix is made of; a run makes it in batches, takes the
  ingredients out and puts the mix in at their cost. **Packing** then takes the mix (and the boxes) out and puts the
  finished products in: how many of each is typed each time, never fixed in a recipe; the cost is shared by selling price.
  Each product's **packaging per piece** (a box, a label) comes out of stock by itself when it is packed.
- **Delivery invoices on the till**: Hold as invoice and print, goods go out with the seller, Finish when back.
- **Desk invoices for B2B customers** (Selling → Invoices): written by the owner with no cash session, due after the
  customer's payment terms, agreed prices per customer, payments by transfer or cheque applied to the oldest invoice
  first, credit notes for goods that come back, a statement of account to print, and a "who owes, by age" report.
- **Three prints**: the official A4 invoice (TVA, due date, boxes, signatures), the delivery note and the credit note.
- **Dashboard**: the business as a flow, the month's sales, who owes, production, the two stocks, break-even.

Design and decisions: `docs/plans/2026-10-12-factory-analysis-and-plan.md`, `docs/ROADMAP.md` (decisions log),
`docs/specs/` (the core design and the owner's documents in `docs/specs/factory/`).

## Requirements

XAMPP with PHP 8.2 (`extension=gd` for images and the logo, `extension=zip` for backups, both in `php.ini`) and
MariaDB 10.4, with Apache and MySQL started. No internet needed. Google Chrome and node 18+ only for the browser checks.

## Install (first time, or after pulling new code)

Create `config/app.ini` (never committed) so this install has its own database:

```ini
[app]
env = production        ; dev while you test
timezone = Asia/Beirut
[database]
host = 127.0.0.1
port = 3306
name = retail_production
user = root
pass =
```

Then:

```bash
cd "/c/xampp/htdocs/Retail Production"
/c/xampp/php/php.exe bin/install.php --admin-password='choose-a-strong-password'
/c/xampp/php/php.exe bin/seed-factory.php        # the Malika catalogue: company, products, prices, materials, recipes
```

`install.php` creates the database, applies the migrations and creates the `admin` user; run it again after every
pull, it only applies what is new. The seed fills an empty database with the price list, the raw materials and
packaging, the mix and its recipe; correct the recipe quantities on the Recipes page before the first real production
run. The products are not in recipes: they are packed from the mix on the Packing page.

Open **http://localhost/Retail%20Production/**, or from another PC on the network
`http://<server-ip>/Retail%20Production/`. Check once that `http://localhost/Retail%20Production/.git/HEAD`
answers **403**: only `public/` may be served.

Upload the logo on Settings → Logo: it goes on the menu, the sign-in page, the receipts and both invoices.

## Backups

Run once on the server, as the Windows user that is normally signed in:

```bash
/c/xampp/php/php.exe bin/install-backup-tasks.php
```

It creates two Windows tasks: a backup every hour from 08:00 to 23:59 and one every night at 02:00 (the one of the
1st is kept as the month's backup). On the Backups page, set the folder of a USB stick: every backup is copied there,
and the dashboard turns amber when the stick is missing or the last backup is old. On the same page, **Google Drive**:
paste the OAuth client made once in Google Cloud (type Web application, redirect URI shown on the page), press Connect
on the server itself, and every daily and monthly backup is uploaded to a folder in that Google Drive. `bin/restore-drill.php` proves a
backup restores; run it after installing and once a month. `bin/reset-data.php` backs up, empties the shop and seeds
the catalogue again (people, registers and settings stay).

## Tests

```bash
/c/xampp/php/php.exe tests/run.php            # everything (about five minutes)
/c/xampp/php/php.exe tests/run.php Production # files whose name contains "Production"
```

The tests use a separate `retail_production_test` database, rebuilt for every test, and start their own web server
on port 8190. Your real data is never touched. `tests/browser/` drives the till in headless Chrome
(see its README) on the same test database.

## Where things are

| Folder | What |
|---|---|
| `public/` | the only web-reachable folder (front controller + assets) |
| `app/routes.php` | every URL, with the permission it requires |
| `app/Core/` | router, auth, permissions (Gate), audit, database |
| `app/Services/` | business rules: sales, production, recipes, packing, returns, cash, dashboard… |
| `app/Models/` | SQL |
| `app/Views/` | pages; `sales/invoice.php` and `sales/delivery-note.php` are the two prints |
| `database/migrations/` | numbered schema changes; add new files, never edit old ones |
| `bin/` | install, seed, backup, backup tasks, restore drill, reset |
| `public/uploads/` | product images and the logo (git-ignored; in every backup) |
