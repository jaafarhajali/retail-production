<?php use App\Services\ProductionService; $tq = 0; $tt = 0.0; $tc = 0.0; ?>
<h2 class="h6 text-muted">Products sold <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?><?= $filterText !== '' ? ' · ' . e($filterText) : '' ?></h2>
<div class="card"><div class="table-responsive"><table class="table table-sm">
  <thead><tr><th>Product</th><th class="text-end">Quantity sold</th><th class="text-end">Returned</th><th class="text-end">Total sales</th><?php if ($showCost): ?><th class="text-end">Cost</th><th class="text-end">Margin</th><?php endif; ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $tt += (float) $r['total']; $tc += (float) $r['cost']; ?>
    <tr>
      <td dir="auto"><?= e($r['product_name']) ?></td>
      <td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $r['qty_base'], $r['base_unit'])) ?></td>
      <td class="text-end text-nowrap"><?= (int) $r['returned_base'] > 0 ? e(ProductionService::baseText((int) $r['returned_base'], $r['base_unit'])) : '—' ?></td>
      <td class="text-end"><?= usd($r['total']) ?></td>
      <?php if ($showCost): ?><td class="text-end"><?= usd($r['cost']) ?></td><td class="text-end <?= (float) $r['total'] - (float) $r['cost'] < 0 ? 'text-danger' : '' ?>"><?= usd((float) $r['total'] - (float) $r['cost']) ?></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($rows === []): ?><tr><td colspan="6" class="empty">Nothing sold in this period.</td></tr><?php endif; ?>
  </tbody>
  <tfoot><tr><td colspan="3" class="fw-semibold">Total</td><td class="text-end fw-semibold"><?= usd($tt) ?></td><?php if ($showCost): ?><td class="text-end fw-semibold"><?= usd($tc) ?></td><td class="text-end fw-semibold"><?= usd($tt - $tc) ?></td><?php endif; ?></tr></tfoot>
</table></div></div>
