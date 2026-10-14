<?php
use App\Services\ProductionService;

// one history: runs (mix made by recipe) and packings (mix turned into products), newest first
$items = [];
foreach ($runs as $x) {
    $items[] = ['kind' => 'run', 'at' => $x['created_at'], 'row' => $x];
}
foreach ($packings as $k) {
    $items[] = ['kind' => 'packing', 'at' => $k['created_at'], 'row' => $k];
}
usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']) ?: strcmp($b['row']['created_at'], $a['row']['created_at']));
?>
<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-gear"></i><?= count($runs) ?> run(s), <?= count($packings) ?> packing(s). A run makes the mix from a recipe; a packing turns the mix into products, with the quantities typed each time.</p>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= url('recipes') ?>"><i class="bi bi-journal-text"></i> Recipes</a>
    <?php if ($canManage): ?>
      <a class="btn btn-primary" href="<?= url('production/new') ?>"><i class="bi bi-play-fill"></i> Make the mix</a>
      <a class="btn btn-primary" href="<?= url('packing/new') ?>"><i class="bi bi-box2"></i> Pack into products</a>
    <?php endif; ?>
  </div>
</div>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>No</th><th>When</th><th>Made</th><th class="text-end">Quantity</th><th>From</th><?php if ($showCost): ?><th class="text-end">Cost</th><th class="text-end">Per unit</th><?php endif; ?><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $x = $it['row']; ?>
      <?php if ($it['kind'] === 'run'): ?>
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
      <?php else: ?>
      <tr>
        <td><code><?= e($x['pack_no']) ?></code></td>
        <td class="text-nowrap"><?= e(date('d/m/Y H:i', strtotime($x['created_at']))) ?></td>
        <td dir="auto"><?= e($x['made_text'] ?? '') ?> <span class="badge text-bg-success">packed</span></td>
        <td class="text-end text-nowrap"><?= number_format((int) $x['made_base']) ?> pieces</td>
        <td dir="auto"><?= e($x['used_text'] ?? '') ?></td>
        <?php if ($showCost): ?>
          <td class="text-end text-nowrap"><?= usd($x['cost_usd']) ?></td>
          <td class="text-end text-nowrap text-muted">per product</td>
        <?php endif; ?>
        <td><?= e($x['username'] ?? '') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('packing/view', ['id' => $x['id']]) ?>">Open</a></td>
      </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="<?= $showCost ? 9 : 7 ?>" class="empty">Nothing made yet. Write a recipe, make the mix, then pack it into products.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
