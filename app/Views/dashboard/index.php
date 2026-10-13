<?php
use App\Core\Gate;
use App\Services\ProductionService;

$canSell = Gate::allows('pos.use');
$d = $dash;   // null for a user without report.sales
?>
<div class="dash">
  <?php if ($d !== null): ?>
    <?php $ms = $d['month_sales']; $be = $d['break_even']; $paidShare = (float) $ms['total'] > 0 ? (float) $ms['paid'] / (float) $ms['total'] : 0; $wsShare = (float) $ms['total'] > 0 ? (float) $ms['wholesale'] / (float) $ms['total'] : 0; ?>
    <section class="dash-panel dash-flow" aria-label="The business this month">
      <ol class="flow">
        <li><a href="<?= url('products', ['kind' => 'material']) ?>"><span class="flow-step">Raw materials</span><span class="flow-figure"><?= usd($d['stock']['material']['value_usd']) ?></span><span class="flow-note"><?= $d['stock']['material']['n'] ?> items in stock<?= $d['stock']['material']['low'] > 0 ? ' · <b class="is-warn">' . $d['stock']['material']['low'] . ' low</b>' : '' ?></span></a></li>
        <li><a href="<?= url('production') ?>"><span class="flow-step">Production</span><span class="flow-figure"><?= (int) $d['production']['runs'] ?> run<?= $d['production']['runs'] === 1 ? '' : 's' ?></span><span class="flow-note"><?= usd($d['production']['cost']) ?> of materials used this month</span></a></li>
        <li><a href="<?= url('products') ?>"><span class="flow-step">Finished stock</span><span class="flow-figure"><?= usd($d['stock']['finished']['value_usd']) ?></span><span class="flow-note"><?= $d['stock']['finished']['n'] ?> products<?= $d['stock']['finished']['low'] > 0 ? ' · <b class="is-warn">' . $d['stock']['finished']['low'] . ' low</b>' : '' ?></span></a></li>
        <li><a href="<?= url('sales') ?>"><span class="flow-step">Out with the seller</span><span class="flow-figure"><?= usd($d['held']['total']) ?></span><span class="flow-note"><?= $d['held']['n'] ?> invoice<?= $d['held']['n'] === 1 ? '' : 's' ?> on hold</span></a></li>
        <li class="flow-end"><a href="<?= url('reports', ['type' => 'credit']) ?>"><span class="flow-step">Owed by customers</span><span class="flow-figure <?= (float) $d['owed']['total'] > 0 ? 'is-owed' : '' ?>"><?= usd($d['owed']['total']) ?></span><span class="flow-note"><?= $d['owed']['customers'] ?> customer<?= $d['owed']['customers'] === 1 ? '' : 's' ?> still owe</span></a></li>
      </ol>
    </section>

    <section class="dash-panel dash-sales">
      <h2 class="dash-label">Sales in <?= e($d['month_label']) ?></h2>
      <div class="dash-figure"><?= usd($ms['total']) ?></div>
      <dl class="dash-meta">
        <div><dt>Today</dt><dd><?= usd($d['today']['total']) ?></dd></div>
        <div><dt>Invoices</dt><dd><?= $ms['invoices'] ?></dd></div>
        <div><dt>Pieces sold</dt><dd><?= number_format($ms['units']) ?></dd></div>
      </dl>
      <div class="split">
        <div class="split-row"><span>Wholesale <?= usd($ms['wholesale']) ?></span><span>Retail <?= usd($ms['retail']) ?></span></div>
        <div class="split-bar"><span style="width: <?= round($wsShare * 100) ?>%"></span></div>
      </div>
      <div class="split">
        <div class="split-row"><span>Paid <?= usd($ms['paid']) ?> (<?= $ms['paid_invoices'] ?> invoice<?= $ms['paid_invoices'] === 1 ? '' : 's' ?>)</span><span>On credit <?= usd($ms['credit']) ?> (<?= $ms['unpaid_invoices'] ?>)</span></div>
        <div class="split-bar is-money"><span style="width: <?= round($paidShare * 100) ?>%"></span></div>
      </div>
    </section>

    <section class="dash-panel dash-bep">
      <h2 class="dash-label">Break-even this month</h2>
      <?php if (!$be['ready']): ?>
        <p class="dash-empty"><?= $be['categories'] === [] ? 'Tell the system which expense categories are fixed costs (rent, salaries, electricity) on the Settings page, and sell at least one piece: then it says how many pieces cover the month.' : 'Nothing sold yet this month. The figures appear with the first sale.' ?></p>
        <?php if ($be['categories'] === [] && Gate::allows('settings.manage')): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('settings') ?>">Choose the fixed costs</a><?php endif; ?>
      <?php else: ?>
        <div class="bep-grid">
          <div><span>Fixed costs</span><b><?= usd($be['fixed_usd']) ?></b><small><?= e(implode(', ', $be['categories'])) ?></small></div>
          <div><span>Margin per piece</span><b><?= usd($be['margin']) ?></b><small>sold at <?= usd($be['avg_price']) ?>, cost <?= usd($be['avg_cost']) ?></small></div>
          <?php if ($be['bep_units'] === null): ?>
            <div class="bep-wide"><span>Break-even</span><b>Not reachable</b><small>The pieces sell under their cost this month; the margin is zero or negative.</small></div>
          <?php else: ?>
            <div><span>Break-even</span><b><?= number_format($be['bep_units']) ?> pieces</b><small><?= usd($be['bep_revenue']) ?> of sales</small></div>
            <div><span>Sold so far</span><b><?= number_format($be['units_sold']) ?> pieces</b><small><?= $be['remaining_units'] > 0 ? number_format($be['remaining_units']) . ' more to break even' : 'past break-even' ?></small></div>
          <?php endif; ?>
        </div>
        <?php if ($be['bep_units'] !== null): ?>
          <div class="bep-bar" role="img" aria-label="<?= $be['pct'] ?> % of break-even"><span style="width: <?= min(100, $be['pct']) ?>%"></span></div>
          <div class="bep-foot"><span><?= $be['pct'] ?> % of break-even</span><span class="<?= (float) $be['profit'] >= 0 ? 'is-good' : 'is-warn' ?>"><?= (float) $be['profit'] >= 0 ? 'Profit so far ' : 'Loss so far ' ?><?= usd(abs((float) $be['profit'])) ?></span></div>
        <?php endif; ?>
        <p class="dash-fine">Only as good as the costs behind it: material costs from production, fixed costs from the expenses you marked.</p>
      <?php endif; ?>
    </section>

    <section class="dash-panel dash-products">
      <div class="dash-head"><h2 class="dash-label">Products sold in <?= e($d['month_label']) ?></h2><a class="dash-more" href="<?= url('reports', ['type' => 'products', 'month' => $d['month']]) ?>">Full report, every month</a></div>
      <table class="dash-table">
        <thead><tr><th>Product</th><th class="r">Quantity</th><th class="r">Sales</th></tr></thead>
        <tbody>
        <?php foreach ($d['products'] as $p): ?><tr><td dir="auto"><?= e($p['product_name']) ?></td><td class="r"><?= e(ProductionService::baseText((int) $p['qty_base'], $p['base_unit'])) ?></td><td class="r"><?= usd($p['total']) ?></td></tr><?php endforeach; ?>
        <?php if ($d['products'] === []): ?><tr><td colspan="3" class="dash-empty">Nothing sold yet this month.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="dash-panel dash-owed">
      <div class="dash-head"><h2 class="dash-label">Who owes</h2><a class="dash-more" href="<?= url('reports', ['type' => 'credit']) ?>">All debtors</a></div>
      <table class="dash-table">
        <tbody>
        <?php foreach ($d['owed']['top'] as $c): ?><tr><td dir="auto"><a href="<?= url('customers/edit', ['id' => $c['id']]) ?>"><?= e($c['name']) ?></a></td><td class="r is-owed"><?= usd($c['balance_usd']) ?></td></tr><?php endforeach; ?>
        <?php if ($d['owed']['top'] === []): ?><tr><td colspan="2" class="dash-empty">Nobody owes anything.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php if ($d['low'] !== []): ?>
        <h2 class="dash-label mt-3">Low stock</h2>
        <table class="dash-table">
          <tbody><?php foreach ($d['low'] as $l): ?><tr><td dir="auto"><?= e($l['name']) ?> <small class="text-muted"><?= $l['kind'] === 'material' ? 'raw material' : ($l['kind'] === 'intermediate' ? 'mix' : '') ?></small></td><td class="r is-warn"><?= e(ProductionService::baseText((int) $l['stock_base'], $l['base_unit'])) ?></td></tr><?php endforeach; ?></tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="dash-panel dash-production">
      <div class="dash-head"><h2 class="dash-label">Made in <?= e($d['month_label']) ?></h2><a class="dash-more" href="<?= url('reports', ['type' => 'production', 'from' => $d['from'], 'to' => $d['to']]) ?>">Production report</a></div>
      <table class="dash-table">
        <tbody>
        <?php foreach ($d['production']['made'] as $r): ?><tr><td dir="auto"><?= e($r['product_name']) ?><?= $r['product_kind'] === 'intermediate' ? ' <small class="text-muted">mix</small>' : '' ?></td><td class="r"><?= e(ProductionService::baseText((int) $r['qty_base'], $r['base_unit'])) ?></td></tr><?php endforeach; ?>
        <?php if ($d['production']['made'] === []): ?><tr><td colspan="2" class="dash-empty">Nothing made yet this month.</td></tr><?php endif; ?>
        </tbody>
      </table>
      <?php if ($d['production']['consumed'] !== []): ?>
        <h2 class="dash-label mt-3">Materials used</h2>
        <table class="dash-table"><tbody><?php foreach (array_slice($d['production']['consumed'], 0, 6) as $c): ?><tr><td dir="auto"><?= e($c['material_name']) ?></td><td class="r"><?= e(ProductionService::baseText((int) $c['qty_base'], $c['base_unit'])) ?></td></tr><?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($backup !== null): ?>
    <section class="dash-panel dash-backup">
      <h2 class="dash-label">Backups</h2>
      <?php if ($backup['warnings'] === []): ?>
        <div class="dash-status is-open"><span class="dot"></span>Last backup <?= e(date('d/m H:i', strtotime($backup['last_at']))) ?> · USB copy OK</div>
      <?php else: ?>
        <?php foreach ($backup['warnings'] as $w): ?><div class="dash-status is-warn"><span class="dot"></span><?= e($w) ?></div><?php endforeach; ?>
      <?php endif; ?>
      <div class="dash-actions"><a class="btn btn-outline-primary" href="<?= url('backup') ?>"><i class="bi bi-shield-check"></i> Backups</a></div>
    </section>
  <?php endif; ?>

  <section class="dash-panel dash-device">
    <h2 class="dash-label">This device</h2>
    <?php if ($register !== null): ?>
      <div class="dash-name" dir="auto"><?= e($register['name']) ?></div>
      <?php $busy = $session === null && $registerOpen !== null ? $registerOpen : null;   // somebody else's shift is open on this register ?>
      <?php if ($busy !== null): ?>
        <div class="dash-status is-warn"><span class="dot"></span>In use by <span dir="auto"><?= e($busy['full_name']) ?></span>: session <?= e($busy['session_no']) ?> is open</div>
      <?php elseif ($session === null): ?>
        <div class="dash-status"><span class="dot"></span>No open session</div>
      <?php else: ?>
        <div class="dash-status is-open"><span class="dot"></span>Session <?= e($session['session_no']) ?> open</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="dash-name">Not a POS register</div>
      <div class="dash-status is-warn"><span class="dot"></span>An administrator links it on the Registers page.</div>
    <?php endif; ?>
    <div class="dash-actions">
      <?php if ($canSell && ($busy ?? null) !== null): ?>
        <?php if (Gate::allows('session.view_all')): ?>
          <a class="btn btn-primary btn-lg" href="<?= url('sessions/view', ['id' => $busy['id']]) ?>"><i class="bi bi-cash-stack"></i> Count and close <?= e($busy['session_no']) ?></a>
        <?php else: ?>
          <p class="dash-wait">Ask the administrator to count and close <?= e($busy['session_no']) ?> before you can start your shift.</p>
        <?php endif; ?>
      <?php elseif ($canSell): ?>
        <?php if ($session === null): ?>
          <a class="btn btn-primary btn-lg" href="<?= url('sessions/open') ?>"><i class="bi bi-unlock"></i> Open a cash session</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg" href="<?= url('pos') ?>"><i class="bi bi-cart3"></i> Open the till</a>
          <a class="btn btn-outline-primary btn-lg" href="<?= url('sessions/view', ['id' => $session['id']]) ?>">Session <?= e($session['session_no']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <p class="dash-me">Signed in as <strong dir="auto"><?= e($user['full_name']) ?></strong> (<?= e($user['role_name']) ?>)</p>
  </section>

  <section class="dash-panel dash-rate">
    <h2 class="dash-label">Exchange rate</h2>
    <span class="rate-figure">1 USD = <?= e(number_format($rate)) ?> LBP</span>
    <span class="rate-note">Snapshotted on every sale</span>
    <?php if (Gate::allows('rate.manage')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('rates') ?>">Change rate</a><?php endif; ?>
  </section>
</div>
