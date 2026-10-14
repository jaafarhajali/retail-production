<?php
$stash = is_array($_SESSION['_old'] ?? null) ? $_SESSION['_old'] : [];
$selected = (int) ($stash['customer_id'] ?? $customerId);
$badge = ['paid' => 'text-bg-success', 'partly' => 'text-bg-info', 'unpaid' => 'text-bg-warning', 'overdue' => 'text-bg-danger'];
?>
<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<form method="post" action="<?= url('invoices/pay-store') ?>" id="pay-form">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card mb-3"><div class="card-body">
        <label class="form-label" for="customer_id">Customer</label>
        <select class="form-select form-select-lg" id="customer_id" name="customer_id" required onchange="location.href='<?= url('invoices/pay') ?>&customer_id=' + this.value">
          <option value="">— choose —</option>
          <?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $selected ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?><?= (float) $c['balance_usd'] > 0.004 ? ' · owes ' . usd($c['balance_usd']) : '' ?></option><?php endforeach; ?>
        </select>
        <div class="row g-2 mt-2">
          <div class="col-6"><label class="form-label" for="usd">Received, USD</label><div class="input-group"><span class="input-group-text">$</span><input class="form-control" id="usd" name="usd" inputmode="decimal" value="<?= e($stash['usd'] ?? '') ?>" placeholder="0.00"></div></div>
          <div class="col-6"><label class="form-label" for="lbp">or LBP</label><input class="form-control" id="lbp" name="lbp" inputmode="numeric" value="<?= e($stash['lbp'] ?? '') ?>" placeholder="0"><div class="form-text">at 1 USD = <?= number_format($rate) ?> LBP</div></div>
          <div class="col-6"><label class="form-label" for="method">How</label>
            <select class="form-select" id="method" name="method"><?php foreach (['transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card'] as $k => $v): ?><option value="<?= $k ?>" <?= ($stash['method'] ?? 'transfer') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
          <div class="col-6"><label class="form-label" for="reference">Reference</label><input class="form-control" id="reference" name="reference" maxlength="80" value="<?= e($stash['reference'] ?? '') ?>" placeholder="cheque or transfer no."></div>
          <div class="col-12"><label class="form-label" for="note">Note</label><input class="form-control" id="note" name="note" dir="auto" maxlength="255" value="<?= e($stash['note'] ?? '') ?>" placeholder="optional"></div>
        </div>
        <p class="text-muted small mt-3 mb-3">Cash is collected at the till (Customer → Collect debt) so that it goes into the drawer. Here: what arrives by bank, cheque or card. It is applied to the oldest invoice first.</p>
        <button class="btn btn-primary btn-lg" type="submit" <?= $selected > 0 ? '' : 'disabled' ?>><i class="bi bi-check-lg"></i> Record the payment</button>
        <a class="btn btn-link" href="<?= url('invoices') ?>">Cancel</a>
      </div></div>
    </div>
    <div class="col-lg-7">
      <div class="card"><div class="card-header">What <?= $selected > 0 ? 'they' : 'the customer' ?> still owe<?= $selected > 0 ? 's' : '' ?></div>
        <?php if ($selected > 0): ?>
        <div class="table-responsive"><table class="table table-sm align-middle m-0">
          <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="text-end">Amount</th><th class="text-end">Left</th><th>Status</th></tr></thead>
          <tbody>
          <?php $left = 0.0; foreach ($outstanding['items'] as $it): if ($it['status'] === 'paid') { continue; } $left += (float) $it['remaining']; ?>
            <tr>
              <td class="text-nowrap"><?= $it['sale_id'] !== null ? '<a href="' . url('sales/view', ['id' => $it['sale_id']]) . '"><code>' . e($it['invoice_no']) . '</code></a>' : e(ucfirst(str_replace('_', ' ', $it['type']))) ?></td>
              <td class="text-nowrap"><?= e(date('d/m/Y', strtotime($it['date']))) ?></td>
              <td class="text-nowrap <?= $it['status'] === 'overdue' ? 'text-danger' : '' ?>"><?= $it['due_date'] ? e(date('d/m/Y', strtotime($it['due_date']))) : '' ?></td>
              <td class="text-end"><?= usd($it['amount']) ?></td>
              <td class="text-end fw-semibold"><?= usd($it['remaining']) ?></td>
              <td><span class="badge <?= $badge[$it['status']] ?>"><?= $it['status'] === 'partly' ? 'partly paid' : $it['status'] ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($left <= 0.004): ?><tr><td colspan="6" class="empty">Nothing open. <?= (float) $outstanding['unapplied'] > 0.004 ? 'They have ' . usd($outstanding['unapplied']) . ' paid in advance.' : '' ?></td></tr><?php endif; ?>
          </tbody>
          <?php if ($left > 0.004): ?><tfoot class="totals"><tr class="grand"><td colspan="4">Total open</td><td class="text-end"><?= usd($left) ?></td><td></td></tr></tfoot><?php endif; ?>
        </table></div>
        <?php else: ?><div class="card-body text-muted">Choose the customer to see their open invoices.</div><?php endif; ?>
      </div>
      <?php if ($selected > 0): ?><a class="btn btn-link px-0 mt-2" href="<?= url('customers/statement', ['id' => $selected]) ?>" target="_blank"><i class="bi bi-printer"></i> Statement of account</a><?php endif; ?>
    </div>
  </div>
</form>
