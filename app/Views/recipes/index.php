<?php
use App\Services\ProductionService;

// One mix (the usual case): the page speaks of flavours only; with several mixes it says which mix each flavour makes.
$single = count($mixes) <= 1;
$cols = ($single ? 5 : 6) + ($showCost ? 2 : 0);
?>
<div class="toolbar">
  <div class="toolbar-note">
    <?php foreach ($mixes as $m): ?>
      <div dir="auto"><i class="bi bi-box2"></i> <?= e($m['name']) ?> in stock: <strong><?= e(ProductionService::baseText((int) $m['stock_base'], $m['base_unit'])) ?></strong></div>
    <?php endforeach; ?>
    <div class="text-muted small">Each flavour says what one batch is made of. <strong>Make</strong> adds it to the stock<?= $single ? '' : ' of its mix' ?>; Packing turns that stock into jars and boxes.</div>
  </div>
  <?php if ($canManage): ?><a class="btn btn-primary" href="<?= url('recipes/edit') ?>"><i class="bi bi-plus-lg"></i> Add a flavour</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><?php if (!$single): ?><th>Makes</th><?php endif; ?><th>Flavour</th><th>One batch</th><th>What goes in</th><?php if ($showCost): ?><th class="text-end">Batch cost now</th><th class="text-end">Cost per unit</th><?php endif; ?><th>Runs</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recipes as $r): ?>
      <tr>
        <?php if (!$single): ?><td dir="auto"><?= e($r['product_name']) ?></td><?php endif; ?>
        <td dir="auto"><strong><?= e($r['name']) ?></strong><?= (int) $r['is_default'] ? ' <span class="badge text-bg-light">shown first</span>' : '' ?></td>
        <td class="text-nowrap"><?= e(ProductionService::baseText((int) $r['output_base'], $r['base_unit'])) ?></td>
        <td class="small" dir="auto"><?= e($r['summary']) ?></td>
        <?php if ($showCost): ?>
          <td class="text-end text-nowrap"><?= usd($r['estimate']['batch_cost']) ?></td>
          <td class="text-end text-nowrap"><?= $r['base_unit'] === 'piece' ? usd($r['estimate']['cost_per_base']) . ' <span class="text-muted">per piece</span>' : usd((float) $r['estimate']['cost_per_base'] * 1000) . ' <span class="text-muted">per ' . ($r['base_unit'] === 'g' ? 'kg' : 'L') . '</span>' ?></td>
        <?php endif; ?>
        <td><?= (int) $r['run_count'] ?></td>
        <td class="text-end text-nowrap">
          <?php if ($canManage): ?><a class="btn btn-sm btn-primary" href="<?= url('production/new', ['recipe_id' => $r['id']]) ?>"><i class="bi bi-play-fill"></i> Make</a> <a class="btn btn-sm btn-outline-primary" href="<?= url('recipes/edit', ['id' => $r['id']]) ?>">Edit</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($recipes === []): ?><tr><td colspan="<?= $cols ?>" class="empty">No flavours yet. Press Add a flavour: its name, and what goes into one batch.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
