<?php $s = $sale; $voided = $s['status'] === 'voided'; $onHold = $s['status'] === 'on_hold'; $settlement = (new \App\Models\Sale())->settlement((int) $s['id']); ?>
<div class="row g-3">
  <div class="col-xl-4">
    <div class="card"><div class="card-body">
      <dl class="kv">
        <dt>Invoice</dt><dd><code><?= e($s['invoice_no']) ?></code><?= $voided ? ' <span class="badge text-bg-danger">voided</span>' : '' ?><?= $onHold ? ' <span class="badge text-bg-warning">on hold · out with the seller</span>' : '' ?><?= $settlement !== null ? ' <span class="badge text-bg-info">delivery</span>' : '' ?></dd>
        <dt>When</dt><dd><?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?></dd>
        <?php if (($s['origin'] ?? 'till') === 'desk'): ?>
          <dt>Written by</dt><dd><?= e($s['username']) ?><span class="cell-sub">Desk invoice · no cash session</span></dd>
          <dt>Due</dt><dd><?= $s['due_date'] ? e(date('d/m/Y', strtotime($s['due_date']))) : '' ?><?php if ($s['ship_to']): ?><span class="cell-sub" dir="auto">Deliver to: <?= e($s['ship_to']) ?></span><?php endif; ?></dd>
          <?php $open = $s['customer_id'] === null ? null : ((new \App\Services\InvoiceService())->forSales([(int) $s['customer_id']])[(int) $s['id']] ?? null); if ($open !== null): ?>
            <dt>Payment</dt><dd><?= ['paid' => 'Paid', 'partly' => 'Partly paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue'][$open['status']] ?><span class="cell-sub">paid <?= usd($open['paid']) ?> · left <?= usd($open['remaining']) ?></span></dd>
          <?php endif; ?>
        <?php else: ?>
          <dt>Cashier</dt><dd><?= e($s['username']) ?><span class="cell-sub"><span dir="auto"><?= e($s['register_name']) ?></span> · <?= e($s['session_no']) ?></span></dd>
        <?php endif; ?>
        <dt>Customer</dt><dd dir="auto"><?= e($s['customer_name'] ?? 'Walk-in') ?></dd>
        <dt>Prices</dt><dd><?= e(ucfirst($s['price_level'])) ?><span class="cell-sub">1 USD = <?= number_format((int) $s['exchange_rate']) ?> LBP</span></dd>
        <?php if ($voided): ?><dt>Voided</dt><dd><span dir="auto"><?= e($s['void_reason']) ?></span><span class="cell-sub"><?= e(date('d/m/Y H:i', strtotime($s['voided_at']))) ?></span></dd><?php endif; ?>
      </dl>
      <div class="d-flex flex-wrap gap-2">
        <?php if ($canReprint): ?><a class="btn btn-outline-primary" href="<?= url('sales/receipt', ['id' => $s['id'], 'copy' => 1]) ?>" target="_blank"><i class="bi bi-printer"></i> Reprint (COPY)</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('sales/print', ['id' => $s['id'], 'type' => 'normal']) ?>" target="_blank"><i class="bi bi-file-earmark-text"></i> Regular invoice</a>
        <a class="btn btn-outline-primary" href="<?= url('sales/print', ['id' => $s['id'], 'type' => 'official']) ?>" target="_blank"><i class="bi bi-file-earmark-ruled"></i> Official invoice</a>
        <?php if (($s['origin'] ?? 'till') === 'desk' && $s['status'] === 'completed' && \App\Core\Gate::allows('invoice.manage')): ?><a class="btn btn-outline-secondary" href="<?= url('invoices/credit', ['id' => $s['id']]) ?>"><i class="bi bi-arrow-return-left"></i> Credit note</a>
        <?php elseif ($canReturn && $s['status'] === 'completed'): ?><a class="btn btn-outline-secondary" href="<?= url('returns', ['invoice' => $s['invoice_no']]) ?>"><i class="bi bi-arrow-return-left"></i> Return items</a><?php endif; ?>
      </div>
    </div></div>
  </div>
  <div class="col-xl-8">
    <?php if ($settlement !== null): ?>
      <div class="card mb-3"><div class="card-header">Finished <?= e(date('d/m/Y H:i', strtotime($settlement['settled_at']))) ?> · <?= e($settlement['settled_by_name'] ?? '') ?></div><div class="card-body">
        <dl class="kv mb-2">
          <dt>Invoice as printed</dt><dd><?= usd($settlement['original_usd']) ?></dd>
          <dt>Came back</dt><dd><?= (float) $settlement['returned_usd'] > 0 ? '-' . usd($settlement['returned_usd']) : usd(0) ?><?php foreach ($settlement['returns'] as $b): ?><span class="cell-sub" dir="auto"><?= e(rtrim(rtrim($b['qty'], '0'), '.')) ?> <?= e($b['unit_name']) ?> <?= e($b['product_name']) ?> · <?= usd($b['value_usd']) ?></span><?php endforeach; ?></dd>
          <dt>To pay</dt><dd><?= usd($settlement['net_usd']) ?></dd>
          <dt>Paid</dt><dd><?= usd($settlement['paid_usd']) ?></dd>
          <dt>On the account</dt><dd class="<?= (float) $settlement['balance_usd'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= usd($settlement['balance_usd']) ?></dd>
        </dl>
        <span class="badge <?= (float) $settlement['balance_usd'] > 0.004 ? ((float) $settlement['paid_usd'] > 0.004 ? 'text-bg-warning' : 'text-bg-danger') : 'text-bg-success' ?>"><?= (float) $settlement['balance_usd'] > 0.004 ? ((float) $settlement['paid_usd'] > 0.004 ? 'Partially paid' : 'Unpaid') : 'Paid' ?></span>
        <?php if ($settlement['returns'] !== []): ?><span class="badge text-bg-secondary"><?= (float) $settlement['net_usd'] <= 0.004 ? 'Returned' : 'Partially returned' ?></span><?php endif; ?>
      </div></div>
    <?php endif; ?>
    <div class="card mb-3"><div class="table-responsive"><table class="table">
      <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th><th class="text-end">Returned</th></tr></thead>
      <tbody><?php foreach ($items as $i): ?>
        <tr><td dir="auto"><?= e($i['product_name']) ?><?= (int) $i['price_overridden'] ? ' <span class="badge text-bg-warning">price changed</span>' : '' ?></td>
          <td class="text-end text-nowrap"><?= e(rtrim(rtrim($i['qty'], '0'), '.')) ?> <?= e($i['unit_name']) ?></td><td class="text-end"><?= usd($i['unit_price_usd']) ?></td>
          <td class="text-end"><?= (float) $i['line_discount_usd'] > 0 ? '-' . usd($i['line_discount_usd']) : '' ?></td><td class="text-end"><?= usd($i['line_total_usd']) ?></td>
          <td class="text-end text-nowrap"><?= (int) $i['returned_base_qty'] > 0 ? e(\App\Services\Quantity::unitQty((int) $i['returned_base_qty'], (int) $i['factor'])) . ' ' . e($i['unit_name']) : '' ?></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot class="totals">
        <?php if ((float) $s['discount_usd'] > 0): ?>
          <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?= usd($s['subtotal_usd']) ?></td><td></td></tr>
          <tr><td colspan="4" class="text-end">Invoice discount</td><td class="text-end">-<?= usd($s['discount_usd']) ?></td><td></td></tr>
        <?php endif; ?>
        <tr class="grand"><td colspan="4" class="text-end">Total</td><td class="text-end"><?= usd($s['total_usd']) ?></td><td></td></tr>
      </tfoot>
    </table></div></div>
    <div class="card mb-3"><div class="card-header">Payments</div><div class="table-responsive"><table class="table table-sm">
      <tbody><?php foreach ($payments as $p): ?><tr><td><?= e(ucfirst($p['method'])) ?> <?= e($p['currency']) ?></td><td class="text-end"><?= $p['currency'] === 'USD' ? usd($p['amount']) : lbp($p['amount']) ?></td><td class="text-end text-muted"><?= usd($p['amount_usd']) ?></td></tr><?php endforeach; ?></tbody>
      <?php if (($s['origin'] ?? 'till') !== 'desk'): // change and rounding belong to a drawer; a desk invoice has none (2026-10-17) ?>
      <tfoot><tr><td>Change given</td><td class="text-end"><?= usd($s['change_usd']) ?> · <?= lbp($s['change_lbp']) ?></td><td class="text-end text-muted">rounding <?= usd($s['rounding_usd']) ?></td></tr></tfoot>
      <?php endif; ?>
    </table></div></div>
    <?php if ($returns !== []): ?>
      <div class="card"><div class="card-header">Returns</div><div class="table-responsive"><table class="table table-sm">
        <tbody><?php foreach ($returns as $r): ?><tr><td><a href="<?= url('returns/view', ['id' => $r['id']]) ?>"><?= e($r['return_no']) ?></a></td><td><?= e(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td class="text-end">-<?= usd($r['total_usd']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div></div>
    <?php endif; ?>
  </div>
</div>
