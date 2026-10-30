<?php if (form_error('form') !== ''): ?><div class="alert alert-danger"><?= form_error('form') ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-7">
    <form method="post" action="<?= url('accounts/opening-save') ?>" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h5">What the system cannot know</h2>
      <p class="text-muted small">The accounts below are not in any sale, purchase or expense, so they are typed once, as they were on the day the system started. Everything else (cash in the drawers, what customers owe, the stock, what suppliers are owed) the system works out by itself. Change these whenever the real figures change, for example a new machine or a loan repaid.</p>
      <div class="row g-3">
        <?php foreach ($codes as $code): [$name, $type] = $chart[$code]; ?>
          <div class="col-md-6">
            <label class="form-label" for="ob_<?= $code ?>"><?= e($name) ?> <small class="text-muted"><?= $code ?> · <?= $type ?></small></label>
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
      <div class="mt-4 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save</button><a class="btn btn-link" href="<?= url('reports', ['type' => 'balance']) ?>">Balance sheet</a></div>
    </div></form>
  </div>
  <div class="col-lg-5">
    <div class="card"><div class="card-body">
      <h2 class="h5">The chart of accounts</h2>
      <p class="text-muted small">Fixed. Expense categories appear as 61xx accounts, one per category, as they are used.</p>
      <table class="table table-sm mb-0"><tbody>
        <?php foreach ($chart as $code => [$name, $type]): ?><tr><td><code><?= $code ?></code></td><td><?= e($name) ?></td><td class="text-muted small"><?= $type ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="text-muted small mt-3 mb-0"><b>Bank and owner's money</b> is every dollar outside the drawers: what a counted drawer hands over, customer payments by card, Whish, transfer or cheque, and what pays the expenses marked "outside the drawer", the suppliers and the production costs. Type its balance on the start day; the system moves it from there.</p>
    </div></div>
  </div>
</div>
