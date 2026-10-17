<?php
// Test helper: sets the allowed cashier discount on the throw-away test database (MAXPCT env, default 0).
require dirname(__DIR__, 2) . '/app/bootstrap.php';
if (APP_ENV !== 'test') { fwrite(STDERR, "not the test database\n"); exit(1); }
$pct = getenv('MAXPCT') ?: '0';
(new App\Models\Setting())->setMany(['max_cashier_discount_pct' => $pct, 'till_delivery_invoices' => '1']);   // the held-invoice scenario needs the till's delivery flow on (2026-10-17)
echo "allowed cashier discount: {$pct} %\n";
