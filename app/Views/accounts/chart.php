<?php
require __DIR__ . '/_tabs.php';
$typed = \App\Services\AccountingService::TYPED;
$groups = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Capital', 'income' => 'Income', 'expense' => 'Expenses'];
$fixed = array_filter($accounts, static fn (array $a): bool => $a['system_key'] === null || !str_starts_with((string) $a['system_key'], 'exp:'));
$movedBy = static function (array $a) use ($typed): string {
    if ($a['system_key'] === null) {
        return 'journal entries';
    }
    return in_array((string) $a['system_key'], $typed, true) ? 'opening + journal' : 'the records';
};
?>
<div class="row g-3">
  <div class="col-xl-8">
    <form method="post" action="<?= url('accounts/chart-save') ?>" class="card"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h5">The chart of accounts</h2>
      <p class="text-muted small">Give every account your own number and name; every statement shows them. Two accounts cannot share a number. Add your own accounts on the right as the business grows; close one you no longer use.</p>
      <div class="table-responsive"><table class="table table-sm mb-0 chart-edit">
        <thead><tr><th>No.</th><th>Account</th><th>Moved by</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($groups as $type => $groupLabel): ?>
            <tr class="group"><td colspan="4"><?= $groupLabel ?></td></tr>
            <?php foreach ($fixed as $a): if ($a['type'] !== $type) { continue; } $id = (int) $a['id']; $own = $a['system_key'] === null; $closed = (int) $a['is_active'] !== 1; ?>
              <tr class="<?= $closed ? 'is-closed' : '' ?>">
                <td><input class="form-control form-control-sm num" name="num[<?= $id ?>]" value="<?= e($a['number']) ?>" maxlength="10" required aria-label="Number of <?= e($a['name']) ?>"></td>
                <td><input class="form-control form-control-sm" name="name[<?= $id ?>]" value="<?= e($a['name']) ?>" maxlength="100" dir="auto" required aria-label="Name of account <?= e($a['number']) ?>"></td>
                <td class="text-muted small text-nowrap"><?= $movedBy($a) ?><?= (int) $a['is_money'] === 1 ? ' <span class="badge text-bg-light">money</span>' : '' ?></td>
                <td class="text-end text-nowrap">
                  <?php if ($own): ?>
                    <label class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="closed[<?= $id ?>]" value="1" <?= $closed ? 'checked' : '' ?>> Closed</label>
                    <?php if (empty($used[$id])): ?><button class="btn btn-link btn-sm text-danger p-0" type="submit" form="delete-<?= $id ?>" title="Delete this account (it has no entry yet)"><i class="bi bi-trash"></i></button><?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($type === 'expense'): ?>
              <?php foreach ($expenseAccounts as $x): ?>
                <tr>
                  <td><input class="form-control form-control-sm num" name="exp_num[]" value="<?= e($x['number']) ?>" maxlength="10" required aria-label="Number of <?= e($x['category']) ?>"><input type="hidden" name="exp_cat[]" value="<?= e($x['category']) ?>"></td>
                  <td dir="auto" class="ps-3"><?= e($x['category']) ?></td>
                  <td class="text-muted small text-nowrap">the Expenses page</td>
                  <td></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="text-muted small mt-2 mb-3">An expense category's name is the one typed on the Expenses page; the first time it is used it takes the first free number from <?= \App\Services\AccountingService::FIRST_EXPENSE_NUMBER ?>, and you can change it here.</p>
      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save the chart</button>
    </div></form>
    <?php foreach ($used as $id => $hasLines): if ($hasLines) { continue; } ?>
      <form id="delete-<?= (int) $id ?>" method="post" action="<?= url('accounts/delete') ?>" data-confirm="Delete this account? It has no entry, so nothing else changes." hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $id ?>"></form>
    <?php endforeach; ?>
  </div>
  <div class="col-xl-4">
    <form method="post" action="<?= url('accounts/add') ?>" class="card mb-3"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h5">Add an account</h2>
      <div class="row g-2">
        <div class="col-4"><label class="form-label" for="acc-number">No.</label><input class="form-control" id="acc-number" name="number" maxlength="10" required value="<?= old('number') ?>" placeholder="1510"></div>
        <div class="col-8"><label class="form-label" for="acc-name">Name</label><input class="form-control" id="acc-name" name="name" maxlength="100" dir="auto" required value="<?= old('name') ?>" placeholder="Vehicles"></div>
        <div class="col-12"><label class="form-label" for="acc-type">Kind</label>
          <select class="form-select" id="acc-type" name="type">
            <?php foreach ($types as $v => $l): ?><option value="<?= $v ?>" <?= old('type', 'asset') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-12" id="acc-money-row"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_money" value="1" <?= old('is_money') === '1' ? 'checked' : '' ?>> <span class="form-check-label">Money account (a bank, Whish, a safe): it gets its own box in the cash flow</span></label></div>
      </div>
      <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-plus-lg"></i> Add the account</button>
    </div></form>
    <div class="card"><div class="card-body small">
      <h2 class="h6">How the accounts move</h2>
      <p class="mb-2"><b>The records</b>: cash in the drawers, customers, stock, suppliers, sales, cost of goods sold and the expense categories follow the till, the invoices, the purchases and the expenses. No journal entry touches them, so they always agree with the records.</p>
      <p class="mb-2"><b>Opening + journal</b>: bank, equipment, other assets, loans, other liabilities and capital start from the <a href="<?= url('accounts/opening') ?>">opening balances</a>, then move by journal entries.</p>
      <p class="mb-2"><b>Journal entries</b>: your own accounts.</p>
      <p class="text-muted mb-0">Example: a van bought on a loan. Add 1510 Vehicles (asset) and 2110 Van loan (liability), then write a <a href="<?= url('accounts/journal') ?>">journal entry</a>: Vehicles debit 15,000, Van loan credit 15,000.</p>
    </div></div>
  </div>
</div>
<script>
(function () {
  var type = document.getElementById('acc-type'), row = document.getElementById('acc-money-row');
  function sync() { row.hidden = type.value !== 'asset'; }
  type.addEventListener('change', sync); sync();
})();
</script>
