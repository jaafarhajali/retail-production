<?php $currentRoute = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'accounts'; ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <ul class="nav nav-pills gap-1 mb-0">
    <?php foreach (['accounts' => 'Chart of accounts', 'accounts/journal' => 'Journal entries', 'accounts/opening' => 'Opening balances'] as $tabRoute => $tabLabel): ?>
      <li class="nav-item"><a class="nav-link <?= $currentRoute === $tabRoute ? 'active' : '' ?>" href="<?= url($tabRoute) ?>"><?= $tabLabel ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="small"><a href="<?= url('reports', ['type' => 'balance']) ?>">Balance sheet</a> · <a href="<?= url('reports', ['type' => 'trial']) ?>">Trial balance</a></div>
</div>
