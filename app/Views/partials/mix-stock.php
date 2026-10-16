<?php
/** How much of each mix is in stock now (2026-10-15), on the Make and Pack pages: what Make adds to and Packing takes from. Needs $mixes. */
?>
<?php if ($mixes !== []): ?>
  <div class="alert alert-light border d-flex flex-wrap gap-4 py-2 mb-3">
    <?php foreach ($mixes as $m): ?>
      <span dir="auto"><i class="bi bi-box2"></i> <?= e($m['name']) ?> in stock: <strong><?= e(\App\Services\ProductionService::baseText((int) $m['stock_base'], $m['base_unit'])) ?></strong></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
