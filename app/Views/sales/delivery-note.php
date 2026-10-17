<?php
/** The regular invoice (F4): the one the seller carries, after the owner's paper form, without the delivery charge. Called "Regular invoice" everywhere since 2026-10-17. */
$s = $sale;
?>
<div class="inv inv-note">
  <header class="inv-top">
    <div class="inv-brand">
      <?php if (($logo = logo_url()) !== null): ?><img class="inv-logo" src="<?= e($logo) ?>" alt=""><?php endif; ?>
      <?php if (setting('company_mof') !== ''): ?><div class="inv-mof">MOF: <?= e(setting('company_mof')) ?></div><?php endif; ?>
    </div>
    <div class="inv-no">№ <b><?= e($s['invoice_no']) ?></b></div>
    <div class="inv-note-title">INVOICE<br><small>Regular Invoice</small></div>
  </header>
  <?php if ($s['status'] === 'on_hold'): ?><div class="inv-flag">Not paid yet · goods out with the seller</div><?php endif; ?>
  <?php if ($s['status'] === 'voided'): ?><div class="inv-flag">VOIDED</div><?php endif; ?>
  <section class="inv-parties">
    <table class="inv-billto">
      <tr><th colspan="2">Invoice to</th></tr>
      <tr><td>Date</td><td><?= e(date('d/m/Y', strtotime($s['created_at']))) ?></td></tr>
      <tr><td>Name</td><td dir="auto"><?= e($s['customer_name'] ?? 'Walk-in customer') ?></td></tr>
      <tr><td>Phone</td><td><?= e($customer['phone'] ?? '') ?></td></tr>
      <tr><td>Location</td><td dir="auto"><?= e($customer['address'] ?? '') ?></td></tr>
    </table>
    <div class="inv-ship"><div class="inv-ship-title">Shipping full address</div><div dir="auto"><?= nl2br(e($customer['address'] ?? '')) ?></div></div>
  </section>
  <table class="inv-lines">
    <thead><tr><th>No</th><th class="l">Item Description</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
    <tbody>
    <?php $n = 0; foreach ($items as $i): $n++; ?>
      <tr>
        <td><?= $n ?></td>
        <td class="l" dir="auto"><?= e($i['product_name']) ?><?= (int) $i['factor'] > 1 ? ' · ' . e($i['unit_name']) : '' ?><?= !empty($i['note']) ? '<br><small>' . e($i['note']) . '</small>' : '' ?></td>
        <td><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?></td>
        <td><?= (float) $i['unit_price_usd'] > 0 ? usd($i['unit_price_usd']) : 'Free' ?></td>
        <td class="amount"><?= (float) $i['line_total_usd'] > 0 ? usd($i['line_total_usd']) : 'Free' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php for ($k = $n; $k < 8; $k++): ?><tr class="blank"><td colspan="5">&nbsp;</td></tr><?php endfor; ?>
    </tbody>
  </table>
  <?php if (!empty($s['notes'])): ?><p class="inv-notes" dir="auto"><b>Notes:</b> <?= e($s['notes']) ?></p><?php endif; ?>
  <section class="inv-totals">
    <table>
      <tr><td>Sub Total</td><td><?= usd($s['subtotal_usd']) ?></td></tr>
      <?php if ((float) $s['discount_usd'] > 0): ?><tr><td>Discount</td><td>-<?= usd($s['discount_usd']) ?></td></tr><?php endif; ?>
      <tr class="total"><td>Total</td><td><?= usd($s['total_usd']) ?></td></tr>
      <?php if ($credit !== null): ?><tr><td>Balance owed</td><td><?= usd($credit['balance']) ?></td></tr><?php endif; ?>
    </table>
  </section>
  <footer class="inv-contact">
    <?php if (setting('shop_phone') !== ''): ?><span><?= e(setting('shop_phone')) ?></span><?php endif; ?>
    <?php if (setting('shop_address') !== ''): ?><span dir="auto"><?= e(setting('shop_address')) ?></span><?php endif; ?>
    <?php if (setting('company_email') !== ''): ?><span><?= e(setting('company_email')) ?></span><?php endif; ?>
    <span class="thanks"><?= e(setting('receipt_footer', 'Thank you for your purchase!')) ?></span>
  </footer>
</div>
