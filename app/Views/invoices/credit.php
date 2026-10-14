<?php
/** A credit note against a desk invoice (2026-10-14): what came back, in what condition; the amount is credited to the account. */
$s = $sale;
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$typed = is_array($stash['items'] ?? null) ? $stash['items'] : [];
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<form method="post" action="<?= url('invoices/credit-store') ?>" id="credit-form">
  <?= csrf_field() ?>
  <input type="hidden" name="sale_id" value="<?= (int) $s['id'] ?>">
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-header">Against <code><?= e($s['invoice_no']) ?></code> of <?= e(date('d/m/Y', strtotime($s['created_at']))) ?> · <span dir="auto"><?= e($s['customer_name'] ?? '') ?></span> · <?= usd($s['total_usd']) ?></div>
        <div class="table-responsive"><table class="table align-middle m-0" id="credit-lines">
          <thead><tr><th>Item</th><th class="text-end">Delivered</th><th class="text-end">Already back</th><th style="width:14%">Comes back</th><th style="width:22%">Condition</th><th class="text-end">Credit</th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): $left = (int) $i['base_qty'] - (int) $i['returned_base_qty']; $leftQty = \App\Services\Quantity::unitQty($left, (int) $i['factor']); $perUnit = (float) $i['qty'] > 0 ? (float) $i['line_total_usd'] / (float) $i['qty'] : 0; ?>
            <tr data-left="<?= e($leftQty) ?>" data-per-unit="<?= number_format($perUnit, 4, '.', '') ?>">
              <td dir="auto"><?= e($i['product_name']) ?><?= (int) $i['factor'] > 1 ? ' · ' . e($i['unit_name']) : '' ?><?= !empty($i['note']) ? '<br><small class="text-muted">' . e($i['note']) . '</small>' : '' ?></td>
              <td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td>
              <td class="text-end text-nowrap"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) : '' ?></td>
              <td><input class="form-control form-control-sm qty" name="items[<?= (int) $i['id'] ?>][qty]" inputmode="decimal" value="<?= e((string) ($typed[$i['id']]['qty'] ?? '')) ?>" placeholder="0" <?= $left <= 0 ? 'disabled' : '' ?>></td>
              <td><select class="form-select form-select-sm" name="items[<?= (int) $i['id'] ?>][condition]" <?= $left <= 0 ? 'disabled' : '' ?>><option value="restock">Back to stock</option><option value="waste" <?= ($typed[$i['id']]['condition'] ?? '') === 'waste' ? 'selected' : '' ?>>Damaged, thrown away</option></select></td>
              <td class="text-end text-nowrap credit"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="totals"><tr class="grand"><td colspan="5">Credited to the account</td><td class="text-end" id="credit-total">$0.00</td></tr></tfoot>
        </table></div>
      </div>
      <div class="card mb-3"><div class="card-body">
        <label class="form-label" for="reason">Reason</label>
        <input class="form-control" id="reason" name="reason" dir="auto" maxlength="255" value="<?= e($stash['reason'] ?? '') ?>" placeholder="e.g. damaged in transport, short expiry, not ordered">
      </div></div>
    </div>
    <div class="col-lg-4">
      <div class="card"><div class="card-body">
        <p class="text-muted small">The goods come back into stock (or go to waste) and the amount, at the invoice's own prices, is taken off what the customer owes, oldest invoice first. No cash moves: a cash refund is done at the till.</p>
        <button class="btn btn-primary btn-lg w-100" type="submit" id="credit-ok" disabled><i class="bi bi-check-lg"></i> Issue the credit note</button>
        <a class="btn btn-link w-100" href="<?= url('sales/view', ['id' => $s['id']]) ?>">Cancel</a>
      </div></div>
    </div>
  </div>
</form>
<script>
(function () {
  var rows = document.querySelectorAll('#credit-lines tbody tr'), ok = document.getElementById('credit-ok');
  function recalc() {
    var total = 0, any = false;
    rows.forEach(function (tr) {
      var q = tr.querySelector('.qty'), v = q ? parseFloat(q.value) || 0 : 0, left = parseFloat(tr.dataset.left) || 0, per = parseFloat(tr.dataset.perUnit) || 0;
      if (v > left) { q.classList.add('is-invalid'); } else if (q) { q.classList.remove('is-invalid'); }
      var c = v > 0 && v <= left ? Math.round(per * v * 100) / 100 : 0;
      tr.querySelector('.credit').textContent = c > 0 ? '$' + c.toFixed(2) : '';
      total += c; if (c > 0) any = true;
    });
    document.getElementById('credit-total').textContent = '$' + total.toFixed(2);
    ok.disabled = !any;
  }
  rows.forEach(function (tr) { var q = tr.querySelector('.qty'); if (q) q.addEventListener('input', recalc); });
  recalc();
})();
</script>
