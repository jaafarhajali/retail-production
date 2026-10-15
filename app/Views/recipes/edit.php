<?php
use App\Services\ProductionService;

$isEdit = $recipe !== null;
$unitOptions = [];
foreach ($unitsById as $pid => $units) {
    $unitOptions[$pid] = array_map(static fn (array $u): array => ['id' => (int) $u['id'], 'name' => $u['name'], 'factor' => (int) $u['factor']], $units);
}
$baseOf = [];
foreach (array_merge($made, $materials) as $p) {
    $baseOf[(int) $p['id']] = $p['base_unit'];
}
// What the form shows: the stash after a refused save, else the recipe, else empty.
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$pick = static fn (int $base, array $units): array => array_values(array_filter($units, static fn (array $u): bool => (int) $u['factor'] === $base))[0] ?? ($units[0] ?? null);
$outputQty = $stash['output_qty'] ?? '';
$outputUnit = (int) ($stash['output_unit_id'] ?? 0);
if ($isEdit && !isset($stash['output_qty'])) {
    // shown in the largest unit that divides the output exactly
    foreach (array_reverse($unitsById[(int) $recipe['product_id']] ?? []) as $u) {
        if ((int) $recipe['output_base'] % (int) $u['factor'] === 0) {
            $outputQty = (string) ((int) $recipe['output_base'] / (int) $u['factor']);
            $outputUnit = (int) $u['id'];
            break;
        }
    }
}
$rows = [];
if (isset($stash['lines']) && is_array($stash['lines'])) {
    foreach ($stash['lines'] as $l) {
        $rows[] = ['material_id' => (int) ($l['material_id'] ?? 0), 'qty' => (string) ($l['qty'] ?? ''), 'unit_id' => (int) ($l['unit_id'] ?? 0)];
    }
} else {
    foreach ($lines as $l) {
        $qty = (string) $l['qty_base'];
        $unitId = 0;
        foreach (array_reverse($unitsById[(int) $l['material_id']] ?? []) as $u) {
            if ((int) $l['qty_base'] % (int) $u['factor'] === 0 || (int) $u['factor'] === 1) {
                $qty = rtrim(rtrim(number_format((int) $l['qty_base'] / (int) $u['factor'], 3, '.', ''), '0'), '.');
                $unitId = (int) $u['id'];
                break;
            }
        }
        $rows[] = ['material_id' => (int) $l['material_id'], 'qty' => $qty, 'unit_id' => $unitId];
    }
}
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<form method="post" action="<?= url('recipes/save') ?>" id="recipe-form">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $isEdit ? (int) $recipe['id'] : 0 ?>">
  <div class="card mb-3"><div class="card-body">
    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label" for="product_id">What does it make?</label>
        <?php if ($isEdit): ?>
          <input type="hidden" name="product_id" value="<?= (int) $recipe['product_id'] ?>">
          <div class="form-control-plaintext fw-semibold" dir="auto"><?= e($recipe['product_name']) ?> <span class="text-muted fw-normal">(mix)</span></div>
        <?php else: ?>
          <select class="form-select" id="product_id" name="product_id" required>
            <option value="">— choose —</option>
            <?php foreach ($made as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) ($stash['product_id'] ?? $productId) ? 'selected' : '' ?> dir="auto"><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="name">Flavour</label>
        <input class="form-control" id="name" name="name" dir="auto" maxlength="120" value="<?= old('name', $recipe['name'] ?? '') ?>" placeholder="e.g. Strawberry">
      </div>
      <div class="col-md-4">
        <label class="form-label" for="output_qty">One batch makes</label>
        <div class="input-group">
          <input class="form-control" id="output_qty" name="output_qty" inputmode="decimal" placeholder="1" value="<?= e($outputQty) ?>" required>
          <select class="form-select" id="output_unit_id" name="output_unit_id" data-selected="<?= $outputUnit ?>" aria-label="Unit"></select>
        </div>
        <div class="form-text">For example 5 kg of mix. Production counts in batches of this; the mix then becomes products on the Packing page, with the quantities typed each time.</div>
      </div>
    </div>
  </div></div>

  <div class="card mb-3">
    <div class="table-responsive"><table class="table align-middle" id="lines">
      <thead><tr><th style="width:44%">Goes in, per batch</th><th style="width:18%">Quantity</th><th style="width:22%">Unit</th><th class="text-nowrap">In stock now</th><th></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td colspan="5"><button class="btn btn-outline-primary" type="button" id="add-line"><i class="bi bi-plus-lg"></i> Add a line</button> <span class="form-text ms-2">Raw materials, or another mix.</span></td></tr></tfoot>
    </table></div>
  </div>

  <div class="card mb-3"><div class="card-body row g-3 align-items-end">
    <div class="col-md-8"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" dir="auto" maxlength="255" value="<?= old('notes', $recipe['notes'] ?? '') ?>" placeholder="e.g. cook at 118 °C, rest 12 h"></div>
    <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1" <?= (isset($stash['name']) ? isset($stash['is_default']) : (!$isEdit || (int) $recipe['is_default'] === 1)) ? 'checked' : '' ?>><label class="form-check-label" for="is_default">The usual recipe for this product</label></div></div>
  </div></div>
  <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check-lg"></i> Save recipe</button>
  <a class="btn btn-link" href="<?= url('recipes') ?>">Cancel</a>
  <?php if ($isEdit): ?><button class="btn btn-outline-danger float-end" type="submit" form="recipe-delete"><?= (int) $recipe['run_count'] > 0 ? 'Switch off' : 'Delete' ?></button><?php endif; ?>
