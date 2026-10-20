<?php $t = $d['total']; ?>
<h2 class="h6 text-muted">Discounts <?= e(date('d/m/Y', strtotime($from))) ?> – <?= e(date('d/m/Y', strtotime($to))) ?></h2>
<p class="text-muted small">Every reduction against the list price: a discount, a price lowered at the till, a customer's agreed price, a discount on the whole invoice.</p>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th class="text-end">At list price</th><th class="text-end">Discounts</th><th class="text-end">Charged</th><th class="text-end">Discount %</th></tr></thead>
  <tbody><tr><td class="text-end"><?= usd($t['list']) ?></td><td class="text-end fw-semibold"><?= usd($t['discount']) ?></td><td class="text-end"><?= usd($t['charged']) ?></td><td class="text-end"><?= e(number_format($t['pct'], 1)) ?> %</td></tr></tbody>
</table></div></div>
<?php foreach (['customers' => 'By customer', 'products' => 'By product'] as $key => $title): ?>
  <h3 class="h6 mt-3"><?= $title ?></h3>
  <div class="card mb-3"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th><?= $key === 'customers' ? 'Customer' : 'Product' ?></th><th class="text-end">At list price</th><th class="text-end">Discounts</th><th class="text-end">Charged</th></tr></thead>
    <tbody><?php foreach ($d[$key] as $r): ?><tr><td dir="auto"><?= e($r['name']) ?></td><td class="text-end"><?= usd($r['list']) ?></td><td class="text-end fw-semibold"><?= usd($r['discount']) ?></td><td class="text-end"><?= usd($r['charged']) ?></td></tr><?php endforeach; ?>
    <?php if ($d[$key] === []): ?><tr><td colspan="4" class="empty">No discount in this period.</td></tr><?php endif; ?></tbody>
  </table></div></div>
<?php endforeach; ?>
<h3 class="h6 mt-3">By invoice</h3>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Invoice</th><th>When</th><th>Customer</th><th class="text-end">At list price</th><th class="text-end">Discounts</th><th class="text-end">Charged</th></tr></thead>
  <tbody><?php foreach ($d['sales'] as $r): ?><tr><td><?= empty($printing) ? '<a href="' . url('sales/view', ['id' => $r['id']]) . '">' . e($r['invoice_no']) . '</a>' : e($r['invoice_no']) ?></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td dir="auto"><?= e($r['customer']) ?></td>
    <td class="text-end"><?= usd($r['list']) ?></td><td class="text-end fw-semibold"><?= usd($r['discount']) ?></td><td class="text-end"><?= usd($r['charged']) ?></td></tr><?php endforeach; ?>
  <?php if ($d['sales'] === []): ?><tr><td colspan="6" class="empty">No discount in this period.</td></tr><?php endif; ?></tbody>
</table></div></div>
