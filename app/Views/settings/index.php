<?php
$field = static fn (string $key): string => old($key, $values[$key] ?? '');
$stashedStep = $_SESSION['_old']['lbp_rounding_step'] ?? null;   // may be a tampered array
$step = is_string($stashedStep) ? $stashedStep : (string) ($values['lbp_rounding_step'] ?? '5000');
?>
<form method="post" action="<?= url('settings/save') ?>" class="card" style="max-width: 820px">
  <div class="card-body">
    <?= csrf_field() ?>
    <h2 class="h5">Company</h2>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label" for="shop_name">Company name</label>
        <input class="form-control" id="shop_name" name="shop_name" dir="auto" required maxlength="100" value="<?= $field('shop_name') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="shop_phone">Phone</label>
        <input class="form-control" id="shop_phone" name="shop_phone" maxlength="60" placeholder="one or two numbers" value="<?= $field('shop_phone') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="shop_address">Address</label>
        <input class="form-control" id="shop_address" name="shop_address" dir="auto" maxlength="255" value="<?= $field('shop_address') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="company_mof">MOF number</label>
        <input class="form-control" id="company_mof" name="company_mof" maxlength="30" value="<?= $field('company_mof') ?>">
        <div class="form-text">Printed on the regular and the official invoice.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="company_email">Email</label>
        <input class="form-control" id="company_email" name="company_email" maxlength="120" value="<?= $field('company_email') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="invoice_terms_days">Days to pay</label>
        <input class="form-control" id="invoice_terms_days" name="invoice_terms_days" inputmode="numeric" maxlength="3" placeholder="45" value="<?= $field('invoice_terms_days') ?>">
        <div class="form-text">How many days a customer has to pay an invoice, unless their own card says otherwise.</div>
      </div>
    </div>

    <h2 class="h5">Receipt</h2>
    <div class="row g-3 mb-4">
      <div class="col-12">
        <label class="form-label" for="receipt_header">Text above the items</label>
        <input class="form-control" id="receipt_header" name="receipt_header" dir="auto" maxlength="255" value="<?= $field('receipt_header') ?>">
      </div>
      <div class="col-12">
        <label class="form-label" for="receipt_footer">Text at the bottom</label>
        <input class="form-control" id="receipt_footer" name="receipt_footer" dir="auto" maxlength="255" value="<?= $field('receipt_footer') ?>">
      </div>
    </div>

    <h2 class="h5">Fixed costs</h2>
    <?php $fixedNow = array_filter(array_map('trim', explode(',', \App\Core\Settings::get('fixed_expense_categories', '')))); $cats = array_filter((new \App\Models\Expense())->categories(), static fn (string $c): bool => $c !== \App\Services\ExpenseService::SUPPLIER_PAYMENT); ?>
    <div class="mb-4">
      <div class="form-text mb-2">Tick the expense categories that come every month whatever you sell (rent, salaries, electricity). The dashboard divides their total by the margin per piece to say how many pieces cover the month.</div>
      <?php if ($cats === []): ?><p class="text-muted mb-0">No expense categories yet. They appear here once an expense is recorded.</p><?php endif; ?>
      <div class="d-flex flex-wrap gap-3">
        <?php foreach ($cats as $c): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="fixed-<?= md5($c) ?>" name="fixed_cats[]" value="<?= e($c) ?>" <?= in_array($c, $fixedNow, true) ? 'checked' : '' ?>><label class="form-check-label" for="fixed-<?= md5($c) ?>" dir="auto"><?= e($c) ?></label></div><?php endforeach; ?>
      </div>
    </div>

    <h2 class="h5">Cash and POS</h2>
    <div class="row g-3 mb-4">
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="till_delivery_invoices" name="till_delivery_invoices" value="1" <?= \App\Core\Settings::get('till_delivery_invoices', '') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="till_delivery_invoices">Delivery invoices on the till<small class="d-block text-muted">Off: deliveries are written on the Invoices page only. On: the till's Hold dialog also offers "Hold as invoice and print" and "Finish" for the owner who delivers from the till.</small></label></div></div>
      <div class="col-md-6">
        <label class="form-label" for="lbp_rounding_step">LBP rounding step</label>
        <select class="form-select" id="lbp_rounding_step" name="lbp_rounding_step">
          <?php foreach (\App\Services\SettingService::ROUNDING_STEPS as $option): ?>
            <option value="<?= $option ?>" <?= (string) $option === $step ? 'selected' : '' ?>><?= e(number_format($option)) ?> LBP</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">LBP change and amounts due are rounded to this.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="max_cashier_discount_pct">Max cashier discount without Admin PIN (%)</label>
        <input class="form-control" id="max_cashier_discount_pct" name="max_cashier_discount_pct" type="number" min="0" max="100" step="1"
               value="<?= $field('max_cashier_discount_pct') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label" for="usd_denominations">USD notes</label>
        <input class="form-control" id="usd_denominations" name="usd_denominations" value="<?= $field('usd_denominations') ?>">
        <div class="form-text">Comma-separated. Used for the cash count when closing a session.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label" for="lbp_denominations">LBP notes</label>
        <input class="form-control" id="lbp_denominations" name="lbp_denominations" value="<?= $field('lbp_denominations') ?>">
      </div>
    </div>

    <button class="btn btn-primary" type="submit">Save settings</button>
  </div>
</form>

<?php $logo = logo_url(); ?>
<div class="card mt-3" style="max-width: 820px"><div class="card-body">
  <h2 class="h5">Logo</h2>
  <p class="text-muted small mb-3">Shown on the menu, the sign-in page and at the top of every printout: receipts, X/Z reports and printed reports. Best is an <strong>SVG</strong> (vector: sharp at any size on the receipt printer) with a dark drawing on a transparent background; PNG or JPG also work.</p>
  <div class="d-flex gap-4 align-items-start flex-wrap">
    <div class="logo-preview"><?php if ($logo !== null): ?><img src="<?= e($logo) ?>" alt="Company logo"><?php else: ?><span class="text-muted">No logo yet</span><?php endif; ?></div>
    <div class="flex-fill" style="min-width: 260px">
      <form method="post" action="<?= url('settings/logo') ?>" enctype="multipart/form-data" class="mb-2">
        <?= csrf_field() ?>
        <input class="form-control mb-2" type="file" name="logo" accept=".svg,image/svg+xml,image/png,image/jpeg,image/webp,image/gif" required>
        <button class="btn btn-outline-primary" type="submit">Upload logo</button>
        <div class="form-text">SVG (recommended), PNG, JPG or WEBP up to 5 MB. Raster images are resized to fit 600 × 300 px.</div>
      </form>
      <?php if ($logo !== null): ?>
        <form method="post" action="<?= url('settings/logo-delete') ?>" data-confirm="Remove the logo?">
          <?= csrf_field() ?>
          <button class="btn btn-sm btn-outline-danger" type="submit">Remove logo</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div></div>