</form>
<?php if ($isEdit): ?>
  <form method="post" action="<?= url('recipes/delete') ?>" id="recipe-delete" data-confirm="<?= (int) $recipe['run_count'] > 0 ? 'Switch this recipe off? Its runs stay in the history.' : 'Delete this recipe?' ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $recipe['id'] ?>"></form>
<?php endif; ?>
<template id="line-tpl">
  <tr>
    <td><select class="form-select form-select-sm" name="lines[IDX][material_id]" required><option value="">— choose —</option>
      <?php foreach ($materials as $m): ?><option value="<?= (int) $m['id'] ?>" data-stock="<?= e(ProductionService::baseText((int) $m['stock_base'], $m['base_unit'])) ?>" dir="auto"><?= e($m['name']) ?><?= $m['kind'] === 'intermediate' ? ' (mix)' : '' ?></option><?php endforeach; ?></select></td>
    <td><input class="form-control form-control-sm" name="lines[IDX][qty]" inputmode="decimal" required></td>
    <td><select class="form-select form-select-sm" name="lines[IDX][unit_id]" aria-label="Unit"></select></td>
    <td class="text-nowrap text-muted small stock"></td>
    <td class="text-end"><button class="btn btn-sm btn-outline-danger remove" type="button" title="Remove this line" aria-label="Remove this line"><i class="bi bi-trash3"></i></button></td>
  </tr>
</template>
<script>
(function () {
  var units = <?= json_encode($unitOptions, JSON_UNESCAPED_UNICODE) ?>, rows = <?= json_encode($rows) ?>;
  var tbody = document.querySelector('#lines tbody'), tpl = document.getElementById('line-tpl'), idx = 0;
  function fillUnits(sel, productId, selected) {
    sel.innerHTML = '';
    (units[productId] || []).forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.textContent = u.name; if (u.id === selected) o.selected = true; sel.appendChild(o); });
  }
  var productSel = document.getElementById('product_id'), outUnit = document.getElementById('output_unit_id');
  function outputUnits() {
    var pid = productSel ? parseInt(productSel.value, 10) : <?= $isEdit ? (int) $recipe['product_id'] : 0 ?>;
    fillUnits(outUnit, pid, parseInt(outUnit.dataset.selected || '0', 10));
  }
  if (productSel) productSel.addEventListener('change', outputUnits);
  outputUnits();
  function addLine(row) {
    tbody.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/IDX/g, String(idx++)));
    var tr = tbody.lastElementChild, mat = tr.querySelector('[name$="[material_id]"]'), unit = tr.querySelector('[name$="[unit_id]"]');
    mat.addEventListener('change', function () { fillUnits(unit, parseInt(this.value, 10), 0); tr.querySelector('.stock').textContent = this.selectedOptions[0] ? (this.selectedOptions[0].dataset.stock || '') : ''; });
    tr.querySelector('.remove').addEventListener('click', function () { tr.remove(); });
    if (row) { mat.value = String(row.material_id); fillUnits(unit, row.material_id, row.unit_id); tr.querySelector('[name$="[qty]"]').value = row.qty; tr.querySelector('.stock').textContent = mat.selectedOptions[0] ? (mat.selectedOptions[0].dataset.stock || '') : ''; }
    else { mat.focus(); }
  }
  document.getElementById('add-line').addEventListener('click', function () { addLine(null); });
  if (rows.length) { rows.forEach(addLine); } else { addLine(null); }
})();
</script>
