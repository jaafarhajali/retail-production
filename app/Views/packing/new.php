<?php
use App\Services\ProductionService;

$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'factor' => (int) $u['factor']], $units);
}
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$rows = static function (string $key) use ($stash): array {
    $out = [];
    foreach (is_array($stash[$key] ?? null) ? $stash[$key] : [] as $l) {
        $out[] = ['product_id' => (int) ($l['product_id'] ?? 0), 'qty' => (string) ($l['qty'] ?? ''), 'unit_id' => (int) ($l['unit_id'] ?? 0)];
    }

    return $out;
};
$usedRows = $rows('used');
$madeRows = $rows('made');
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<?php if ($mixes === []): ?>
  <div class="card"><div class="card-body"><p class="mb-2">Nothing to pack yet: no mix exists. Make the mix first.</p><a class="btn btn-primary" href="<?= url('production/new') ?>">Make</a></div></div>
<?php else: ?>
<?php require APP_PATH . '/Views/partials/mix-stock.php'; ?>
<form method="post" action="<?= url('packing/store') ?>" id="pack-form" data-preview="<?= e(url('packing/preview')) ?>">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card mb-3">
        <div class="card-header">Used: the mix. Boxes and labels come out by themselves, from each product's packaging per piece</div>
        <div class="table-responsive"><table class="table align-middle m-0" id="used">
          <thead><tr><th style="width:46%">What</th><th style="width:18%">Quantity</th><th style="width:22%">Unit</th><th class="text-nowrap">In stock</th><th></th></tr></thead>
          <tbody></tbody>
          <tfoot><tr><td colspan="5"><button class="btn btn-outline-primary btn-sm" type="button" id="add-used"><i class="bi bi-plus-lg"></i> Add an extra not in the products' packaging</button></td></tr></tfoot>
        </table></div>
      </div>
      <div class="card mb-3">
        <div class="card-header">Made: what came out, as many lines as you like</div>
        <div class="table-responsive"><table class="table align-middle m-0" id="made">
          <thead><tr><th style="width:46%">Product</th><th style="width:18%">How many</th><th style="width:22%">Unit</th><th class="text-nowrap">In stock</th><th></th></tr></thead>
          <tbody></tbody>
          <tfoot><tr><td colspan="5"><button class="btn btn-outline-primary btn-sm" type="button" id="add-made"><i class="bi bi-plus-lg"></i> Add a product</button> <span class="form-text ms-2">Type the real count. Next time the same mix can give a different split.</span><div class="small mt-2 fw-semibold" id="pack-held"></div></td></tr></tfoot>
        </table></div>
      </div>
      <div class="card mb-3"><div class="card-body">
        <label class="form-label" for="notes">Notes</label>
        <input class="form-control" id="notes" name="notes" dir="auto" maxlength="255" value="<?= old('notes') ?>" placeholder="optional">
      </div></div>
      <input type="hidden" name="confirm_gap" id="confirm-gap" value="">
      <button class="btn btn-primary btn-lg" type="submit" id="pack-ok" disabled><i class="bi bi-check-lg"></i> <span id="pack-ok-label">Confirm: it is packed</span></button>
      <a class="btn btn-link" href="<?= url('production') ?>">Cancel</a>
    </div>
    <div class="col-lg-5">
      <div class="card"><div class="card-header">What it does</div>
        <div class="card-body" id="preview-empty"><p class="text-muted mb-0">Type how much was used and what came out: the stock, the packaging taken out and the cost show here before anything is written.</p></div>
        <div id="preview-body" hidden>
          <div class="table-responsive"><table class="table table-sm align-middle m-0">
            <thead><tr><th>Out of stock</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
            <tbody id="preview-used"></tbody>
            <thead><tr><th>Into stock</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
            <tbody id="preview-made"></tbody>
            <?php if ($showCost): ?><tfoot class="totals"><tr class="grand"><td colspan="2">Total cost</td><td class="text-end" id="preview-cost"></td></tr></tfoot><?php endif; ?>
          </table></div>
          <div class="card-body pt-2">
            <div class="alert alert-warning mb-2" id="preview-gap" hidden></div>
            <div class="alert alert-danger mb-2" id="preview-short" hidden></div>
            <?php if ($showCost): ?><div class="text-muted small" id="preview-note"></div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</form>
