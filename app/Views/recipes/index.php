<?php use App\Services\ProductionService; ?>
<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-journal-text"></i><?= count($recipes) ?> recipe(s). A recipe says what one batch of a mix is made of; Production makes it in batches, and Packing turns the mix into products with the quantities you type each time.</p>
  <?php if ($canManage): ?><a class="btn btn-primary" href="<?= url('recipes/edit') ?>"><i class="bi bi-plus-lg"></i> New recipe</a><?php endif; ?>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Makes</th><th>Recipe</th><th>One batch</th><th>Lines</th><?php if ($showCost): ?><th class="text-end">Batch cost now</th><th class="text-end">Cost per unit</th><?php endif; ?><th>Runs</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recipes as $r): ?>
      <tr>
        <td dir="auto"><?= e($r['product_name']) ?> <span class="badge <?= $r['product_kind'] === 'intermediate' ? 'text-bg-info' : 'text-bg-success' ?>"><?= $r['product_kind'] === 'intermediate' ? 'mix' : 'finished' ?></span></td>
        <td dir="auto"><?= e($r['name']) ?><?= (int) $r['is_default'] ? ' <span class="badge text-bg-light">default</span>' : '' ?></td>
        <td class="text-nowrap"><?= e(ProductionService::baseText((int) $r['output_base'], $r['base_unit'])) ?></td>
        <td><?= (int) $r['line_count'] ?></td>
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
    <?php if ($recipes === []): ?><tr><td colspan="<?= $showCost ? 8 : 6 ?>" class="empty">No recipes yet. Press New recipe: choose the mix it makes, then list what goes into one batch.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
