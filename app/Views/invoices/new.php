<?php
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$rows = [];
foreach (is_array($stash['lines'] ?? null) ? $stash['lines'] : [] as $l) {
    $rows[] = ['product_id' => (int) ($l['product_id'] ?? 0), 'unit_id' => (int) ($l['unit_id'] ?? 0), 'qty' => (string) ($l['qty'] ?? ''), 'pct' => (string) ($l['pct'] ?? ''), 'note' => (string) ($l['note'] ?? ''), 'fill' => (string) ($l['fill'] ?? '')];
}
$selectedCustomer = (int) ($stash['customer_id'] ?? 0);
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<?php if ($customers === []): ?>
  <div class="card"><div class="card-body"><p class="mb-2">An invoice needs a customer. Add the first one.</p><a class="btn btn-primary" href="<?= url('customers/edit') ?>">New customer</a></div></div>
<?php elseif ($catalogue === []): ?>
  <div class="card"><div class="card-body"><p class="mb-0">No finished product with a price yet.</p></div></div>
<?php else: ?>
<form method="post" action="<?= url('invoices/store') ?>" id="inv-form">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="customer_id">Customer</label>
            <select class="form-select form-select-lg" id="customer_id" name="customer_id" required>
              <option value="">— choose —</option>
              <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $selectedCustomer ? 'selected' : '' ?> data-level="<?= e($c['default_price_level']) ?>" data-terms="<?= (int) $c['terms_days'] ?>" data-address="<?= e($c['address'] ?? '') ?>" data-balance="<?= e($c['balance_usd']) ?>" data-agreed="<?= e(json_encode((object) $c['agreed'])) ?>" dir="auto"><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <div class="form-text" id="cust-note"></div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="price_level">Prices</label>
            <select class="form-select" id="price_level" name="price_level"><option value="wholesale" <?= ($stash['price_level'] ?? 'wholesale') === 'wholesale' ? 'selected' : '' ?>>Wholesale</option><option value="retail" <?= ($stash['price_level'] ?? '') === 'retail' ? 'selected' : '' ?>>Retail</option></select>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="due_date">Due date</label>
            <input class="form-control" type="date" id="due_date" name="due_date" value="<?= e($stash['due_date'] ?? '') ?>">
            <div class="form-text" id="due-note">Empty: <?= (int) $defaultTerms ?> days from today</div>
          </div>
          <div class="col-md-8">
            <label class="form-label" for="ship_to">Deliver to</label>
            <input class="form-control" id="ship_to" name="ship_to" dir="auto" maxlength="255" value="<?= e($stash['ship_to'] ?? '') ?>" placeholder="the customer's address, or another branch">
          </div>
          <div class="col-md-4">
            <label class="form-label d-block">Print as</label>
            <div class="btn-group" role="group">
              <input type="radio" class="btn-check" name="invoice_type" id="type-official" value="official" <?= ($stash['invoice_type'] ?? 'official') === 'official' ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="type-official">Official invoice</label>
              <input type="radio" class="btn-check" name="invoice_type" id="type-normal" value="normal" <?= ($stash['invoice_type'] ?? '') === 'normal' ? 'checked' : '' ?>><label class="btn btn-outline-primary" for="type-normal">Regular invoice</label>
            </div>
          </div>
        </div>
      </div></div>
      <div class="card mb-3">
        <div class="table-responsive"><table class="table align-middle m-0" id="lines">
          <thead><tr><th style="width:34%">Product</th><th style="width:16%">Sold as</th><th style="width:11%">Qty</th><th class="text-end">Price</th><th style="width:10%">Disc. %</th><th class="text-end">Total</th><th></th></tr></thead>
          <tbody></tbody>
          <tfoot><tr><td colspan="7"><button class="btn btn-outline-primary btn-sm" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add a line</button> <span class="form-text ms-2">Boxes or pieces; the stock shows under the product.</span></td></tr></tfoot>
        </table></div>
      </div>
      <div class="card mb-3"><div class="card-body">
        <label class="form-label" for="notes">Notes on the invoice</label>
        <input class="form-control" id="notes" name="notes" dir="auto" maxlength="255" value="<?= e($stash['notes'] ?? '') ?>" placeholder="optional, printed under the lines">
      </div></div>
    </div>
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <dl class="kv mb-3">
          <dt>Subtotal</dt><dd id="t-sub">$0.00</dd>
          <dt>Line discounts</dt><dd id="t-ldisc">$0.00</dd>
          <dt><label for="invoice_discount" class="mb-0">Discount on the total ($)</label></dt><dd><input class="form-control form-control-sm" id="invoice_discount" name="invoice_discount" inputmode="decimal" value="<?= e($stash['invoice_discount'] ?? '') ?>" placeholder="0"></dd>
          <dt class="fs-5">Total</dt><dd class="fs-5 fw-semibold" id="t-total">$0.00</dd>
        </dl>
        <p class="text-muted small">The whole amount goes on the customer's account until it is paid: by transfer or cheque here, or in cash at the till.</p>
        <button class="btn btn-primary btn-lg w-100" type="submit" id="inv-ok" disabled><i class="bi bi-check-lg"></i> Issue the invoice</button>
        <a class="btn btn-link w-100" href="<?= url('invoices') ?>">Cancel</a>
      </div></div>
    </div>
  </div>
