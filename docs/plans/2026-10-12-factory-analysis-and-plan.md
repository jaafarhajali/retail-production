# Retail Production — analysis of the existing system and the plan for the factory edition

Written 2026-10-12 from the owner's business description (home-based food manufacturing: raw materials → production → finished
products → distribution to shops and supermarkets). Nothing in this document is built yet. The owner approves it, then the
phases are built one at a time, tests first, one commit per task, as always.

## 1. What exists today (Retail POS at commit 1ba80d0, 334 tests)

| Area | What it does | Where |
|---|---|---|
| Products | One table, base unit piece / g / ml, stock as an integer of base units, cost per base unit (moving average), several **units** per product (Pack 250g, Box of 6, kg) each with a **retail and a wholesale price**, barcodes, images, "price floats" | `products`, `product_units`, `barcodes`, `ProductService`, `StockService` |
| Purchases | From a supplier, lines in any unit, updates stock and the moving-average cost, supplier ledger, "paid now" | `purchases`, `purchase_items`, `supplier_ledger`, `PurchaseService` |
| Stock | Every change is a `stock_movements` row (opening, purchase, sale, return_restock, waste, adjustment, stocktake); stock page, low-stock, stocktaking with count sheets | `StockService`, `CountService` |
| Till | Touch screen, barcode scan, cart, **retail / wholesale switch per sale**, line and invoice discounts with the admin PIN, price override (permission + per product), hold a cart (JSON, same register), customer with debt, payments in USD and LBP, cash, card, credit | `pos.js`, `PosController`, `SaleService` |
| Sales | `sales` (status completed / voided), `sale_items` (price, discount, cost frozen per line), `sale_payments`, receipt print | `SaleController` |
| Customers | Ledger: sale on credit (+), payment (−), return credit (−), adjustment, price adjustment; balance = SUM; credit limit; debt collected at the till in USD/LBP with change | `customer_ledger`, `CashService::collectDebt`, `DebtService` |
| Returns | Against an invoice (per line, restock or waste, refund in cash or off the debt) or without an invoice | `returns`, `return_items`, `return_refunds`, `ReturnService` |
| Cash | Register per device, cash session per cashier, every cash event is a movement, blind count, X/Z report, admin review | `cash_sessions`, `cash_movements`, `CashService` |
| Expenses | Category, USD/LBP, from the drawer or outside; supplier payments | `expenses`, `ExpenseService` |
| Reports | Sales by day/product/customer/cashier, payments, profit, credit (debtors), returns, waste, purchases, sessions, today | `ReportService`, `reports/*.php` |
| Admin | Users, roles with 40 permissions, registers, exchange rate, settings, logo, audit log, backups (hourly/daily/monthly + USB) | |

The architecture: plain PHP micro-MVC. `app/routes.php` (every route declares its access), controllers, **services hold every
business rule**, models are thin SQL, views are PHP. Tests in `tests/` (service tests and HTTP tests through a real server).

## 2. What is reused as it is

- **Everything in the table above.** The factory edition keeps the till for direct sales, the customers and their ledger,
  the cash sessions, the returns engine, the expenses, the backups, users, roles, audit.
- **Raw materials are products.** A product gets a *kind*: `finished` (what is sold) or `material` (what is bought and
  consumed). Honey is then a product of kind material, base unit g, bought in "Jar 1kg" and "Bucket 5kg" units, counted
  in grams, with the same stock movements, the same low-stock warning, the same stocktaking, the same moving-average cost
  that a purchase already updates today. This is what gives two separate stocks without writing a second stock system:
  every screen filters by kind, every service refuses the wrong kind (the till never shows a material, a purchase never
  accepts a finished product, production only consumes materials and only produces finished products).
- **Wholesale and retail prices** already exist per unit, and the till already switches the whole sale between the two
  levels; a customer carries a default level. Nothing to build.
- **Price override** with permission and per-product flag already exists.
- **Returns** already put stock back and credit the customer; the settlement of a delivery reuses them.
- **Debt** already flows through the customer ledger; "outstanding balance" is the ledger SUM.

## 3. Database tables that change

