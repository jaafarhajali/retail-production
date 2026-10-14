<?php
/** The official A4 invoice (F4), after the company's own template: header with MOF, Bill To with TVA, due date, boxes and quantity per box, signatures. */
$s = $sale;
$issued = strtotime($s['created_at']);
?>
<div class="inv inv-official">
  <header class="inv-top">
    <div class="inv-brand">
      <?php if (($logo = logo_url()) !== null): ?><img class="inv-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
      <?php if (setting('company_mof') !== ''): ?><div class="inv-mof">MOF: <?= e(setting('company_mof')) ?></div><?php endif; ?>
    </div>
    <div class="inv-company">
      <div class="inv-company-name" dir="auto"><?= e(setting('shop_name', APP_NAME)) ?></div>
      <?php if (setting('shop_address') !== ''): ?><div dir="auto"><?= e(setting('shop_address')) ?></div><?php endif; ?>
      <?php if (setting('shop_phone') !== ''): ?><div><?= e(setting('shop_phone')) ?></div><?php endif; ?>
      <?php if (setting('company_email') !== ''): ?><div><?= e(setting('company_email')) ?></div><?php endif; ?>
      <?php if (setting('company_mof') !== ''): ?><div><b>MOF: <?= e(setting('company_mof')) ?></b></div><?php endif; ?>
    </div>
  </header>
  <div class="inv-title"><i></i><span>Invoice</span><i></i></div>
  <?php if ($s['status'] === 'on_hold'): ?><div class="inv-flag">Not paid yet · goods out with the seller</div><?php endif; ?>
  <?php if ($s['status'] === 'voided'): ?><div class="inv-flag">VOIDED</div><?php endif; ?>
  <section class="inv-parties">
    <table class="inv-billto">
      <tr><th colspan="2">Bill To</th></tr>
      <tr><td>Name</td><td dir="auto"><?= e($s['customer_name'] ?? 'Walk-in customer') ?></td></tr>
      <tr><td>TVA</td><td><?= e($customer['tva_number'] ?? '') ?></td></tr>
      <tr><td>Address</td><td dir="auto"><?= e($customer['address'] ?? '') ?></td></tr>
      <tr><td>Phone</td><td><?= e($customer['phone'] ?? '') ?></td></tr>
    </table>
    <table class="inv-numbers">
      <tr><td>Invoice No.</td><td><b><?= e($s['invoice_no']) ?></b></td></tr>
      <tr><td>Date of Issue</td><td><?= e(date('Y-m-d', $issued)) ?></td></tr>
      <tr><td>Due Date</td><td><?= e($dueDate) ?></td></tr>
    </table>
  </section>
  <table class="inv-lines">
    <thead><tr><th>Item</th><th>Box</th><th>Qty in Box</th><th>Code</th><th class="l">Description</th><th>Total Qty</th><th>Unit Price</th><th>Amount</th></tr></thead>
    <tbody>
    <?php $n = 0; foreach ($items as $i): $n++; $factor = max(1, (int) $i['factor']); $boxes = $factor > 1 ? rtrim(rtrim($i['qty'], '0'), '.') : ''; ?>
      <tr>
        <td><?= $n ?></td>
        <td><?= e($boxes) ?></td>
        <td><?= $factor > 1 ? number_format($factor) : '' ?></td>
        <td class="code"><?= e($barcodes[(int) $i['product_unit_id']] ?? '') ?></td>
        <td class="l" dir="auto"><?= e($i['product_name']) ?><?= $factor > 1 ? ' · ' . e($i['unit_name']) : '' ?><?= !empty($i['note']) ? '<br><small>' . e($i['note']) . '</small>' : '' ?></td>
        <td><?= number_format((int) $i['base_qty']) ?></td>
        <td><?= usd((float) $i['unit_price_usd'] / $factor) ?></td>
        <td class="amount"><?= usd($i['line_total_usd']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($k = $n; $k < 12; $k++): ?><tr class="blank"><td colspan="8">&nbsp;</td></tr><?php endfor; ?>
    </tbody>
  </table>
  <?php if (!empty($s['notes'])): ?><p class="inv-notes" dir="auto"><b>Notes:</b> <?= e($s['notes']) ?></p><?php endif; ?>
  <section class="inv-totals">
    <table>
      <tr><td>Subtotal</td><td><?= usd($s['subtotal_usd']) ?></td></tr>
      <tr><td>Discount</td><td><?= usd($s['discount_usd']) ?></td></tr>
      <tr class="total"><td>Total</td><td><?= usd($s['total_usd']) ?></td></tr>
      <?php if ($credit !== null): ?><tr><td>Balance owed</td><td><?= usd($credit['balance']) ?></td></tr><?php endif; ?>
    </table>
  </section>
  <section class="inv-sign">
    <div>Recipient : المستلم</div>
    <div>Deliverer : المسلّم</div>
  </section>
  <footer class="inv-foot">Thank you for your business!</footer>
</div>