</form>
<template id="line-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="lines[IDX][product_id]" required><option value="">— choose —</option><?php foreach ($catalogue as $p): ?><option value="<?= (int) $p['id'] ?>" dir="auto"><?= e($p['name']) ?></option><?php endforeach; ?></select><div class="form-text stock"></div>
      <div class="input-group input-group-sm mt-1 line-fill" hidden><input class="form-control" name="lines[IDX][fill]" inputmode="numeric" placeholder="grams" aria-label="Grams of marshmallow in one"><span class="input-group-text">g of marshmallow each</span></div>
      <input class="form-control form-control-sm mt-1" name="lines[IDX][note]" dir="auto" maxlength="255" placeholder="note on this line (flavours…)" aria-label="Note on this line"></td>
    <td><select class="form-select form-select-sm" name="lines[IDX][unit_id]" aria-label="Sold as"></select></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][qty]" inputmode="decimal" value="1" required></td>
    <td class="text-end text-nowrap price"></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][pct]" inputmode="decimal" placeholder="0"><input type="hidden" name="lines[IDX][discount]"></td>
    <td class="text-end text-nowrap total"></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var catalogue = <?= json_encode($catalogue, JSON_UNESCAPED_UNICODE) ?>, rows = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>, byId = {};
  catalogue.forEach(function (p) { byId[p.id] = p; });
  var tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('line-tpl'), idx = 0;
  var customer = document.getElementById('customer_id'), level = document.getElementById('price_level'), due = document.getElementById('due_date'), shipTo = document.getElementById('ship_to');
  function money(n) { return '$' + n.toFixed(2); }
  function agreed() { var o = customer.selectedOptions[0]; try { return o && o.dataset.agreed ? JSON.parse(o.dataset.agreed) : {}; } catch (e) { return {}; } }
  function priceOf(u) { var a = agreed(); if (a[u.id] !== undefined) return parseFloat(a[u.id]); var p = level.value === 'wholesale' ? u.wholesale : u.retail; return p === null || p === undefined ? null : parseFloat(p); }
  function fillUnits(sel, p, selected) {
    sel.innerHTML = '';
    (p ? p.units : []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name + (u.factor > 1 ? ' (' + u.factor + ')' : ''); if (u.id === selected || (!selected && u.main)) o.selected = true; sel.appendChild(o); });
  }
  function unitOf(tr) { var p = byId[parseInt(tr.querySelector('[name$="[product_id]"]').value, 10)], uid = parseInt(tr.querySelector('[name$="[unit_id]"]').value, 10); if (!p) return null; return p.units.filter(function (u) { return u.id === uid; })[0] || null; }
  function recalc() {
    var sub = 0, ldisc = 0, ok = false;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var u = unitOf(tr), qty = parseFloat(tr.querySelector('[name$="[qty]"]').value) || 0, pct = parseFloat(tr.querySelector('[name$="[pct]"]').value) || 0;
      var price = u ? priceOf(u) : null, priceCell = tr.querySelector('.price'), totalCell = tr.querySelector('.total');
      if (!u || price === null) { priceCell.textContent = u ? 'no ' + level.value + ' price' : ''; totalCell.textContent = ''; tr.querySelector('[name$="[discount]"]').value = ''; return; }
      var gross = Math.round(price * qty * 100) / 100, disc = Math.round(gross * Math.min(100, Math.max(0, pct)) / 100 * 100) / 100, total = gross - disc;
      priceCell.textContent = money(price) + (agreed()[u.id] !== undefined ? ' (agreed)' : ''); totalCell.textContent = money(total);
      tr.querySelector('[name$="[discount]"]').value = disc > 0 ? disc.toFixed(2) : '';
      sub += total; ldisc += disc; if (qty > 0) ok = true;
    });
    var idisc = parseFloat(document.getElementById('invoice_discount').value) || 0;
    document.getElementById('t-sub').textContent = money(sub); document.getElementById('t-ldisc').textContent = money(ldisc);
    document.getElementById('t-total').textContent = money(Math.max(0, sub - idisc));
    document.getElementById('inv-ok').disabled = !(ok && customer.value);
  }
  function addLine(row) {
    tbody.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/IDX/g, String(idx++)));
    var tr = tbody.lastElementChild, prod = tr.querySelector('[name$="[product_id]"]'), unit = tr.querySelector('[name$="[unit_id]"]');
    function onProduct(selected) {
      var p = byId[parseInt(prod.value, 10)], box = tr.querySelector('.line-fill'), grams = tr.querySelector('[name$="[fill]"]');
      fillUnits(unit, p, selected);
      tr.querySelector('.stock').textContent = p ? (p.filled ? 'filled at the sale: weigh it' : 'in stock: ' + p.stock.toLocaleString()) : '';
      box.hidden = !(p && p.filled); grams.required = !!(p && p.filled); if (p && p.filled && !grams.value && p.fill) { grams.value = p.fill; }   // filled at the sale (2026-10-18)
      recalc();
    }
    prod.addEventListener('change', function () { onProduct(0); });
    ['[name$="[qty]"]', '[name$="[pct]"]'].forEach(function (s) { tr.querySelector(s).addEventListener('input', recalc); });
    unit.addEventListener('change', recalc);
    tr.querySelector('.remove').addEventListener('click', function () { tr.remove(); recalc(); });
    if (row) { prod.value = String(row.product_id); tr.querySelector('[name$="[qty]"]').value = row.qty; tr.querySelector('[name$="[pct]"]').value = row.pct; tr.querySelector('[name$="[fill]"]').value = row.fill || ''; tr.querySelector('[name$="[note]"]').value = row.note || ''; onProduct(row.unit_id); } else { prod.focus(); }
  }
  function onCustomer() {
    var o = customer.selectedOptions[0];
    if (!o || !o.value) { document.getElementById('cust-note').textContent = ''; recalc(); return; }
    if (!customer.dataset.touched) { level.value = o.dataset.level || 'wholesale'; }
    if (!shipTo.value) { shipTo.value = o.dataset.address || ''; }
    var d = new Date(); d.setDate(d.getDate() + parseInt(o.dataset.terms || '0', 10));
    document.getElementById('due-note').textContent = 'Empty: ' + o.dataset.terms + ' days from today (' + d.toLocaleDateString('en-GB') + ')';
    var bal = parseFloat(o.dataset.balance || '0');
    document.getElementById('cust-note').textContent = bal > 0.004 ? 'Owes ' + money(bal) + ' already.' : 'Owes nothing.';
    recalc();
  }
  customer.addEventListener('change', function () { customer.dataset.touched = ''; onCustomer(); });
  level.addEventListener('change', function () { customer.dataset.touched = '1'; recalc(); });
  document.getElementById('invoice_discount').addEventListener('input', recalc);
  document.getElementById('add-line').addEventListener('click', function () { addLine(null); });
  if (rows.length) { rows.forEach(addLine); } else { addLine(null); }
  onCustomer();
})();
</script>
<?php endif; ?>