<?php $singleMix = count($mixes) === 1; // one mix (2026-10-18): its line asks only the kilos; the extras never offer it ?>
<?php if ($singleMix): $mx = $mixes[0]; ?>
<template id="mix-tpl">
  <tr class="mix-line">
    <td><input type="hidden" name="used[IDX][product_id]" value="<?= (int) $mx['id'] ?>" data-stock="<?= e(ProductionService::baseText((int) $mx['stock_base'], $mx['base_unit'])) ?>"><strong dir="auto"><?= e($mx['name']) ?></strong> <span class="text-muted small">used</span></td>
    <td><input class="form-control form-control-sm" name="used[IDX][qty]" inputmode="decimal" required placeholder="how much"></td>
    <td><select class="form-select form-select-sm" name="used[IDX][unit_id]" aria-label="Unit"></select></td>
    <td class="text-nowrap text-muted small stock"></td>
    <td></td>
  </tr>
</template>
<?php endif; ?>
<template id="used-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="used[IDX][product_id]" required><option value="">— choose —</option>
      <?php if (!$singleMix): ?><optgroup label="Mix"><?php foreach ($mixes as $m): ?><option value="<?= (int) $m['id'] ?>" data-stock="<?= e(ProductionService::baseText((int) $m['stock_base'], $m['base_unit'])) ?>" dir="auto"><?= e($m['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
      <?php foreach (\App\Services\ProductService::groupedByCategory($materials) as $groupLabel => $inGroup): // packaging first, then the ingredients ?>
        <optgroup label="<?= e($groupLabel) ?>"><?php foreach ($inGroup as $m): ?><option value="<?= (int) $m['id'] ?>" data-stock="<?= e(ProductionService::baseText((int) $m['stock_base'], $m['base_unit'])) ?>" dir="auto"><?= e($m['name']) ?></option><?php endforeach; ?></optgroup>
      <?php endforeach; ?></select></td>
    <td><input class="form-control form-control-sm" name="used[IDX][qty]" inputmode="decimal" required></td>
    <td><select class="form-select form-select-sm" name="used[IDX][unit_id]" aria-label="Unit"></select></td>
    <td class="text-nowrap text-muted small stock"></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<template id="made-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="made[IDX][product_id]" required><option value="">— choose —</option>
      <?php foreach ($finished as $p): ?><option value="<?= (int) $p['id'] ?>" data-stock="<?= e(ProductionService::baseText((int) $p['stock_base'], $p['base_unit'])) ?>" data-grams="<?= (int) ($p['fill_base'] ?? 0) ?>" dir="auto"><?= e($p['name']) ?></option><?php endforeach; ?></select></td>
    <td><input class="form-control form-control-sm" name="made[IDX][qty]" inputmode="decimal" required></td>
    <td><select class="form-select form-select-sm" name="made[IDX][unit_id]" aria-label="Unit"></select></td>
    <td class="text-nowrap text-muted small stock"></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>, usedRows = <?= json_encode($usedRows) ?>, madeRows = <?= json_encode($madeRows) ?>;
  var form = document.getElementById('pack-form'), token = form.querySelector('input[name="_token"]').value, timer = null, seq = 0, idx = 0;
  function fillUnits(sel, productId, selected) {
    sel.innerHTML = '';
    (units[productId] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; if (u.id === selected) o.selected = true; sel.appendChild(o); });
  }
  function addLine(table, tplId, row) {
    var tbody = document.querySelector('#' + table + ' tbody');
    tbody.insertAdjacentHTML('beforeend', document.getElementById(tplId).innerHTML.replace(/IDX/g, String(idx++)));
    var tr = tbody.lastElementChild, prod = tr.querySelector('[name$="[product_id]"]'), unit = tr.querySelector('[name$="[unit_id]"]'), qty = tr.querySelector('[name$="[qty]"]');
    var fixed = prod.type === 'hidden';   // the one mix: no choice to make (2026-10-18)
    function stockText() { var o = fixed ? prod : prod.selectedOptions[0]; tr.querySelector('.stock').textContent = o ? (o.dataset.stock || '') : ''; }
    prod.addEventListener('change', function () { fillUnits(unit, parseInt(this.value, 10), 0); stockText(); preview(); });
    unit.addEventListener('change', preview);
    qty.addEventListener('input', function () { if (fixed) { qty.dataset.typed = qty.value.trim() === '' ? '' : '1'; } preview(); });   // typed by hand: no longer filled from the products
    var rm = tr.querySelector('.remove'); if (rm) { rm.addEventListener('click', function () { tr.remove(); preview(); }); }
    if (fixed) { fillUnits(unit, parseInt(prod.value, 10), row ? row.unit_id : 0); if (row) { qty.value = row.qty; qty.dataset.typed = row.qty ? '1' : ''; } stockText(); }
    else if (row) { prod.value = String(row.product_id); fillUnits(unit, row.product_id, row.unit_id); qty.value = row.qty; stockText(); }
    else if (table === 'used' && tbody.children.length === 1 && prod.querySelector('optgroup option')) { prod.value = prod.querySelector('optgroup option').value; fillUnits(unit, parseInt(prod.value, 10), 0); stockText(); }
    else { prod.focus(); }
  }
  function rows(table) {
    return Array.prototype.map.call(document.querySelectorAll('#' + table + ' tbody tr'), function (tr) {
      return { product_id: tr.querySelector('[name$="[product_id]"]').value, qty: tr.querySelector('[name$="[qty]"]').value, unit_id: tr.querySelector('[name$="[unit_id]"]').value };
    });
  }
  function money(s) { return '$' + s; }
  function cell(tr, i, text, cls) { tr.children[i].textContent = text; if (cls) tr.children[i].className = cls; }
  // What the products hold (2026-10-18): each product's marshmallow per piece × the pieces typed; it fills "used" on the
  // mix line until someone types there, and says when a product has no weight on its page.
  // A gap over 5% (2026-10-18): the button says "Confirm anyway", and its first click only asks for a second one
  var gapOn = false, gapArmed = false;
  function gapState(on) {
    gapOn = on; if (!on) { gapArmed = false; }
    document.getElementById('confirm-gap').value = on && gapArmed ? '1' : '';
    document.getElementById('pack-ok-label').textContent = !on ? 'Confirm: it is packed' : (gapArmed ? 'Click again to confirm anyway' : 'Confirm anyway');
  }
  form.addEventListener('submit', function (ev) {
    if (gapOn && !gapArmed) { ev.preventDefault(); ev.stopPropagation(); gapArmed = true; gapState(true); document.getElementById('preview-gap').scrollIntoView({ block: 'center' }); }   // stopped here: the page's submit-once guard must not lock the button
  });
  function kgText(g) { return g >= 1000 ? (Math.round(g) / 1000).toLocaleString('en-US', { maximumFractionDigits: 3 }) + ' kg' : Math.round(g) + ' g'; }
  function updateHeld() {
    var grams = 0, unknown = [];
    document.querySelectorAll('#made tbody tr').forEach(function (tr) {
      var prod = tr.querySelector('[name$="[product_id]"]'), o = prod.selectedOptions[0], q = parseFloat((tr.querySelector('[name$="[qty]"]').value || '').replace(',', '.')) || 0;
      if (!o || !o.value || q <= 0) return;
      var uid = parseInt(tr.querySelector('[name$="[unit_id]"]').value, 10), u = (units[parseInt(o.value, 10)] || []).find(function (x) { return x.id === uid; }), f = u ? u.factor : 1;
      var per = parseInt(o.dataset.grams || '0', 10);
      if (per > 0) { grams += per * q * f; } else if (unknown.indexOf(o.textContent) < 0) { unknown.push(o.textContent.trim()); }
    });
    var box = document.getElementById('pack-held');
    box.textContent = (grams > 0 ? 'These products hold ' + kgText(grams) + ' of marshmallow.' : '') + (unknown.length ? ' No "marshmallow per piece" on: ' + unknown.join(', ') + '.' : '');
    var mixLine = document.querySelector('#used tbody tr.mix-line');
    if (mixLine && grams > 0) {
      var mq = mixLine.querySelector('[name$="[qty]"]'), mu = mixLine.querySelector('[name$="[unit_id]"]'), mid = parseInt(mixLine.querySelector('[name$="[product_id]"]').value, 10);
      var mf = ((units[mid] || []).find(function (x) { return x.id === parseInt(mu.value, 10); }) || { factor: 1 }).factor;
      if (mq.dataset.typed !== '1') { mq.value = String(Math.round(grams / mf * 1000) / 1000); }
    }
  }
  function preview() {
    updateHeld();
    clearTimeout(timer);
    timer = setTimeout(function () {
      var mine = ++seq, used = rows('used'), made = rows('made');
      document.getElementById('pack-ok').disabled = true;
      var filled = function (r) { return r.product_id && r.qty.trim(); };
      if (!used.some(filled) || !made.some(filled)) { document.getElementById('preview-empty').hidden = false; document.getElementById('preview-body').hidden = true; return; }
      fetch(form.dataset.preview, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify({ used: used, made: made }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (mine !== seq) return;
          document.getElementById('preview-empty').hidden = true; document.getElementById('preview-body').hidden = false;
          var shortBox = document.getElementById('preview-short'), tu = document.getElementById('preview-used'), tm = document.getElementById('preview-made');
          if (!res.ok) { shortBox.hidden = false; shortBox.textContent = res.j.error || 'Could not preview'; tu.innerHTML = ''; tm.innerHTML = ''; return; }
          var showCost = res.j.cost !== null;
          tu.innerHTML = ''; tm.innerHTML = '';
          res.j.used.forEach(function (l) {
            var tr = document.createElement('tr'); if (l.short) tr.className = 'table-danger';
            tr.innerHTML = '<td dir="auto"></td><td class="text-end text-nowrap"></td>' + (showCost ? '<td class="text-end text-nowrap"></td>' : '');
            cell(tr, 0, l.name + (l.for ? ' · for ' + l.for : '')); cell(tr, 1, '− ' + l.qty + (l.short ? ' (in stock ' + l.stock + ')' : '')); if (showCost) cell(tr, 2, money(l.cost));
            tu.appendChild(tr);
          });
          res.j.made.forEach(function (l) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td dir="auto"></td><td class="text-end text-nowrap"></td>' + (showCost ? '<td class="text-end text-nowrap"></td>' : '');
            cell(tr, 0, l.name); cell(tr, 1, '+ ' + l.qty); if (showCost) cell(tr, 2, money(l.cost) + ' · ' + money(l.per_unit) + ' / ' + l.unit);
            tm.appendChild(tr);
          });
          var cost = document.getElementById('preview-cost'); if (cost && showCost) cost.textContent = money(res.j.cost);
          var note = document.getElementById('preview-note'); if (note && showCost) note.textContent = res.j.by_value ? 'The cost is shared between the products in proportion to their selling price, so a $6 product takes twice the cost of a $3 one.' : 'No product has a price yet, so the cost is shared per piece.';
          var gap = document.getElementById('preview-gap'); gap.hidden = !res.j.mismatch; gap.textContent = res.j.mismatch || '';
          // more than the mix in stock: a hard stop, shown like a shortage (2026-10-18)
          var stop = res.j.over || (res.j.short.length ? 'Not enough in stock: ' + res.j.short.join('; ') + '. Make or buy the missing part first.' : '');
          shortBox.hidden = stop === ''; shortBox.textContent = stop;
          document.getElementById('pack-ok').disabled = stop !== '';
          gapState(!!res.j.mismatch);
        }).catch(function () {});
    }, 250);
  }
  document.getElementById('add-used').addEventListener('click', function () { addLine('used', 'used-tpl', null); });
  document.getElementById('add-made').addEventListener('click', function () { addLine('made', 'made-tpl', null); });
  var mixTpl = document.getElementById('mix-tpl'), mixId = mixTpl ? parseInt(mixTpl.content.querySelector('[name$="[product_id]"]').value, 10) : 0;
  if (mixTpl && !usedRows.some(function (r) { return r.product_id === mixId; })) { addLine('used', 'mix-tpl', null); }   // the mix line comes first
  usedRows.forEach(function (r) { addLine('used', mixTpl && r.product_id === mixId ? 'mix-tpl' : 'used-tpl', r); });
  if (!mixTpl && !usedRows.length) { addLine('used', 'used-tpl', null); }
  if (madeRows.length) { madeRows.forEach(function (r) { addLine('made', 'made-tpl', r); }); } else { addLine('made', 'made-tpl', null); addLine('made', 'made-tpl', null); }
  preview();
})();
</script>
<?php endif; ?>
