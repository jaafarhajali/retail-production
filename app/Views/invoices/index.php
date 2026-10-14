<?php $badge = ['paid' => 'text-bg-success', 'partly' => 'text-bg-info', 'unpaid' => 'text-bg-warning', 'overdue' => 'text-bg-danger']; $label = ['paid' => 'paid', 'partly' => 'partly paid', 'unpaid' => 'unpaid', 'overdue' => 'overdue']; ?>
<div class="toolbar">
  <p class="toolbar-note"><i class="bi bi-file-earmark-text"></i><?= count($rows) ?> invoice(s) shown · <strong><?= usd($open) ?></strong> still to be paid on this page. Written at the desk; payments are applied to the oldest invoice first.</p>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= url('reports', ['type' => 'aging']) ?>"><i class="bi bi-hourglass-split"></i> Who owes, by age</a>
    <a class="btn btn-outline-primary" href="<?= url('invoices/pay', $filters['customer_id'] > 0 ? ['customer_id' => $filters['customer_id']] : []) ?>"><i class="bi bi-cash-coin"></i> Record a payment</a>
    <a class="btn btn-primary" href="<?= url('invoices/new') ?>"><i class="bi bi-plus-lg"></i> New invoice</a>
  </div>
</div>
<form class="filters mb-3" method="get">
  <input type="hidden" name="r" value="invoices">
  <div><label class="form-label">Show</label><select class="form-select" name="status"><?php foreach (['open' => 'Open (unpaid or partly paid)', 'overdue' => 'Overdue', 'paid' => 'Paid', 'all' => 'All'] as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label">Customer</label><select class="form-select" name="customer_id"><option value="0">All</option><?php foreach ($customers as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $filters['customer_id'] ? 'selected' : '' ?> dir="auto"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label">Invoice no.</label><input class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="INV-000031"></div>
  <div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($filters['from']) ?>"></div>
  <div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($filters['to']) ?>"></div>
  <button class="btn btn-outline-primary" type="submit">Filter</button>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Left</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
      <tr>
        <td class="text-nowrap"><code><?= e($s['invoice_no']) ?></code><?= ($s['invoice_type'] ?? 'normal') === 'official' ? ' <span class="badge text-bg-dark">official</span>' : '' ?></td>
        <td class="text-nowrap"><?= e(date('d/m/Y', strtotime($s['created_at']))) ?></td>
        <td dir="auto"><?= e($s['customer_name'] ?? '') ?></td>
        <td class="text-nowrap <?= $s['pay_status'] === 'overdue' ? 'text-danger fw-semibold' : '' ?>"><?= $s['due_date'] ? e(date('d/m/Y', strtotime($s['due_date']))) : '' ?></td>
        <td class="text-end"><?= usd($s['total_usd']) ?></td>
        <td class="text-end"><?= usd($s['paid']) ?></td>
        <td class="text-end <?= (float) $s['remaining'] > 0 ? 'fw-semibold' : '' ?>"><?= usd($s['remaining']) ?></td>
        <td><span class="badge <?= $badge[$s['pay_status']] ?>"><?= $label[$s['pay_status']] ?></span></td>
        <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= url('sales/print', ['id' => $s['id'], 'type' => 'official']) ?>" target="_blank" title="Print the invoice"><i class="bi bi-printer"></i></a> <a class="btn btn-sm btn-outline-primary" href="<?= url('sales/view', ['id' => $s['id']]) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td colspan="9" class="empty">No invoices here. Press New invoice to write one for a customer.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php require APP_PATH . '/Views/partials/pagination.php'; ?>
