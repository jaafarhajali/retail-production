<?php
/** The A4 credit note (2026-10-14), in the style of the official invoice: what came back against which invoice, and the amount credited to the account. */
$r = $return;
?>
<div class="inv inv-official inv-credit">
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
    </div>
  </header>
  <div class="inv-title"><i></i><span>Credit Note</span><i></i></div>
  <section class="inv-parties">
    <table class="inv-billto">
      <tr><th colspan="2">Credit To</th></tr>
      <tr><td>Name</td><td dir="auto"><?= e($r['customer_name'] ?? 'Walk-in customer') ?></td></tr>
      <tr><td>TVA</td><td><?= e($customer['tva_number'] ?? '') ?></td></tr>
      <tr><td>Address</td><td dir="auto"><?= e($customer['address'] ?? '') ?></td></tr>
      <tr><td>Phone</td><td><?= e($customer['phone'] ?? '') ?></td></tr>
    </table>
    <table class="inv-numbers">
      <tr><td>Credit Note No.</td><td><b><?= e($r['return_no']) ?></b></td></tr>
      <tr><td>Date</td><td><?= e(date('Y-m-d', strtotime($r['created_at']))) ?></td></tr>
      <tr><td>Against Invoice</td><td><?= e($sale['invoice_no'] ?? '') ?><?= $sale !== null ? ' of ' . e(date('Y-m-d', strtotime($sale['created_at']))) : '' ?></td></tr>
    </table>
  </section>
  <table class="inv-lines">
    <thead><tr><th>Item</th><th class="l">Description</th><th>Qty</th><th>Unit Price</th><th>Condition</th><th>Amount</th></tr></thead>
    <tbody>
    <?php $n = 0; foreach ($items as $i): $n++; ?>
      <tr>
        <td><?= $n ?></td>
        <td class="l" dir="auto"><?= e($i['product_name']) ?><?= $i['unit_name'] !== '' && (int) ($i['factor'] ?? 1) > 1 ? ' · ' . e($i['unit_name']) : '' ?></td>
        <td><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td>
        <td><?= usd($i['unit_price_usd']) ?></td>
        <td><?= $i['item_condition'] === 'waste' ? 'damaged' : 'returned' ?></td>
        <td class="amount"><?= usd($i['refund_usd']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($k = $n; $k < 6; $k++): ?><tr class="blank"><td colspan="6">&nbsp;</td></tr><?php endfor; ?>
    </tbody>
  </table>
  <?php if ($r['reason']): ?><p class="inv-notes" dir="auto"><b>Reason:</b> <?= e($r['reason']) ?></p><?php endif; ?>
  <section class="inv-totals">
    <table>
      <tr class="total"><td>Credited to your account</td><td><?= usd($r['total_usd']) ?></td></tr>
      <?php if ($balance !== null): ?><tr><td>Balance after this credit</td><td><?= usd($balance) ?></td></tr><?php endif; ?>
    </table>
  </section>
  <section class="inv-sign">
    <div>Recipient : المستلم</div>
    <div>Deliverer : المسلّم</div>
  </section>
  <footer class="inv-foot">Thank you for your business!</footer>
</div>
