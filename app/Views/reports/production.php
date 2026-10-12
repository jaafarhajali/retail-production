<?php use App\Services\ProductionService; ?>
<h2 class="h6 text-muted">Production <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Made</div><div class="table-responsive"><table class="table table-sm m-0">
      <thead><tr><th>Product</th><th class="text-end">Runs</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody><?php $tc = 0; foreach ($rows as $r): $tc += (float) $r['cost_usd']; ?><tr><td dir="auto"><?= e($r['product_name']) ?><?= $r['product_kind'] === 'intermediate' ? ' <span class="badge text-bg-info">mix</span>' : '' ?></td><td class="text-end"><?= (int) $r['runs'] ?></td><td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $r['qty_base'], $r['base_unit'])) ?></td><?php if ($showCost): ?><td class="text-end"><?= usd($r['cost_usd']) ?></td><?php endif; ?></tr><?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="4" class="empty">Nothing made in this period.</td></tr><?php endif; ?></tbody>
      <?php if ($showCost && $rows !== []): ?><tfoot class="totals"><tr class="grand"><td colspan="3">Total</td><td class="text-end"><?= usd($tc) ?></td></tr></tfoot><?php endif; ?>
    </table></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Materials consumed</div><div class="table-responsive"><table class="table table-sm m-0">
      <thead><tr><th>Material</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($consumed as $c): ?><tr><td dir="auto"><?= e($c['material_name']) ?><?= $c['material_kind'] === 'intermediate' ? ' <span class="badge text-bg-info">mix</span>' : '' ?></td><td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $c['qty_base'], $c['base_unit'])) ?></td><?php if ($showCost): ?><td class="text-end"><?= usd($c['cost_usd']) ?></td><?php endif; ?></tr><?php endforeach; ?>
      <?php if ($consumed === []): ?><tr><td colspan="3" class="empty">Nothing consumed in this period.</td></tr><?php endif; ?></tbody>
    </table></div></div>
  </div>
</div>
