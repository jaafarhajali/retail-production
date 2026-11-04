<div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
  <h2 class="h6 text-muted">Trial balance · as of <?= e(date('d/m/Y', strtotime($a['to']))) ?> <small>(income and expenses since <?= e(date('d/m/Y', strtotime($a['year_start']))) ?>)</small></h2>
  <?php if (!empty($canOpen) && empty($printing)): ?><a class="small" href="<?= url('accounts/opening', ['back' => 'reports?' . http_build_query(['type' => $type, 'from' => $from, 'to' => $to])]) ?>">Opening balances and fiscal year</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 acct">
  <thead><tr><th>No.</th><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
  <tbody>
    <?php foreach ($a['rows'] as $r): ?><tr><td><code><?= e($r['code']) ?></code></td><td dir="auto"><?= e($r['name']) ?></td><td class="text-end"><?= $r['debit'] !== '' ? usd($r['debit']) : '' ?></td><td class="text-end"><?= $r['credit'] !== '' ? usd($r['credit']) : '' ?></td></tr><?php endforeach; ?>
    <?php if ($a['rows'] === []): ?><tr><td colspan="4" class="empty">Nothing recorded yet.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr class="total"><td></td><td>Total</td><td class="text-end"><?= usd($a['total_debit']) ?></td><td class="text-end"><?= usd($a['total_credit']) ?></td></tr></tfoot>
</table></div></div>
<p class="text-muted small mt-2"><?= $a['balanced'] ? 'The two columns are equal: the accounts balance.' : 'The two columns differ: a typed opening balance is missing or wrong (see "Differences").' ?> Assets and expenses are debits, liabilities, capital and income are credits.</p>
