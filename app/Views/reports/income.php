<?php $neg = static fn (string $v): string => (float) $v < 0 ? '(' . usd(abs((float) $v)) . ')' : usd($v); ?>
<h2 class="h6 text-muted">Income statement · <?= e(date('d/m/Y', strtotime($a['from']))) ?> – <?= e(date('d/m/Y', strtotime($a['to']))) ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 acct">
  <tbody>
    <tr><td>Sales</td><td class="text-end"><?= usd($a['sales']) ?></td></tr>
    <tr><td class="ps-4">Sales returns</td><td class="text-end">(<?= usd($a['returns']) ?>)</td></tr>
    <tr class="sub"><td>Net sales</td><td class="text-end"><?= usd($a['net_sales']) ?></td></tr>
    <tr><td class="ps-4">Cost of goods sold <small class="text-muted">the mix and the packaging of what was sold, at cost</small></td><td class="text-end">(<?= usd($a['cogs']) ?>)</td></tr>
    <tr class="sub"><td>Gross profit</td><td class="text-end"><?= $neg($a['gross']) ?></td></tr>
    <?php foreach ($a['expenses'] as $x): ?><tr><td class="ps-4" dir="auto"><?= e($x['name']) ?> <small class="text-muted"><?= e($x['code']) ?></small></td><td class="text-end">(<?= usd($x['amount']) ?>)</td></tr><?php endforeach; ?>
    <?php if ((float) $a['losses'] != 0.0): ?><tr><td class="ps-4">Stock losses and corrections <small class="text-muted">waste, adjustments, stocktakes</small></td><td class="text-end"><?= (float) $a['losses'] > 0 ? '(' . usd($a['losses']) . ')' : usd(abs((float) $a['losses'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $a['cash_diff'] != 0.0): ?><tr><td class="ps-4">Cash differences at closing</td><td class="text-end"><?= (float) $a['cash_diff'] > 0 ? '(' . usd($a['cash_diff']) . ')' : usd(abs((float) $a['cash_diff'])) ?></td></tr><?php endif; ?>
    <tr class="sub"><td>Total expenses</td><td class="text-end">(<?= usd((float) $a['total_expenses'] + (float) $a['losses'] + (float) $a['cash_diff']) ?>)</td></tr>
    <tr class="total"><td>Net <?= (float) $a['net'] >= 0 ? 'profit' : 'loss' ?></td><td class="text-end <?= (float) $a['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $neg($a['net']) ?></td></tr>
  </tbody>
</table></div></div>
<p class="text-muted small mt-2">Sales are the invoices of the period, paid or not. Expenses are what was recorded on the Expenses page, by category; supplier payments are not expenses (what was bought is in the cost of goods sold when it is sold). Amounts in brackets are taken off.</p>
