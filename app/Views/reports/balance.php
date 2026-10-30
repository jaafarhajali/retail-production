<?php $neg = static fn (string $v): string => (float) $v < 0 ? '(' . usd(abs((float) $v)) . ')' : usd($v); ?>
<div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2">
  <h2 class="h6 text-muted">Balance sheet · as of <?= e(date('d/m/Y', strtotime($a['to']))) ?> <small>(the period's profit since <?= e(date('d/m/Y', strtotime($a['year_start']))) ?>)</small></h2>
  <?php if (!empty($canOpen) && empty($printing)): ?><a class="small" href="<?= url('accounts/opening') ?>">Opening balances and fiscal year</a><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="table-responsive"><table class="table table-sm mb-0 acct">
    <thead><tr><th colspan="2">Assets</th></tr></thead>
    <tbody>
      <?php foreach ($a['assets'] as $r): ?><tr><td><?= e($r['name']) ?> <small class="text-muted"><?= e($r['code']) ?></small></td><td class="text-end"><?= $neg($r['amount']) ?></td></tr><?php endforeach; ?>
      <tr class="total"><td>Total assets</td><td class="text-end"><?= $neg($a['total_assets']) ?></td></tr>
    </tbody>
  </table></div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="table-responsive"><table class="table table-sm mb-0 acct">
    <thead><tr><th colspan="2">Liabilities and capital</th></tr></thead>
    <tbody>
      <?php foreach ($a['liabilities'] as $r): ?><tr><td><?= e($r['name']) ?> <small class="text-muted"><?= e($r['code']) ?></small></td><td class="text-end"><?= $neg($r['amount']) ?></td></tr><?php endforeach; ?>
      <tr class="sub"><td>Total liabilities</td><td class="text-end"><?= $neg($a['total_liabilities']) ?></td></tr>
      <?php foreach ($a['equity'] as $r): ?><tr><td><?= e($r['name']) ?> <small class="text-muted"><?= e($r['code']) ?></small></td><td class="text-end"><?= $neg($r['amount']) ?></td></tr><?php endforeach; ?>
      <tr class="sub"><td>Total capital</td><td class="text-end"><?= $neg($a['total_equity']) ?></td></tr>
      <tr class="total"><td>Total liabilities and capital</td><td class="text-end"><?= $neg($a['total_right']) ?></td></tr>
    </tbody>
  </table></div></div></div>
</div>
<p class="text-muted small mt-2">Cash, customers, stock and suppliers come from the records. Bank, equipment, loans and capital are the opening balances typed, moved by what happened since. "Differences" appears only when the typed figures do not explain the rest: then one of them is missing or wrong.</p>
