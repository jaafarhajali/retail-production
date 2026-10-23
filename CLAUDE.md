# Retail Production — instructions for Claude

**Retail Production is the factory edition**, forked from Retail POS on 2026-10-12 (owner's decision:
a separate folder and repository, `https://github.com/jaafarhajali/retail-production`). It starts as an exact copy
of Retail POS at commit 1ba80d0 and will grow the factory logic: raw materials, recipes, production runs, locations,
and the factory's own rules for returns, expenses and costing. Fixes made in Retail POS have to be brought here by hand
(the owner chose two repositories over one with editions). The spec and the plan are in `docs/plans/2026-10-12-factory-analysis-and-plan.md` (owner's documents in `docs/specs/factory/`); phases F1–F6 are built one at a time with the owner's go. **F1 (product kinds, two stocks), F2 (recipes, production) F3 (held delivery invoices on the till) F4 (official invoice and delivery note prints) F5 (dashboard, products by month, break-even) and F6 (Customized Box, line notes) are built**, 2026-10-13 (411 tests). The factory plan's six phases are done; what remains is the owner's browser check and the fixes from the logic study.
**2026-10-14: recipes make the mix only, and the new Packing page (`packing/new`, `PackingService`, migration 016) turns the mix into finished products with the quantities typed each time; the cost is shared by selling price. Each finished product lists its packaging per piece, taken out by itself. Desk invoices for B2B (`invoices*`, `InvoiceService`, migrations 018–019): no cash session, payment terms, agreed prices, desk payments applied FIFO, credit notes, statement, aging.**
**2026-10-15: one mix is the pot and its flavours are its recipes (decisions log); a mix is always counted by weight, the Mixes tab and the mix's page list its flavours (411 tests).**
**2026-10-15/16: the screens speak of Flavours (menu, Make asks flavour and quantity, mix stock on Make and Pack); with one mix the Mixes tab and the Mix choice are hidden, a new mix starts from Flavours → "Add a new mix". A mix's form asks name, category and the low-stock warning only (cost and stock come from Make; no photo, barcodes or target profit); a flavour must have a name.**
**2026-10-17: the two invoice types are named Regular invoice / Official invoice on every screen and print (stored as `normal` / `official`). The whole stylesheet follows the logo's palette (teal #1F7F74/#0F4A44, honey #EFB526, pink #D497B6, red for errors only); every colour is a token at the top of `public/assets/css/app.css`. Owner's way of working (2026-10-17): deliveries are written on the **Invoices** page (official / regular, paid cash at the door or later by card / Whish / transfer), the **Till** is for workshops; Record a payment takes **Cash** into the user's open session; the till's "Hold as invoice" is off unless the setting `till_delivery_invoices` is `1`. 2026-10-18: **attachments** (`AttachmentService`, table `attachments`, migration 020, `public/uploads/attachments/`): the supplier's invoice on a purchase, the receipt on an expense, photos re-encoded to 1600 px or PDFs, several per record. Categories carry `on_till` (migration 021): the till lists only categories marked for it that hold a sellable product (`Category::forTill`). The **custom order** (2026-10-21, migration 025, `CustomOrderService`): one built-in product `is_custom_order` never listed, a tile on the till and a line on the invoice form; at each sale the price, the grams of marshmallow, the packaging taken from the stock (`sale_item_packaging`) and the extras are typed; `SaleService` costs the line and `fulfil` takes the grams and the packaging out of stock; no stock of its own, a return puts nothing back. `fill_mix_id`/`fill_base` (migration 022) remain the mechanism; no product is "filled at the sale" any more. `fill_base` is also every finished product's **marshmallow per piece** (migration 023, filled from names): Packing adds it up, fills the mix used and warns over a 5% gap. Flavour lines are written in grams by default (unit 0 = base unit). 2026-10-20: the **dashboard is the break-even only** (`DashboardService`, table `fixed_costs`, migration 024): the **estimated** fixed costs typed there by the administrator and the **actual** ones (the month's expenses, supplier payments left out) with their history, a Share / Need to sell / Sold / Left table per product (each product's share of the fixed costs = its share of last month's profit), and the month's money (sales, variable costs, fixed costs, net profit). A lowered or agreed price is saved as the list price and a discount (Reports → Discounts). A sale beyond the stock needs the PIN on the till and is refused on a desk invoice. 2026-10-22: **daily backups on Google Drive** (`GoogleDriveService`, OAuth client + Connect on the Backups page, `public/google-callback.php`; the tests use a fake transport), in both editions.**

Retail POS is a point-of-sale and stock system for an Argile / Hookah shop in Lebanon.
Prices are in USD; customers pay in USD and/or LBP. It runs on XAMPP on a local
network: one server, one or more touch-screen POS terminals with a USB barcode
scanner and a receipt printer with a cash drawer.

## Read these first, every session

1. `docs/ROADMAP.md`: phase status, the scope of every phase, and the **decisions log**
   (every business decision the owner made, with dates). Do not re-ask what is already decided there.
2. `docs/specs/2026-09-24-core-design.md`: the **approved design**, the source of truth for
   the database, money and cash rules, returns, stocktaking and permissions.
3. `docs/plans/`: one implementation plan per phase. Execute the plan of the current phase.

## How we work (agreed with the owner)

- **One phase at a time:** plan → owner reviews the plan → implement task by task →
  owner checks it in the browser → mark it done in `docs/ROADMAP.md` → next phase.
- **Never write product code for a phase without an approved plan.** Write the next
  plan from the spec only after the previous phase is finished, because plans depend on the
  exact names that earlier phases created.
- **Claude writes every phase plan** (Phases 2–10), in a session in this folder, when
  the owner says the previous phase works. Steps: read the spec sections listed for
  that phase in `docs/ROADMAP.md` and the code that already exists, ask the owner that
  phase's "Decide before the plan" questions, write
  `docs/plans/YYYY-MM-DD-phaseN-<name>.md` in the same format as the Phase 1 plan, and
  wait for the owner's approval before coding. The owner only answers questions and approves.
- Inside a plan: tests first, each task ends with all tests passing and one commit.
  Don't skip a failing test; fix the cause.
- A business-rule question that the spec and the decisions log don't answer →
  **ask the owner**, don't guess. Record the answer in the decisions log.
- Screens get a short screen spec (with a mockup) before their plan, especially the POS screen (Phase 4).
- **Since 2026-09-24 the owner works by request** (decisions log): v1 was built in one continuous
  run, and changes now arrive as messages. A plain instruction ("add X", "fix Y") is implemented
  directly, with tests, and committed. A question ("how to solve X?") gets an answer and
  options first; nothing is edited until the owner chooses. Every decision goes in the log.

## Environment

- Project path has a **space**: `C:\xampp\htdocs\Retail POS`. Always quote it.
  Git Bash: `cd "/c/xampp/htdocs/Retail POS"`.
- PHP 8.2.12: `/c/xampp/php/php.exe`. MariaDB 10.4 on `127.0.0.1:3306`, user `root`,
  empty password (XAMPP → MySQL must be started). Dev database **`retail_production`** (set in `config/app.ini`,
  a copy of `retail_pos` taken 2026-10-12); the test suite drops and rebuilds **`retail_production_test`** for every test (its own, so both suites may run at once; the browser harness in `tests/browser` uses it too).
- Tests: `/c/xampp/php/php.exe tests/run.php [Filter]`. HTTP tests start their own
  server on port 8190.
- No Composer, npm or CDN. Vendor assets are local in `public/assets/vendor/`.
- `C:\xampp\php\php.ini` needs `extension=gd` (product images, logo) and `extension=zip` (backups).
  Apache reads it only when it starts.
- `php bin/seed-factory.php` fills an empty database with the **Malika factory catalogue** (company, price list, materials, mix); the dev database holds it since 2026-10-13. `php bin/reset-data.php` backs up,
  then empties the shop (keeps users, registers, settings, rate) and seeds again. `php bin/restore-drill.php`
  proves a backup restores; it only reads the live database. Test-suite backups go to `storage/backups/test`.
  `php bin/install-backup-tasks.php` creates the one Windows task (daily at the Backups page time, hidden, `bin/backup.php --auto`); the setup's copies run a service instead.

## Rules that must never be broken

- UI text in **English only**. User data may be **Arabic**: utf8mb4 everywhere, `dir="auto"` on name fields.
- Money is `DECIMAL` (USD `DECIMAL(12,2)`, LBP whole numbers), never float in the database.
  Stock is an **integer of base units** (piece / gram / ml).
- PHP and the MariaDB session use the same timezone (`SET time_zone` on connect).
- PDO native prepares: a named placeholder appears **once** per SQL statement (`:q1`, `:q2`).
- Every route in `app/routes.php` declares its access (`Router::GUEST`, `Router::AUTH`
  or a permission key). The Router enforces CSRF on every POST. Never check
  permissions only in the frontend.
- Financial records (sales, payments, returns, cash movements, ledgers, stock movements,
  Z reports, audit log) are **append-only**. Corrections are new rows.
- Stock changes only through `StockService` (from Phase 3). Payments are stored in their
  original currency plus the USD value at the transaction's snapshot rate.
- Invoice discounts are spread across lines, so returns refund what was really paid.
- Never commit backups, logs or `config/app.ini` (`.gitignore`).
- Before a test-install of anything that touches a real installation, check it is not the owner's live system.

## Owner

- Works in VS Code on Windows, writes informal English, and prefers clear answers with
  concrete numeric examples and a recommendation instead of a list of options.
- Tests features manually in the browser after each phase.
- Same author as **Daher Phone** (`C:\xampp\htdocs\daher store`), a repair-shop system
  in the same code style. Its audit lessons are built into this project's rules above.

## Current status

See the phase table and the **Open items** in `docs/ROADMAP.md`. As of 2026-09-28: all ten phases
are built (411 tests); Phase 1 is Done, Phases 2–9 are Implemented and wait for the owner's
browser check; backups work and a restore drill passed. What remains is mostly the owner's:
hardware, LAN, the nightly backup task, the administrator's PIN and production mode.
