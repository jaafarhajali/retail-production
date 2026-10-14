<div class="row g-3">
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <form method="post" action="<?= url('customers/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($customer['id'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" dir="auto" required maxlength="120" value="<?= old('name', $customer['name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="30" value="<?= old('phone', $customer['phone'] ?? '') ?>"></div>
        <div class="row g-2 mb-3">
          <div class="col-5"><label class="form-label" for="tva_number">TVA number</label><input class="form-control" id="tva_number" name="tva_number" maxlength="30" placeholder="for the official invoice" value="<?= old('tva_number', $customer['tva_number'] ?? '') ?>"></div>
          <div class="col-7"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" dir="auto" maxlength="255" placeholder="printed on both invoices" value="<?= old('address', $customer['address'] ?? '') ?>"></div>
        </div>
        <?php if (isset($_SESSION['_errors']['same_name'])): ?>
          <div class="form-check mb-3 same-name"><input class="form-check-input" type="checkbox" id="allow_same_name" name="allow_same_name" value="1"><label class="form-check-label" for="allow_same_name">Different person with the same name: save anyway</label></div>
        <?php endif; ?>
        <div class="row g-2 mb-3">
          <div class="col-6"><label class="form-label" for="default_price_level">Price level</label>
            <select class="form-select" id="default_price_level" name="default_price_level">
              <?php foreach (['retail', 'wholesale'] as $lv): ?><option value="<?= $lv ?>" <?= ($customer['default_price_level'] ?? 'retail') === $lv ? 'selected' : '' ?>><?= $lv ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-6"><label class="form-label" for="credit_limit_usd">Credit limit (USD)</label><input class="form-control" id="credit_limit_usd" name="credit_limit_usd" inputmode="decimal" placeholder="no limit" value="<?= old('credit_limit_usd', $customer['credit_limit_usd'] ?? '') ?>"></div>
        </div>
        <div class="mb-3"><label class="form-label" for="payment_terms_days">Payment terms (days)</label><input class="form-control" id="payment_terms_days" name="payment_terms_days" inputmode="numeric" placeholder="shop default: <?= (int) (new \App\Services\InvoiceService())->termsDays(null) ?>" value="<?= old('payment_terms_days', $customer['payment_terms_days'] ?? '') ?>"><div class="form-text">A desk invoice is due this many days after its date.</div></div>
        <div class="mb-3"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" dir="auto" rows="2"><?= old('notes', $customer['notes'] ?? '') ?></textarea></div>
        <?php if ($customer !== null): ?>
          <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (int) $customer['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Active</label></div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Save customer</button>
        <a class="btn btn-link" href="<?= url('customers') ?>">Back</a>
      </form>
    </div></div>
    <?php if ($customer !== null): ?>
      <div class="card mt-3"><div class="card-body">
        <?php $pending = (new \App\Services\DebtService())->pending((int) $customer['id']); $owes = (float) $customer['balance_usd'] + (float) $pending['amount']; ?>
        <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-3">
          <div><div class="balance-label"><?= $owes >= 0 ? 'Owes the shop' : 'The shop owes them' ?></div>
            <div class="balance-value <?= $owes > 0 ? 'is-debt' : '' ?>"><?= usd(abs($owes)) ?></div></div>
          <?php if ($customer['credit_limit_usd'] !== null): ?><div class="text-muted small">Credit limit <?= usd($customer['credit_limit_usd']) ?></div><?php endif; ?>
        </div>
        <?php if ((float) $pending['amount'] > 0): ?><p class="form-text">Includes <strong>+<?= usd($pending['amount']) ?></strong> price of the day, charged when they pay: <?= e((new \App\Services\DebtService())->describe($pending)) ?></p><?php endif; ?>
        <?php $cid = (int) $customer['id']; $pdo = \App\Core\Database::pdo();
          $tot = ['sales' => (float) $pdo->query("SELECT COALESCE(SUM(total_usd), 0) FROM sales WHERE customer_id = {$cid} AND status = 'completed'")->fetchColumn(),
                  'payments' => -(float) $pdo->query("SELECT COALESCE(SUM(amount_usd), 0) FROM customer_ledger WHERE customer_id = {$cid} AND type = 'payment'")->fetchColumn(),
                  'returns' => (float) $pdo->query("SELECT COALESCE(SUM(total_usd), 0) FROM returns WHERE customer_id = {$cid}")->fetchColumn() + (float) $pdo->query("SELECT COALESCE(SUM(t.returned_usd), 0) FROM sale_settlements t JOIN sales s ON s.id = t.sale_id WHERE s.customer_id = {$cid}")->fetchColumn(),
                  'on_hold' => (int) $pdo->query("SELECT COUNT(*) FROM sales WHERE customer_id = {$cid} AND status = 'on_hold'")->fetchColumn()]; ?>
        <dl class="kv mb-3">
          <dt>Total sales</dt><dd><?= usd($tot['sales']) ?></dd>
          <dt>Total payments</dt><dd><?= usd($tot['payments']) ?></dd>
          <dt>Total returns</dt><dd><?= usd($tot['returns']) ?></dd>
          <?php if ($tot['on_hold'] > 0): ?><dt>Out with the seller</dt><dd><?= $tot['on_hold'] ?> invoice<?= $tot['on_hold'] === 1 ? '' : 's' ?> on hold</dd><?php endif; ?>
        </dl>
        <?php if (\App\Core\Gate::allows('invoice.manage')): ?><p class="d-flex flex-wrap gap-2 mb-3"><a class="btn btn-outline-primary btn-sm" href="<?= url('invoices/pay', ['customer_id' => $customer['id']]) ?>"><i class="bi bi-cash-coin"></i> Record a payment</a><a class="btn btn-outline-primary btn-sm" href="<?= url('invoices', ['customer_id' => $customer['id']]) ?>"><i class="bi bi-file-earmark-text"></i> Invoices</a><a class="btn btn-outline-secondary btn-sm" href="<?= url('customers/statement', ['id' => $customer['id']]) ?>" target="_blank"><i class="bi bi-printer"></i> Statement of account</a></p><?php endif; ?>
        <h2 class="h6">Adjust the balance</h2>
        <form method="post" action="<?= url('customers/adjust') ?>" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $customer['id'] ?>">
          <div class="col-4"><label class="form-label" for="adjust-amount">USD</label><input class="form-control" id="adjust-amount" name="amount" inputmode="decimal" placeholder="+5 or -5" required></div>
          <div class="col-5"><label class="form-label" for="adjust-note">Reason</label><input class="form-control" id="adjust-note" name="note" dir="auto" placeholder="e.g. old debt" required></div>
          <div class="col-3"><button class="btn btn-outline-primary w-100" type="submit">Adjust</button></div>
        </form>
        <div class="form-text mt-2">+ adds to what they owe, − reduces it. Cash they pay back is collected at the till (Customer → Collect debt), so it goes into the drawer.</div>
      </div></div>
    <?php endif; ?>
  </div>
  <?php if ($customer !== null): ?>
    <div class="col-lg-7">
      <div class="card"><div class="card-header">Statement</div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Date</th><th>Type</th><th>Ref</th><th class="text-end">USD</th></tr></thead>
          <tbody>
          <?php foreach ($ledger as $l): ?>
            <tr><td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($l['created_at']))) ?></td><td class="text-nowrap"><?= e(ucfirst(str_replace('_', ' ', $l['type']))) ?></td>
              <td dir="auto"><?= e($l['invoice_no'] ?? $l['note'] ?? '') ?><?= $l['currency'] === 'LBP' ? ' (' . lbp($l['amount_original']) . ')' : '' ?></td>
              <td class="text-end <?= (float) $l['amount_usd'] < 0 ? 'text-success' : '' ?>"><?= usd($l['amount_usd']) ?></td></tr>
          <?php endforeach; ?>
          <?php if ($ledger === []): ?><tr><td colspan="4" class="empty">No entries yet. Credit sales, payments and adjustments show here.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  <?php endif; ?>
</div>
