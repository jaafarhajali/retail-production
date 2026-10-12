<?php use App\Services\ProductionService; $x = $run; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="card"><div class="card-body">
    <dl class="kv">
      <dt>Run</dt><dd><code><?= e($x['run_no']) ?></code></dd>
      <dt>Made</dt><dd dir="auto"><?= e($x['product_name']) ?> <span class="badge <?= $x['product_kind'] === 'intermediate' ? 'text-bg-info' : 'text-bg-success' ?>"><?= $x['product_kind'] === 'intermediate' ? 'mix' : 'finished' ?></span></dd>
      <dt>Quantity</dt><dd><?= e(ProductionService::baseText((int) $x['qty_base'], $x['base_unit'])) ?></dd>
      <dt>Recipe</dt><dd dir="auto"><?= e($x['recipe_name']) ?> × <?= rtrim(rtrim($x['batches'], '0'), '.') ?></dd>
      <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($x['created_at']))) ?> · <?= e($x['username'] ?? '') ?></dd>
      <?php if ($showCost): ?>
        <dt>Materials</dt><dd><?= usd($x['material_cost_usd']) ?></dd>
        <?php if ((float) $x['extra_cost_usd'] > 0): ?><dt>Other costs</dt><dd><?= usd($x['extra_cost_usd']) ?></dd><?php endif; ?>
        <dt>Cost per unit</dt><dd><?= $x['base_unit'] === 'piece' ? usd($x['cost_per_base']) . ' per piece' : usd((float) $x['cost_per_base'] * 1000) . ' per ' . ($x['base_unit'] === 'g' ? 'kg' : 'L') ?></dd>
      <?php endif; ?>
      <?php if ($x['notes']): ?><dt>Notes</dt><dd dir="auto"><?= e($x['notes']) ?></dd><?php endif; ?>
    </dl>
    <a class="btn btn-link px-0" href="<?= url('production') ?>">Back to production</a>
  </div></div></div>
  <div class="col-lg-8">
    <div class="card"><div class="card-header">Taken out of stock</div><div class="table-responsive"><table class="table">
      <thead><tr><th>Material</th><th class="text-end">Quantity</th><?php if ($showCost): ?><th class="text-end">Cost then</th><th class="text-end">Cost</th><?php endif; ?></tr></thead>
      <tbody><?php foreach ($lines as $l): ?><tr><td dir="auto"><?= e($l['material_name']) ?><?= $l['material_kind'] === 'intermediate' ? ' <span class="badge text-bg-info">mix</span>' : '' ?></td><td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $l['qty_base'], $l['base_unit'])) ?></td>
        <?php if ($showCost): ?><td class="text-end text-nowrap"><?= $l['base_unit'] === 'piece' ? usd($l['cost_per_base']) . ' / piece' : usd((float) $l['cost_per_base'] * 1000) . ' / ' . ($l['base_unit'] === 'g' ? 'kg' : 'L') ?></td><td class="text-end"><?= usd($l['cost_usd']) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
      <?php if ($showCost): ?><tfoot class="totals"><tr class="grand"><td colspan="3">Total</td><td class="text-end"><?= usd($x['total_cost_usd']) ?></td></tr></tfoot><?php endif; ?>
    </table></div></div>
  </div>
</div>
