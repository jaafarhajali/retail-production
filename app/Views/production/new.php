<?php
$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'factor' => (int) $u['factor']], $units);
}
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$selectedRecipe = (int) ($stash['recipe_id'] ?? $recipeId);
$selectedProduct = 0;
foreach ($byProduct as $pid => $p) {
    foreach ($p['recipes'] as $r) {
        if ($r['id'] === $selectedRecipe) {
            $selectedProduct = (int) $pid;
        }
    }
}
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<?php if ($byProduct === []): ?>
  <div class="card"><div class="card-body"><p class="mb-2">Nothing can be made yet: no recipe exists. A recipe says what one batch of a mix is made of.</p><a class="btn btn-primary" href="<?= url('recipes/edit') ?>">Write the first recipe</a></div></div>
<?php else: ?>
<?php require APP_PATH . '/Views/partials/mix-stock.php'; ?>
<form method="post" action="<?= url('production/store') ?>" id="make-form" data-preview="<?= e(url('production/preview')) ?>">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card mb-3"><div class="card-body">
        <?php if (count($byProduct) === 1): ?>
          <input type="hidden" id="product_id" value="<?= (int) array_key_first($byProduct) ?>">
          <label class="form-label" for="recipe_id">Flavour</label>
        <?php else: ?>
        <label class="form-label" for="product_id">What did you make?</label>
        <select class="form-select form-select-lg" id="product_id">
          <option value="">— choose —</option>
          <?php foreach ($byProduct as $pid => $p): ?><option value="<?= (int) $pid ?>" <?= (int) $pid === $selectedProduct ? 'selected' : '' ?> dir="auto"><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label mt-3" for="recipe_id">Flavour</label>
        <?php endif; ?>
        <select class="form-select" id="recipe_id" name="recipe_id" data-selected="<?= $selectedRecipe ?>"></select>
        <label class="form-label mt-3" for="qty">How much?</label>
        <div class="input-group input-group-lg">
          <input class="form-control" id="qty" name="qty" inputmode="decimal" placeholder="5" value="<?= old('qty') ?>" required autocomplete="off">
          <select class="form-select" id="unit_id" name="unit_id" data-selected="<?= isset($stash['unit_id']) ? (int) $stash['unit_id'] : -1 ?>" aria-label="Unit"></select>
        </div>
        <div class="form-text">Count in batches of the recipe, or switch to kg and type what really came out: if one batch gave 4.8 kg instead of 5, the cost per kg goes up and the loss is visible. The mix then becomes products on the Packing page.</div>
        <label class="form-label mt-3" for="notes">Notes</label>
        <input class="form-control" id="notes" name="notes" dir="auto" maxlength="255" value="<?= old('notes') ?>" placeholder="optional">
      </div></div>
      <button class="btn btn-primary btn-lg" type="submit" id="make-ok" disabled><i class="bi bi-check-lg"></i> Confirm: it is made</button>
      <a class="btn btn-link" href="<?= url('production') ?>">Cancel</a>
    </div>
    <div class="col-lg-7">
      <div class="card"><div class="card-header">What it takes</div>
        <div class="card-body" id="preview-empty"><p class="text-muted mb-0">Choose the flavour and how much: the materials, the stock and the cost show here before anything is written.</p></div>
        <div class="table-responsive" id="preview-table" hidden><table class="table align-middle m-0">
          <thead><tr><th>Material</th><th class="text-end">Needed</th><th class="text-end">In stock</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
          <tbody id="preview-lines"></tbody>
          <tfoot class="totals"><tr class="grand"><td>Makes <span id="preview-qty"></span> <span class="text-muted" id="preview-batches"></span></td><td colspan="2"></td><?php if ($showCost): ?><td class="text-end" id="preview-cost"></td><?php endif; ?></tr></tfoot>
        </table></div>
        <div class="card-body pt-2" id="preview-foot" hidden>
          <div class="alert alert-danger mb-2" id="preview-short" hidden></div>
          <?php if ($showCost): ?><div class="text-muted" id="preview-unit"></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  var byProduct = <?= json_encode($byProduct, JSON_UNESCAPED_UNICODE) ?>, units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>;
  var form = document.getElementById('make-form'), product = document.getElementById('product_id'), recipe = document.getElementById('recipe_id'), qty = document.getElementById('qty'), unit = document.getElementById('unit_id');
  var token = form.querySelector('input[name="_token"]').value, timer = null, seq = 0;
  function fill(sel, items, selected, label) { sel.innerHTML = ''; items.forEach(function (it) { var o = document.createElement('option'); o.value = it.id; o.textContent = label(it); if (it.id === selected || (!selected && it.default)) o.selected = true; sel.appendChild(o); }); }
  function onProduct() {
    var pid = parseInt(product.value, 10), p = byProduct[pid];
    fill(recipe, p ? p.recipes : [], parseInt(recipe.dataset.selected || '0', 10), function (r) { return r.name; });
    fill(unit, [{ id: 0, name: 'batch(es)', default: true }].concat(units[pid] || []), parseInt(unit.dataset.selected || '-1', 10), function (u) { return u.name; });
    recipe.dataset.selected = '0'; unit.dataset.selected = '0';
    preview();
  }
  function money(s) { return '$' + s; }
  function preview() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      var mine = ++seq;
      document.getElementById('make-ok').disabled = true;
      if (!recipe.value || !qty.value.trim()) { document.getElementById('preview-empty').hidden = false; document.getElementById('preview-table').hidden = true; document.getElementById('preview-foot').hidden = true; return; }
      fetch(form.dataset.preview, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token }, body: JSON.stringify({ recipe_id: recipe.value, qty: qty.value, unit_id: unit.value }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
          if (mine !== seq) return;
          var tb = document.getElementById('preview-lines'); tb.innerHTML = '';
          document.getElementById('preview-empty').hidden = true; document.getElementById('preview-table').hidden = false; document.getElementById('preview-foot').hidden = false;
          var shortBox = document.getElementById('preview-short');
          if (!res.ok) { shortBox.hidden = false; shortBox.textContent = res.j.error || 'Could not preview'; return; }
          res.j.lines.forEach(function (l) {
            var tr = document.createElement('tr'); if (l.short) tr.className = 'table-danger';
            tr.innerHTML = '<td dir="auto"></td><td class="text-end text-nowrap"></td><td class="text-end text-nowrap"></td>' + (l.cost !== null ? '<td class="text-end text-nowrap"></td>' : '');
            tr.children[0].textContent = l.name; tr.children[1].textContent = l.need; tr.children[2].textContent = l.stock + (l.short ? ' — short' : '');
            if (l.cost !== null) tr.children[3].textContent = money(l.cost);
            tb.appendChild(tr);
          });
          document.getElementById('preview-qty').textContent = res.j.qty_text; document.getElementById('preview-batches').textContent = '(' + res.j.batches.replace(/\.?0+$/, '') + ' × the recipe)';
          var cost = document.getElementById('preview-cost'); if (cost && res.j.material_cost !== null) cost.textContent = money(res.j.material_cost);
          var pu = document.getElementById('preview-unit'); if (pu && res.j.cost_per_unit !== null) pu.textContent = 'Cost per ' + res.j.unit_name + ': ' + money(res.j.cost_per_unit) + ' (materials only, at today’s costs).';
          shortBox.hidden = res.j.short.length === 0; shortBox.textContent = res.j.short.length ? 'Not enough in stock: ' + res.j.short.join('; ') + '. Buy or produce the missing part first.' : '';
          document.getElementById('make-ok').disabled = res.j.short.length > 0;
        }).catch(function () {});
    }, 250);
  }
  product.addEventListener('change', onProduct);
  recipe.addEventListener('change', preview); unit.addEventListener('change', preview); qty.addEventListener('input', preview);
  onProduct();
})();
</script>
<?php endif; ?>
