<?php require __DIR__ . '/_tabs.php'; ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" action="<?= url('accounts/opening-save') ?>" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h5">What the system cannot know</h2>
      <p class="text-muted small">The accounts below are not in any sale, purchase or expense, so they are typed once, as they were on the day the system started. Everything else (cash in the drawers, what customers owe, the stock, what suppliers are owed) the system works out by itself. After the start, record what changes (a new machine, a loan repaid) as a <a href="<?= url('accounts/journal') ?>">journal entry</a>, so the history stays.</p>
      <div class="row g-3">
        <?php foreach ($codes as $code): $acc = $chart[$code]; ?>
          <div class="col-md-6">
            <label class="form-label" for="ob_<?= $code ?>"><span dir="auto"><?= e($acc['name']) ?></span> <small class="text-muted"><?= e($acc['number']) ?> · <?= $acc['type'] ?></small></label>
            <div class="input-group"><span class="input-group-text">$</span><input class="form-control" id="ob_<?= $code ?>" name="ob_<?= $code ?>" inputmode="decimal" placeholder="0.00" value="<?= e(old('ob_' . $code, isset($typed[$code]) && (float) $typed[$code] != 0.0 ? $typed[$code] : '')) ?>"></div>
          </div>
        <?php endforeach; ?>
        <div class="col-md-6">
          <label class="form-label" for="fiscal_year_start_month">First month of the fiscal year</label>
          <select class="form-select" id="fiscal_year_start_month" name="fiscal_year_start_month">
            <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m === (int) old('fiscal_year_start_month', (string) $fiscalMonth) ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option><?php endfor; ?>
          </select>
          <div class="form-text">The profit of the period on the balance sheet and the trial balance runs from this month.</div>
        </div>
      </div>
      <div class="mt-4"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save</button></div>
    </div></form>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body small">
      <h2 class="h6"><span dir="auto"><?= e($chart['1100']['name']) ?></span> <span class="text-muted"><?= e($chart['1100']['number']) ?></span></h2>
      <p>Every dollar outside the drawers: what a counted drawer hands over, customer payments by card, Whish, transfer or cheque, and what pays the expenses marked "outside the drawer", the suppliers and the production costs. Type its balance on the start day; the system moves it from there.</p>
      <p class="mb-0 text-muted">A second bank or a Whish account of its own: add it on the <a href="<?= url('accounts') ?>">chart of accounts</a> as a money account, type its starting amount as a journal entry (the account debit, Capital credit), and move money to it with journal entries.</p>
    </div></div>
  </div>
</div>
