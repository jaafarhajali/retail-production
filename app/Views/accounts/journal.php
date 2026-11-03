<?php
require __DIR__ . '/_tabs.php';
// after a refusal, the lines come back as they were typed
$oldLines = static fn (string $key): array => is_array($_SESSION['_old'][$key] ?? null) ? array_values($_SESSION['_old'][$key]) : [];
$oldAccounts = $oldLines('line_account');
$oldDebits = $oldLines('line_debit');
$oldCredits = $oldLines('line_credit');
$lineCount = max(2, count($oldAccounts));
$byType = [];
foreach ($postable as $a) {
    $byType[$a['type']][] = $a;
}
$options = static function (string $chosen) use ($byType, $types): string {
    $html = '<option value="">— account —</option>';
    foreach ($types as $type => $label) {
        if (empty($byType[$type])) {
            continue;
        }
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($byType[$type] as $a) {
            $html .= '<option value="' . (int) $a['id'] . '"' . ((string) $a['id'] === $chosen ? ' selected' : '') . '>' . e($a['number'] . ' · ' . $a['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
};
?>
<div class="row g-3">
  <div class="col-xl-5">
    <form method="post" action="<?= url('accounts/journal-save') ?>" class="card" id="journal-form"><div class="card-body">
      <?= csrf_field() ?>
      <h2 class="h5">New journal entry</h2>
      <div class="row g-2 mb-3">
        <div class="col-sm-5"><label class="form-label" for="je-date">Date</label><input class="form-control" type="date" id="je-date" name="entry_date" value="<?= old('entry_date', date('Y-m-d')) ?>" required></div>
        <div class="col-sm-7"><label class="form-label" for="je-description">What is it?</label><input class="form-control" id="je-description" name="description" maxlength="255" dir="auto" required value="<?= old('description') ?>" placeholder="Van bought on a loan"></div>
      </div>
      <table class="table table-sm mb-2 journal-lines">
        <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
        <tbody id="je-lines">
          <?php for ($i = 0; $i < $lineCount; $i++): ?>
            <tr>
              <td><select class="form-select form-select-sm" name="line_account[]" aria-label="Account of line <?= $i + 1 ?>"><?= $options((string) ($oldAccounts[$i] ?? '')) ?></select></td>
              <td><input class="form-control form-control-sm text-end" name="line_debit[]" inputmode="decimal" placeholder="0.00" value="<?= e((string) ($oldDebits[$i] ?? '')) ?>" aria-label="Debit of line <?= $i + 1 ?>"></td>
              <td><input class="form-control form-control-sm text-end" name="line_credit[]" inputmode="decimal" placeholder="0.00" value="<?= e((string) ($oldCredits[$i] ?? '')) ?>" aria-label="Credit of line <?= $i + 1 ?>"></td>
            </tr>
          <?php endfor; ?>
        </tbody>
        <tfoot>
          <tr><td><button class="btn btn-link btn-sm p-0" type="button" id="je-add"><i class="bi bi-plus-lg"></i> Add a line</button></td><td class="text-end fw-semibold" id="je-debits">$0.00</td><td class="text-end fw-semibold" id="je-credits">$0.00</td></tr>
        </tfoot>
      </table>
      <div class="small mb-3" id="je-balance" aria-live="polite"></div>
      <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save the entry</button>
      <div class="text-muted small mt-3">
        <p class="mb-1">Debits must equal credits. An entry cannot be edited or deleted: a mistake is undone with <b>Reverse</b>, then written again.</p>
        <p class="mb-1">Only your own accounts and the opening ones (bank, equipment, other assets, loans, other liabilities, capital) are offered; the others follow the records.</p>
        <p class="mb-0">Examples. A loan repaid from the bank: Loans debit, Bank credit. Money moved to Whish: Whish debit, Bank credit. A month of depreciation: Depreciation (expense) debit, Accumulated depreciation credit.</p>
      </div>
    </div></form>
  </div>
  <div class="col-xl-7">
    <form class="filters mb-3" method="get">
      <input type="hidden" name="r" value="accounts/journal">
      <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline-primary" type="submit">Show</button>
    </form>
    <?php foreach ($entries as $en): ?>
      <div class="card mb-2"><div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
          <div>
            <span class="text-muted small">#<?= (int) $en['id'] ?> · <?= e(date('d/m/Y', strtotime($en['entry_date']))) ?></span>
            <div class="fw-semibold" dir="auto"><?= e($en['description']) ?></div>
          </div>
          <div class="text-end small">
            <span class="text-muted"><?= e((string) $en['username']) ?></span>
            <?php if ($en['reversed_by'] !== null): ?><div><span class="badge text-bg-light">Reversed by #<?= (int) $en['reversed_by'] ?></span></div>
            <?php elseif ($en['reverses_id'] !== null): ?><div><span class="badge text-bg-light">Reverses #<?= (int) $en['reverses_id'] ?></span></div>
            <?php else: ?>
              <form method="post" action="<?= url('accounts/journal-reverse') ?>" data-confirm="Reverse entry #<?= (int) $en['id'] ?>? A mirror entry dated today undoes it."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $en['id'] ?>"><button class="btn btn-link btn-sm p-0" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Reverse</button></form>
            <?php endif; ?>
          </div>
        </div>
        <table class="table table-sm mb-0 mt-1 acct journal-entry">
          <tbody>
            <?php foreach ($en['lines'] as $l): ?>
              <tr><td class="<?= (float) $l['credit_usd'] > 0 ? 'ps-4' : '' ?>"><code><?= e($l['number']) ?></code> <span dir="auto"><?= e($l['name']) ?></span></td>
                <td class="text-end amt"><?= (float) $l['debit_usd'] > 0 ? usd($l['debit_usd']) : '' ?></td><td class="text-end amt"><?= (float) $l['credit_usd'] > 0 ? usd($l['credit_usd']) : '' ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>
    <?php endforeach; ?>
    <?php if ($entries === []): ?><div class="card"><div class="card-body empty">No journal entry in these dates.</div></div><?php endif; ?>
  </div>
</div>
<script>
(function () {
  var body = document.getElementById('je-lines'), add = document.getElementById('je-add');
  var debits = document.getElementById('je-debits'), credits = document.getElementById('je-credits'), balance = document.getElementById('je-balance');
  function amount(v) {
    v = String(v || '').replace(/\s/g, '');
    v = /^\d{1,3}(,\d{3})+(\.\d{1,2})?$/.test(v) ? v.replace(/,/g, '') : v.replace(',', '.');
    var n = parseFloat(v);
    return isFinite(n) && n > 0 ? Math.round(n * 100) : 0;
  }
  function money(c) { return '$' + (c / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function sum() {
    var d = 0, c = 0;
    body.querySelectorAll('input[name="line_debit[]"]').forEach(function (i) { d += amount(i.value); });
    body.querySelectorAll('input[name="line_credit[]"]').forEach(function (i) { c += amount(i.value); });
    debits.textContent = money(d); credits.textContent = money(c);
    if (d === 0 && c === 0) { balance.textContent = ''; balance.className = 'small mb-3'; return; }
    balance.textContent = d === c ? 'Debits and credits are equal.' : 'Off by ' + money(Math.abs(d - c)) + ': debits must equal credits.';
    balance.className = 'small mb-3 ' + (d === c ? 'text-success' : 'text-danger');
  }
  add.addEventListener('click', function () {
    var row = body.rows[body.rows.length - 1].cloneNode(true);
    row.querySelector('select').value = '';
    row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    body.appendChild(row);
    row.querySelector('select').focus();
  });
  body.addEventListener('input', sum);
  sum();
})();
</script>
