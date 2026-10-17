<h2 class="h6 text-muted">Customer debts, and how late each one is · <?= e(date('d/m/Y')) ?></h2>
<p class="text-muted small">Every open invoice of every customer, placed by how long it is past its due date. "Not yet due" is owed but still within the terms. Payments are applied to the oldest invoice first.</p>
<div class="card"><div class="table-responsive"><table class="table table-sm m-0">
  <thead><tr><th>Customer</th><th class="text-end">Not yet due</th><th class="text-end">1–30 days</th><th class="text-end">31–60 days</th><th class="text-end">Over 60 days</th><th class="text-end">Total owed</th><th class="text-end">Oldest</th></tr></thead>
  <tbody>
  <?php $t = ['current' => 0.0, 'b30' => 0.0, 'b60' => 0.0, 'b90' => 0.0, 'total' => 0.0]; foreach ($rows as $r): foreach ($t as $k => $v) { $t[$k] += (float) $r[$k]; } ?>
    <tr>
      <td dir="auto"><?= $printing ?? false ? e($r['name']) : '<a href="' . url('customers/edit', ['id' => $r['customer_id']]) . '">' . e($r['name']) . '</a>' ?></td>
      <td class="text-end"><?= (float) $r['current'] > 0 ? usd($r['current']) : '' ?></td>
      <td class="text-end"><?= (float) $r['b30'] > 0 ? usd($r['b30']) : '' ?></td>
      <td class="text-end text-warning-emphasis"><?= (float) $r['b60'] > 0 ? usd($r['b60']) : '' ?></td>
      <td class="text-end text-danger fw-semibold"><?= (float) $r['b90'] > 0 ? usd($r['b90']) : '' ?></td>
      <td class="text-end fw-semibold"><?= usd($r['total']) ?></td>
      <td class="text-end text-nowrap"><?= $r['oldest_days'] !== null ? (int) $r['oldest_days'] . ' days' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="7" class="empty">Nobody owes anything.</td></tr><?php endif; ?>
  </tbody>
  <?php if ($rows !== []): ?><tfoot class="totals"><tr class="grand"><td>Total</td><td class="text-end"><?= usd($t['current']) ?></td><td class="text-end"><?= usd($t['b30']) ?></td><td class="text-end"><?= usd($t['b60']) ?></td><td class="text-end"><?= usd($t['b90']) ?></td><td class="text-end"><?= usd($t['total']) ?></td><td></td></tr></tfoot><?php endif; ?>
</table></div></div>
