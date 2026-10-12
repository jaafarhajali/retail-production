<?php use App\Services\ProductionService; ?>
<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-gear"></i><?= count($runs) ?> run(s). Every run takes the materials out of stock and puts the product in, at the cost of the materials used.</p>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= url('recipes') ?>"><i class="bi bi-journal-text"></i> Recipes</a>
    <?php if ($canManage): ?><a class="btn btn-primary" href="<?= url('production/new') ?>"><i class="bi bi-play-fill"></i> Make</a><?php endif; ?>
  </div>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>No</th><th>When</th><th>Made</th><th class="text-end">Quantity</th><th>Recipe</th><?php if ($showCost): ?><th class="text-end">Cost</th><th class="text-end">Per unit</th><?php endif; ?><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($runs as $x): ?>
      <tr>
        <td><code><?= e($x['run_no']) ?></code></td>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($x['created_at']))) ?></td>
        <td dir="auto"><?= e($x['product_name']) ?> <span class="badge <?= $x['product_kind'] === 'intermediate' ? 'text-bg-info' : 'text-bg-success' ?>"><?= $x['product_kind'] === 'intermediate' ? 'mix' : 'finished' ?></span></td>
        <td class="text-end text-nowrap"><?= e(ProductionService::baseText((int) $x['qty_base'], $x['base_unit'])) ?></td>
        <td dir="auto"><?= e($x['recipe_name']) ?> <span class="text-muted">× <?= rtrim(rtrim($x['batches'], '0'), '.') ?></span></td>
        <?php if ($showCost): ?>
          <td class="text-end text-nowrap"><?= usd($x['total_cost_usd']) ?></td>
          <td class="text-end text-nowrap"><?= $x['base_unit'] === 'piece' ? usd($x['cost_per_base']) : usd((float) $x['cost_per_base'] * 1000) . ' <span class="text-muted">/ ' . ($x['base_unit'] === 'g' ? 'kg' : 'L') . '</span>' ?></td>
        <?php endif; ?>
        <td><?= e($x['username'] ?? '') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('production/view', ['id' => $x['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($runs === []): ?><tr><td colspan="<?= $showCost ? 9 : 7 ?>" class="empty">Nothing made yet. Write a recipe, then press Make.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
