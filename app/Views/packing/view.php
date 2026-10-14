<?php use App\Services\ProductionService; $k = $packing; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Packing</dt><dd><code><?= e($k['pack_no']) ?></code></dd>
      <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($k['created_at']))) ?> · <?= e($k['username'] ?? '') ?></dd>
      <?php if ($showCost): ?><dt>Cost of what was used</dt><dd><?= usd($k['cost_usd']) ?></dd><?php endif; ?>
      <?php if ($k['notes']): ?><dt>Notes</dt><dd dir="auto"><?= e($k['notes']) ?></dd><?php endif; ?>
    </dl>
    <p class="text-muted small">The cost of the mix and the packaging is shared between the products in proportion to their selling price.</p>
    <a class="btn btn-link px-0" href="<?= url('production') ?>">Back to production</a>
  </div></div></div>
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header">Taken out of stock</div><div class="table-responsive"><table class="table m-0">
      <thead><tr><th>What</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost then</th><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($used as $l): ?><tr><td dir="auto"><?= e($l['product_name']) ?><?= $l['kind'] === 'intermediate' ? ' <span class="badge text-bg-info">mix</span>' : '' ?></td><td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $l['qty_base'], $l['base_unit'])) ?></td>
        <?php if ($showCost): ?><td class="text-end text-nowrap"><?= $l['base_unit'] === 'piece' ? usd($l['cost_per_base']) . ' / piece' : usd((float) $l['cost_per_base'] * 1000) . ' / ' . ($l['base_unit'] === 'g' ? 'kg' : 'L') ?></td><td class="text-end"><?= usd($l['cost_usd']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card"><div class="card-header">Put into stock</div><div class="table-responsive"><table class="table m-0">
      <thead><tr><th>Product</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost per piece</th><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($made as $l): ?><tr><td dir="auto"><?= e($l['product_name']) ?></td><td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $l['qty_base'], $l['base_unit'])) ?></td>
        <?php if ($showCost): ?><td class="text-end text-nowrap"><?= usd($l['cost_per_base']) ?></td><td class="text-end"><?= usd($l['cost_usd']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
      <?php if ($showCost): ?><tfoot class="totals"><tr class="grand"><td colspan="3">Total</td><td class="text-end"><?= usd($k['cost_usd']) ?></td></tr></tfoot><?php endif; ?>
    </table></div></div>
  </div>
</div>
