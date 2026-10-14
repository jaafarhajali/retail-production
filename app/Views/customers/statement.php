<?php
/** Statement of account (2026-10-14): the customer's ledger over a period with a running balance, printed for them. */
$c = $customer;
?>
<div class="inv inv-statement">
  <header class="inv-top">
    <div class="inv-brand">
      <?php if (($logo = logo_url()) !== null): ?><img class="inv-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
    </div>
    <div class="inv-company">
      <div class="inv-company-name" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
      <?php if (setting('shop_address') !== ''): ?><div dir="auto"><?= e(setting('shop_address')) ?></div><?php endif; ?>
      <?php if (setting('shop_phone') !== ''): ?><div><?= e(setting('shop_phone')) ?></div><?php endif; ?>
      <?php if (setting('company_mof') !== ''): ?><div><b>MOF: <?= e(setting('company_mof')) ?></b></div><?php endif; ?>
    </div>
  </header>
  <div class="inv-title"><i></i><span>Statement of Account</span><i></i></div>
  <section class="inv-parties">
    <table class="inv-billto">
      <tr><th colspan="2">Customer</th></tr>
      <tr><td>Name</td><td dir="auto"><?= e($c['name']) ?></td></tr>
      <tr><td>TVA</td><td><?= e($c['tva_number'] ?? '') ?></td></tr>
      <tr><td>Address</td><td dir="auto"><?= e($c['address'] ?? '') ?></td></tr>
      <tr><td>Phone</td><td><?= e($c['phone'] ?? '') ?></td></tr>
    </table>
    <table class="inv-numbers">
      <tr><td>Period</td><td><?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></td></tr>
      <tr><td>Printed</td><td><?= e(date('d/m/Y')) ?></td></tr>
      <tr><td>Balance now</td><td><b><?= usd($balanceNow) ?></b></td></tr>
    </table>
  </section>
  <table class="inv-lines">
    <thead><tr><th>Date</th><th class="l">Description</th><th>Reference</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
    <tbody>
      <tr><td></td><td class="l"><b>Opening balance</b></td><td></td><td></td><td></td><td class="amount"><b><?= usd($opening) ?></b></td></tr>
      <?php $running = (float) $opening; foreach ($rows as $r): $amt = (float) $r['amount_usd']; $running += $amt; ?>
        <tr>
          <td><?= e(date('d/m/Y', strtotime($r['created_at']))) ?></td>
          <td class="l" dir="auto"><?= e($r['label']) ?></td>
          <td><?= e($r['reference'] ?? '') ?></td>
          <td><?= $amt > 0 ? usd($amt) : '' ?></td>
          <td><?= $amt < 0 ? usd(-$amt) : '' ?></td>
          <td class="amount"><?= usd($running) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="6" class="l">Nothing in this period.</td></tr><?php endif; ?>
    </tbody>
    <tfoot><tr><td></td><td class="l"><b>Closing balance</b></td><td></td><td><?= usd($debits) ?></td><td><?= usd($credits) ?></td><td class="amount"><b><?= usd($closing) ?></b></td></tr></tfoot>
  </table>
  <?php if ($open !== []): ?>
    <h3 class="inv-sub">Open invoices at <?= e(date('d/m/Y')) ?></h3>
    <table class="inv-lines">
      <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th>Amount</th><th>Paid</th><th>Left</th></tr></thead>
      <tbody><?php foreach ($open as $it): ?><tr><td><?= e($it['invoice_no'] ?? ucfirst(str_replace('_', ' ', $it['type']))) ?></td><td><?= e(date('d/m/Y', strtotime($it['date']))) ?></td><td><?= $it['due_date'] ? e(date('d/m/Y', strtotime($it['due_date']))) : '' ?><?= $it['status'] === 'overdue' ? ' (overdue)' : '' ?></td><td><?= usd($it['amount']) ?></td><td><?= usd($it['paid']) ?></td><td class="amount"><?= usd($it['remaining']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
  <footer class="inv-foot">Please settle the balance by the due dates shown. Thank you for your business!</footer>
</div>
