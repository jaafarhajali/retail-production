<?php
use App\Core\Gate;

$canSell = Gate::allows('pos.use');
$d = $dash;   // null for a user without report.sales
$canSetFixed = Gate::allows('settings.manage');
?>
<div class="dash">
  <?php if ($backup !== null && $backup['warnings'] !== []): ?>
    <section class="dash-panel dash-backup">
      <?php foreach ($backup['warnings'] as $w): ?><div class="dash-status is-warn"><span class="dot"></span><?= e($w) ?></div><?php endforeach; ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= url('backup') ?>"><i class="bi bi-shield-check"></i> Backups</a>
    </section>
  <?php endif; ?>

  <?php if ($d !== null): ?>
    <?php $t = $d['targets']; $m = $d['money']; $fx = $d['fixed']; ?>
    <section class="dash-panel dash-targets" aria-label="Break-even by product">
      <div class="dash-head"><h2 class="dash-label">Break-even in <?= e($d['month_label']) ?>: what to sell of each product</h2>
        <?php if ($t['ready']): ?><span class="dash-more"><?= number_format($t['need']) ?> pieces cover the fixed costs</span><?php endif; ?></div>
      <?php if ($t['rows'] === []): ?>
        <p class="dash-empty">No finished product sold by the piece yet. Add the products first.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="dash-table bep-table">
            <thead><tr><th></th><?php foreach ($t['rows'] as $r): ?><th class="r" dir="auto"><?= e($r['name']) ?></th><?php endforeach; ?><th class="r all">All products</th></tr></thead>
            <tbody>
              <tr><td class="lead">Need to sell</td>
                <?php foreach ($t['rows'] as $r): ?><td class="r"><?= $r['need'] === null ? ($t['ready'] ? '<span class="text-muted" title="This product sells under its cost">—</span>' : '<span class="text-muted">?</span>') : number_format($r['need']) ?></td><?php endforeach; ?>
                <td class="r all"><?= $t['ready'] ? number_format($t['need']) : '<span class="text-muted">?</span>' ?></td></tr>
              <tr><td class="lead">Sold</td>
                <?php foreach ($t['rows'] as $r): ?><td class="r"><?= number_format($r['sold']) ?></td><?php endforeach; ?>
                <td class="r all"><?= number_format($t['sold']) ?></td></tr>
              <tr><td class="lead">Left</td>
                <?php foreach ($t['rows'] as $r): ?><td class="r <?= $r['left'] === null ? '' : ($r['left'] === 0 ? 'is-done' : 'is-left') ?>"><?= $r['left'] === null ? '<span class="text-muted">—</span>' : ($r['left'] === 0 ? 'done ✓' : number_format($r['left'])) ?></td><?php endforeach; ?>
                <td class="r all <?= $t['ready'] ? ($t['left'] === 0 ? 'is-done' : 'is-left') : '' ?>"><?= $t['ready'] ? ($t['left'] === 0 ? 'done ✓' : number_format($t['left'])) : '<span class="text-muted">—</span>' ?></td></tr>
            </tbody>
          </table>
        </div>
        <?php if (!$t['ready']): ?>
          <p class="dash-fine"><?= $fx['amount'] === null ? 'Type the monthly fixed costs below: then this table says how many pieces of each product cover them.' : 'The products earn nothing yet (price minus cost is zero): the targets appear once a piece sells above its cost.' ?></p>
        <?php else: ?>
          <p class="dash-fine">Each product's target is its share of <?= $t['share_from'] === 'last' ? 'last month\'s' : ($t['share_from'] === 'this' ? 'this month\'s' : 'the') ?> pieces sold<?= $t['share_from'] === 'equal' ? ', equal shares before any sale' : '' ?>, weighed by what one piece earns (<?= usd($t['per_piece']) ?> on average). Returns are taken off.</p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="dash-panel dash-fixed">
      <h2 class="dash-label">Monthly fixed costs · <?= e($d['month_label']) ?></h2>
      <div class="fixed-figure"><?= $fx['amount'] === null ? '<span class="text-muted">not set</span>' : usd($fx['amount']) ?></div>
      <?php if ($fx['from_month'] !== null): ?><p class="dash-fine mt-0">Carried over from <?= e($fx['from_month']) ?>. Change it if the bills moved.</p><?php endif; ?>
      <?php if ($canSetFixed): ?>
        <form method="post" action="<?= url('dashboard/fixed-costs') ?>" class="fixed-form">
          <?= csrf_field() ?>
          <input type="hidden" name="month" value="<?= e($d['month']) ?>">
          <div class="input-group"><span class="input-group-text">$</span>
            <input class="form-control" type="text" inputmode="decimal" name="amount" id="fixed-amount" aria-label="Monthly fixed costs in dollars" placeholder="1500" value="<?= e(old('amount', $fx['amount'] === null ? '' : rtrim(rtrim($fx['amount'], '0'), '.'))) ?>">
            <button class="btn btn-primary" type="submit">Save</button></div>
        </form>
      <?php endif; ?>
      <p class="dash-fine">Rent, salaries, electricity: what goes out every month whatever you sell. Raw materials and packaging are not here; they are in the cost of each piece.</p>
    </section>

    <section class="dash-panel dash-money">
      <h2 class="dash-label">The month's money · <?= e($d['month_label']) ?></h2>
      <table class="dash-table money-table">
        <tbody>
          <tr><td>Sales</td><td class="r"><?= usd($m['sales']) ?></td></tr>
          <tr><td>Variable costs <small class="text-muted">mix and packaging of what was sold</small></td><td class="r">− <?= usd($m['variable']) ?></td></tr>
          <tr class="sub"><td>Margin</td><td class="r"><?= usd($m['margin']) ?></td></tr>
          <tr><td>Fixed costs</td><td class="r">− <?= usd($m['fixed']) ?></td></tr>
          <tr class="total"><td>Net profit</td><td class="r <?= (float) $m['profit'] >= 0 ? 'is-good' : 'is-bad' ?>"><?= (float) $m['profit'] < 0 ? '− ' : '' ?><?= usd(abs((float) $m['profit'])) ?></td></tr>
        </tbody>
      </table>
    </section>
  <?php else: ?>
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
  <?php endif; ?>
</div>
