<h2 class="h6 text-muted">Cash flow · <?= e(date('d/m/Y', strtotime($a['from']))) ?> – <?= e(date('d/m/Y', strtotime($a['to']))) ?></h2>
<div class="row g-3">
  <?php foreach (['drawers' => 'Cash in the drawers', 'bank' => "Bank and owner's money"] as $key => $title): $s = $a[$key]; ?>
    <div class="col-lg-6"><div class="card h-100"><div class="table-responsive"><table class="table table-sm mb-0 acct">
      <thead><tr><th colspan="2"><?= $title ?></th></tr></thead>
      <tbody>
        <tr class="sub"><td>Opening</td><td class="text-end"><?= usd($s['opening']) ?></td></tr>
        <?php foreach ($s['in'] as $r): ?><tr><td class="ps-4"><?= e($r['what']) ?></td><td class="text-end">+ <?= usd($r['amount']) ?></td></tr><?php endforeach; ?>
        <tr class="sub"><td>Money in</td><td class="text-end">+ <?= usd($s['total_in']) ?></td></tr>
        <?php foreach ($s['out'] as $r): ?><tr><td class="ps-4"><?= e($r['what']) ?></td><td class="text-end">− <?= usd($r['amount']) ?></td></tr><?php endforeach; ?>
        <tr class="sub"><td>Money out</td><td class="text-end">− <?= usd($s['total_out']) ?></td></tr>
        <tr class="total"><td>Closing</td><td class="text-end"><?= usd($s['closing']) ?></td></tr>
      </tbody>
    </table></div></div></div>
  <?php endforeach; ?>
</div>
<div class="card mt-3"><div class="table-responsive"><table class="table table-sm mb-0 acct">
  <tbody>
    <tr><td>All cash, opening</td><td class="text-end"><?= usd($a['opening_total']) ?></td></tr>
    <tr><td>Net change over the period</td><td class="text-end <?= (float) $a['net_change'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= (float) $a['net_change'] < 0 ? '− ' : '+ ' ?><?= usd(abs((float) $a['net_change'])) ?></td></tr>
    <tr class="total"><td>All cash, closing</td><td class="text-end"><?= usd($a['closing_total']) ?></td></tr>
  </tbody>
</table></div></div>
<p class="text-muted small mt-2">A sale on credit is not money until the customer pays; a purchase on credit is not money out until the supplier is paid. Lira is counted at the rate of today. A drawer that is counted hands its cash to "Bank and owner's money"; the floats put into drawers come from there.</p>