| Table | Change | Why |
|---|---|---|
| `products` | `kind ENUM('finished','material') NOT NULL DEFAULT 'finished'` | the two stocks |
| `sales` | `status` gains `on_hold` and `settled` next to `completed` and `voided`; `invoice_type ENUM('normal','official')`; `official_no VARCHAR(20) NULL`; `delivered_at`, `settled_at`, `salesperson_id` | the delivery workflow and the two invoice formats |
| `stock_movements.type` | gains `production_in` (finished +) and `production_out` (material −) | production is its own line in every stock history |
| `permissions` | `production.manage`, `production.view`, `report.production`, `invoice.official`, `delivery.settle` | who may do what |
| `counters` | `production` (PRD-000001), `official` (the official invoice sequence) | numbering |
| `settings` | `monthly_fixed_costs_usd`, company legal details for the official invoice (registration number, VAT number, address lines as the template needs) | break-even and the official invoice |

Nothing is removed. Existing rows keep working: every existing product is `finished`, every existing sale is `completed`.

## 4. New tables

```
recipes              id, product_id (finished), output_base_qty (e.g. 1000 g or 1 piece), name, notes, is_active, created_at
recipe_lines         id, recipe_id, material_id (product of kind material), qty_base (grams/ml/pieces for one output batch)
production_runs      id, run_no (PRD-000001), product_id, recipe_id, qty_base produced, batches (decimal: produced ÷ recipe output),
                     material_cost_usd, extra_cost_usd (0 for now), total_cost_usd, cost_per_base, user_id, notes, created_at
production_lines     id, run_id, material_id, qty_base consumed, cost_per_base at that moment, cost_usd
production_costs     id, run_id, kind ('labor','packaging','electricity','overhead','other'), amount_usd, note
                     -- empty in the first version; the run total already adds it, so labour and overhead plug in later
```

Delivery settlements reuse `sale_payments`, `returns` and `customer_ledger`; one small table records the settlement itself:

```
sale_settlements     id, sale_id, settled_by, settled_at, original_usd, returned_usd, net_usd, paid_usd, balance_usd, notes
```

## 5. The stock flow

```
Supplier ──purchase──▶ MATERIAL stock (grams / ml / pieces of honey, sugar, colouring, jars, boxes)
                              │
                     production run (recipe × batches)
                              │  production_out: − each material at its current cost
                              ▼
                       FINISHED stock (grams or pieces of Joy 100g, Marshmallow Stick…)
                              │  production_in: + produced quantity, cost = materials (+ extra costs later)
                              │
            ┌─────────────────┴──────────────────┐
     till sale (walk-in)                 delivery invoice (on hold → settled)
            │                                     │
         sale: −                         sale: − when the goods leave the house
                                         return_restock: + what the customer refused
```

Two stock pages: **Raw materials** (kind material, with "days of stock" from the recipes' average consumption) and
**Finished products** (kind finished). Stocktaking gets the same kind filter. Low-stock lists split the same way.

## 6. How production connects materials to finished products

- A **recipe** belongs to one finished product and says: *this list of materials makes this much output*. "1 kg Raspberry
  Marshmallow = 200 g honey + 10 g colouring + 300 g B + 490 g C". A product may have several recipes (summer / winter
  version); one is the default.
- A **production run** asks: which product, how much (in any of its units: 5 kg, or 50 Joy 100g), optional notes. The
  system shows the materials it will consume (recipe × batches), in red when stock is short, and the cost it will book.
  Confirm → one transaction: material movements out, finished movement in, cost of the finished product updated by moving
  average exactly like a purchase does today, run and lines written, audit row.
- **Cost of a run** = Σ material qty × that material's cost per base at that moment (frozen in `production_lines`, so a
  later price change never rewrites history) + extra costs (table exists, UI later). Cost per unit produced = total ÷ qty.
  The finished product's `cost_per_base` becomes the moving average of its production runs, so the profit report and the
  below-cost warning keep working unchanged.
- **Yield**: the first version books exactly what the user types as produced. If 5 kg of materials gave 4.8 kg, the user
  types 4.8 and the cost per kg is higher; the recipe's theoretical output is shown next to it so the loss is visible.
  Waste of materials is a stock adjustment, as today.
- **History**: a Production page lists every run (number, date, product, quantity, cost, user) with a detail page showing
  the lines consumed; a report gives production by product and material consumption per period.

## 7. The delivery workflow: invoice → on hold → visit → payment and returns → settled

Today the till has "Hold" (a cart in JSON, resumed on the same register, no stock movement, no number). That stays for
walk-in customers. The distribution flow needs a real invoice that leaves the house with the goods, so it is built on
`sales`, not on `held_sales`:

1. **Prepare**: a new screen "Delivery" (not the touch till): customer (required), price level (retail/wholesale from the
   customer's default, changeable), lines in any unit, discounts under the same PIN rules, invoice type normal/official,
   salesperson. **Save as on hold** → the sale gets its invoice number, the finished stock goes **out now** (the goods are
   leaving), status `on_hold`, no payment, nothing in the customer ledger yet. Print: the normal invoice or the official one.
2. **Visit**: nothing in the system.
3. **Settle**: open the on-hold invoice from the Deliveries list. The screen shows the lines and asks:
   returned quantities per line (→ a `returns` record through the existing engine: stock back, value computed from what
   the invoice charged), then the payment received (cash USD/LBP, card) into the salesperson's open cash session, the rest
   as **credit** on the customer ledger. Result box: original total, returns, net, paid, outstanding, status. Confirm → the
   sale becomes `settled`, `sale_settlements` written, payments and ledger rows written, audit.
4. **Statuses** shown everywhere: `on hold` (out with the salesperson), `settled · paid`, `settled · partially paid`,
   `settled · unpaid`, with "returned" / "partially returned" as a second badge when a return exists. A customer who pays
   later uses the existing "Collect debt" (till) and the customer page.
5. **Cash and Z**: the settlement payments are cash movements in the session of the user who settles, so the drawer and
   the Z report stay exact. A delivery settled with no open session is refused, like a till sale.
6. **Void**: an on-hold invoice that never left (prepared by mistake) can be cancelled before the visit: stock back, status
   `voided`, number kept. After settlement nothing is edited; corrections are returns and ledger adjustments, as today.
7. **Customer profile** gains totals: sales, payments, returns, balance, and the lists of invoices and payments.

Example from the owner: invoice $500 on hold; customer pays $400 and returns 2 boxes ($50). Settlement: original 500,
returned −50, net 450, paid 400, outstanding 50 → `settled · partially paid · partially returned`; the customer's balance
rises by 50; the drawer has +400.

## 8. Official invoice

A second print template, `sales/official.php`, built from the owner's template (to be provided): company legal header,
customer block, numbered official sequence (`official_no`, its own counter, never reused), lines, totals, and whatever the
template carries (VAT, signatures, bank details). The choice normal/official is on the Delivery screen and on the till's
payment dialog; the Sales list shows the type; reprint keeps it.

## 9. Dashboard, monthly product sales, break-even

- **Dashboard** rebuilt for this business: sales today / this month, wholesale vs retail, invoices, paid vs unpaid, total
  outstanding with the top debtors; production this month by product, materials consumed; the two stock levels with
  low-stock counts; break-even box.
- **Monthly product sales**: a report page with month picker (and a from/to range), filters by level, product, customer,
  salesperson: product, quantity sold (net of returns), revenue, cost, margin. The dashboard shows the current month's top
  of it.
- **Break-even**: setting "monthly fixed costs" (typed, or the sum of expenses in categories ticked as fixed); variable cost
  per unit = the finished product's production cost; contribution margin = average selling price of the month − average
  cost; shown as: fixed costs, average margin per unit, units to break even, units sold so far, remaining, break-even
  revenue, current revenue, estimated profit, % reached, with a plain note that it is only as good as the cost data.

## 10. Phases

| Phase | Content | New permissions | Migration |
|---|---|---|---|
| F1 | Product kind; two stock pages; purchases accept materials only; till and delivery accept finished only; stocktaking and low-stock by kind | — | 011 |
| F2 | Recipes (page per finished product), production runs (screen with the material preview), production history, cost per run and moving-average cost of the finished product, production report | production.view, production.manage, report.production | 012 |
| F3 | Delivery invoices: prepare, on hold, print, settle with returns and payments, cancel before delivery, statuses, customer profile totals, deliveries list | delivery.settle | 013 |
| F4 | Official invoice: type, counter, template from the owner's design, choice on delivery and till | invoice.official | 014 |
| F5 | Dashboard, monthly product sales report, break-even, production and stock reports | — | 015 (settings) |
| F6 | Customized Box and flavours/packaging per product (after the decisions below) | — | 016 |

Each phase: tests first, browser check by the owner, decisions in `docs/ROADMAP.md`.

## 10b. What the owner's documents say (received 2026-10-12, filed in `docs/specs/factory/`)

**The company**: Malika For Trading / Malika Marshmallow, Lebanon, Arab Salim, main road; phones +961 70316708 and
+961 76564645; malikamarshmallow@gmail.com; MOF 3900717. Logo: `logo-malika-marshmallow.jpeg` (teal, pink, honey).

**Official invoice** (`official-invoice-300017.pdf`, A4, numbered 300017): company header with logo and MOF; "Bill To"
block with Name, TVA, Address, Phone; Invoice No., Date of Issue, **Due Date** (45 days after issue in the sample);
line table **Item, Box, Qty in Box, Code (barcode), Description, Total Qty, Unit Price, Amount**; Subtotal, Discount,
Total; "Thank you for your business!"; signature lines **Recipient / المستلم** and **Deliverer / المسلّم**. So the
official invoice needs: company legal settings (name, address, phones, email, MOF), customer TVA number and address,
its own number sequence, a due date, and lines expressed in boxes × quantity per box, which is exactly what product
units (Box of 100) already carry.

**Normal invoice** (`delivery-note-1591.jpeg`, the paper form "INVOICE & Delivery Note" No. 1591): date, **Delivery
charge yes/no**, Invoice to: name, phone, location; **Shipping full address**; lines No, Item Description, Qty, Price,
Total; Sub Total, **Delivery**, Total; contact line and QR; items may be **Free** (price 0). So the normal invoice needs
a delivery charge line, a shipping address on the sale, and a price of zero allowed for a line (a gift) under the
price-override rule.

**Price list** (`owner-notes-prices-and-flow.jpeg`), wholesale / retail in USD: Stick 25g 0.65 / 0.80; Bags 0.65 / 0.80;
Joy 100g 2.50 / 3.00; Joy 200g 5.00 / 6.00; Mini Joy 100g 3.20 / 4.00; Excellence 100g 3.00 / 3.80; Pistachio 100g
3.25 / 4.00; Choco Dream 100g 3.25 / 4.00; Gold 100g 3.50 / 4.00; **Customized: name and price typed at the sale**.
These become the seed of the finished products (barcodes from the official invoice: Stick 850009611772, Joy 100g
850009611789, Mini Joy 850009611819).

**The owner's flow notes** (Arabic): two kinds of invoices, normal and official; **the hold is wanted on the till**:
"on the till, hold, print the receipt, and when back finish it"; a stock of products and a stock of raw materials;
production: "produce → a quantity, e.g. 1 kg → raw materials A, B, C, D, E", example 5 and 4 (produce 5, materials
scale); BEP monthly; every item's sold quantity and total per month.

**What this changes in the plan above**

- F3 is built **into the till**, not a separate Delivery screen: Hold becomes a real invoice (number, stock out, printable
  as normal or official), the Held list shows the invoices out with the salesperson, and "Finish" opens the settlement
  (returns, payment received, remainder as debt). The old JSON hold disappears; every hold is an invoice.
- The normal invoice print gets the delivery-note layout (A5/A4, as the paper form) next to the thermal receipt; the sale
  gets `delivery_charge_usd` and `shipping_address`.
- A line price of **0** is allowed (gift) on products that allow override, logged like any override.
- Customized Box = a product "Customized" whose name detail and price are typed at the sale (price override allowed,
  stock not tracked, a note on the line for flavours and weight). The box's cost is estimated from its weight × the mix
  cost when the mix exists (question 1 below).
- The official invoice gets its own counter starting where the paper one stopped (the owner gives the number, e.g. 300018),
  a due date (default 45 days, changeable), and the customer record gets **TVA number** and **shipping address**.
- Company settings gain MOF number, email, a second phone, and the official-invoice header lines.

## 11. Decide before F1 (the owner answers, the answers go in the decisions log)

1. **Finished products: counted in pieces or in grams?** Joy 100g is a piece (a box) made from 100 g of marshmallow.
   Proposal: the recipe produces grams of marshmallow *mix*, and the finished products are pieces whose recipe line is
   "100 g of mix + 1 box + 1 label". That needs the mix itself as a product of a third kind, `intermediate`. Alternative:
   skip the intermediate and write each finished product's recipe directly in raw materials. Which one matches how you work?
2. **Customized Box**: sold by weight at a price per kg with the flavours typed in a note, or as a fixed list of box sizes
   (250 g, 500 g, 1 kg) each with its price? Does the box take stock out of specific finished products, or out of the mix?
3. **A held invoice takes the stock out when it is saved** (the goods leave the house). Agreed? Or only at settlement?
4. **Who settles**: the salesperson himself at home with his own cash session, or the owner? Does the salesperson need the
   admin PIN for returns at settlement (today a cashier needs it)?
5. **Official invoice**: please send the template. Does it carry VAT? Its own numbering (OF-000001) or the same invoice number?
6. **Fixed costs** for break-even: one number you type each month, or the sum of expense categories you mark as fixed
   (rent, salaries, electricity)?
7. **LBP**: do the distribution customers pay in LBP too, or USD only?
8. **Salesperson per invoice**: a user of the system (so his sales are reported) or just a name?
