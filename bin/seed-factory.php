<?php
/**
 * php bin/seed-factory.php [--yes] [--force]
 *
 * Seeds the factory edition (Malika Marshmallow, 2026-10-12): the company settings, the categories, the nine finished
 * products with the owner's wholesale / retail price list and barcodes, the "Customized" product, the raw materials and
 * packaging with purchase units and costs, and one "Marshmallow mix" (its recipe comes with Phase F2).
 * Everything goes through the normal services, so stock movements and the audit log are what real use would produce.
 *
 *   --yes    skip the confirmation prompt
 *   --force  seed even when products already exist (adds what is missing by name)
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Services\ProductService;
use App\Services\StockService;

$opts = getopt('', ['yes', 'force']);
$out = static fn (string $line) => print($line . "\n");

if (APP_ENV === 'production') {
    fwrite(STDERR, "Refusing to seed into a production installation (app.env = production in config/app.ini).\n");
    exit(1);
}
$existing = (int) Database::pdo()->query('SELECT COUNT(*) FROM products')->fetchColumn();
if ($existing > 0 && !isset($opts['force'])) {
    fwrite(STDERR, "Database " . DB_NAME . " already has {$existing} products. Run with --force to add what is missing.\n");
    exit(1);
}
if (!isset($opts['yes'])) {
    echo 'Seed the Malika factory catalogue into database "' . DB_NAME . '"? [y/N] ';
    if (strtolower(trim((string) fgets(STDIN))) !== 'y') {
        $out('Cancelled.');
        exit(0);
    }
}

$_SESSION = [];
Auth::login(1);
$admin = 1;

// ---------------------------------------------------------------- the company
$company = ['shop_name' => 'Malika For Trading', 'shop_address' => 'Lebanon / Arab Salim / main road', 'shop_phone' => '+961 70 316 708 · +961 76 564 645',
    'receipt_header' => 'Malika Marshmallow · Premium quality', 'receipt_footer' => 'Thank you for your purchase!',
    'company_email' => 'malikamarshmallow@gmail.com', 'company_mof' => '3900717', 'company_phone2' => '+961 76 564 645',
];
if (in_array(Settings::get('shop_name', ''), ['', 'Retail POS'], true)) {   // a shop that renamed itself keeps its name
    (new Setting())->setMany($company);
    Settings::flush();
    $out('Company: Malika For Trading (MOF 3900717)');
}

try {
    (new ExchangeRate())->current();
} catch (\RuntimeException) {
    (new ExchangeRate())->add(89500, $admin);
    $out('Exchange rate set: 1 USD = 89,500 LBP');
}

// ---------------------------------------------------------------- people and registers (for the till and the browser checks)
$users = new App\Models\User();
foreach ([['sara', 'Sara Haddad'], ['ali', 'Ali Khoury']] as [$username, $fullName]) {
    if ((int) Database::pdo()->query('SELECT COUNT(*) FROM users WHERE username = ' . Database::pdo()->quote($username))->fetchColumn() === 0) {
        $users->create($username, 'password123', $fullName, App\Models\Role::CASHIER_ID, false);
    }
}
$registers = new App\Models\Register();
foreach (['Register 01', 'Register 02'] as $name) {
    if (array_filter($registers->all(), static fn (array $r): bool => $r['name'] === $name) === []) {
        $registers->create($name);
    }
}
$out('Sellers: sara / ali (password123); registers: Register 01, Register 02');
$parties = new App\Services\PartyService();
foreach ([['شركة متاجر العاملية ش.م.ل', '07/225999', '2941978-601', 'صيدا - الغازية', 'wholesale'], ['Hawraa Issa', '76 357 563', '', 'الرملة البيضا، Picasso Building', 'retail']] as [$name, $phone, $tva, $address, $level]) {
    if ((int) Database::pdo()->query('SELECT COUNT(*) FROM customers WHERE name = ' . Database::pdo()->quote($name))->fetchColumn() === 0) {
        $parties->saveCustomer(0, ['name' => $name, 'phone' => $phone, 'tva_number' => $tva, 'address' => $address, 'notes' => '', 'default_price_level' => $level, 'credit_limit_usd' => '', 'is_active' => true]);
    }
}
$out('Customers: 2');

// ---------------------------------------------------------------- categories
$categories = new Category();
$cat = [];
foreach ([['Marshmallow', '#c084a8'], ['Ingredients', '#d8a200'], ['Packaging', '#3fa39a']] as $i => [$name, $color]) {
    $cat[$name] = $categories->nameExists($name)
        ? (int) Database::pdo()->query('SELECT id FROM categories WHERE name = ' . Database::pdo()->quote($name))->fetchColumn()
        : $categories->create($name, $color, $i + 1);
}

// ---------------------------------------------------------------- products
$products = new ProductService();
$stock = new StockService();
$exists = static fn (string $name): bool => (int) Database::pdo()->query('SELECT COUNT(*) FROM products WHERE name = ' . Database::pdo()->quote($name))->fetchColumn() > 0;

/** Finished products: [name, units => [type, size, fraction, retail, wholesale, barcode], cost per piece, opening pieces, min pieces, override] — prices from the owner's list of 2026-10-10. */
$finished = [
    ['Marshmallow Stick 25g',  [['Piece', 1, false, '0.80', '0.65', '850009611772'], ['Box', 100, false, '', '']], '0.30', '200', '50', false],
    ['Marshmallow Bags',       [['Piece', 1, false, '0.80', '0.65', ''], ['Box', 100, false, '', '']], '0.30', '100', '50', false],
    ['Joy 100g',               [['Piece', 1, false, '3.00', '2.50', '850009611789'], ['Box', 27, false, '', '']], '1.20', '60', '20', false],
    ['Joy 200g',               [['Piece', 1, false, '6.00', '5.00', ''], ['Box', 12, false, '', '']], '2.40', '30', '10', false],
    ['Mini Joy 100g',          [['Piece', 1, false, '4.00', '3.20', '850009611819'], ['Box', 22, false, '', '']], '1.30', '40', '20', false],
    ['Excellence 100g',        [['Piece', 1, false, '3.80', '3.00', ''], ['Box', 24, false, '', '']], '1.30', '40', '20', false],
    ['Pistachio 100g',         [['Piece', 1, false, '4.00', '3.25', ''], ['Box', 24, false, '', '']], '1.50', '40', '20', false],
    ['Choco Dream 100g',       [['Piece', 1, false, '4.00', '3.25', ''], ['Box', 24, false, '', '']], '1.50', '40', '20', false],
    ['Gold 100g',              [['Piece', 1, false, '4.00', '3.50', ''], ['Box', 24, false, '', '']], '1.60', '40', '20', false],
    ['Customized Box',         [['Piece', 1, false, '10.00', '8.00', '']], '', '0', '', true],
];
$made = 0;
foreach ($finished as [$name, $unitDefs, $cost, $opening, $min, $override]) {
    if ($exists($name)) {
        continue;
    }
    $id = $products->create(['name' => $name, 'category_id' => (string) $cat['Marshmallow'], 'base_unit' => 'piece', 'internal_code' => '', 'description' => $override ? 'Weight, flavours and price typed at the sale' : '',
        'target_margin_pct' => '', 'show_on_pos_grid' => true, 'allow_price_override' => $override, 'kind' => 'finished', 'track_stock' => !$override]);
    $unitIds = [];
    $piece = $unitDefs[0];
    foreach ($unitDefs as $u) {
        [$type, $size, $fraction, $retail, $wholesale] = $u;
        $unitId = $products->addUnit($id, $type, (string) $size, $fraction, false);
        if ($retail === '' && $size > 1 && $piece[3] !== '') {   // a box is sold at the piece price × its pieces (the supermarkets buy boxes)
            $retail = number_format((float) $piece[3] * $size, 2, '.', '');
            $wholesale = $piece[4] === '' ? '' : number_format((float) $piece[4] * $size, 2, '.', '');
        }
        $products->setPrices($unitId, $retail, $wholesale);
        if (($u[5] ?? '') !== '') {
            $products->addBarcode($unitId, $u[5]);
        }
        $unitIds[] = $unitId;
    }
    if ($cost !== '') {
        $products->setCost($id, $cost, 1);
    }
    if ((int) $opening > 0) {
        $stock->adjust($id, $unitIds[0], $opening, 'opening', 'Seed opening stock', null, $admin);
    }
    if ($min !== '') {
        $products->setMinStock($id, $min, $unitIds[0]);
    }
    $made++;
}
$out("Finished products: {$made} (Marshmallow Stick, Joy, Mini Joy, Excellence, Pistachio, Choco Dream, Gold, Customized Box)");

/** Raw materials and packaging: [name, category, base, units => [type, size, fraction], cost of the first unit, opening (qty of first unit), min (qty of first unit)]. */
$materials = [
    ['Honey', 'Ingredients', 'g', [['Bucket', 5000, false], ['kg', 1000, true]], '45.00', '4', '1'],
    ['Sugar', 'Ingredients', 'g', [['Bag', 25000, false], ['kg', 1000, true]], '22.00', '2', '1'],
    ['Glucose syrup', 'Ingredients', 'g', [['Bucket', 5000, false], ['kg', 1000, true]], '12.00', '3', '1'],
    ['Gelatin', 'Ingredients', 'g', [['Bag', 1000, false], ['kg', 1000, true]], '18.00', '5', '2'],
    ['Food colouring', 'Ingredients', 'g', [['Bottle', 100, false], ['kg', 1000, true]], '3.00', '10', '3'],
    ['Raspberry flavour', 'Ingredients', 'ml', [['Bottle', 250, false], ['L', 1000, true]], '6.00', '6', '2'],
    ['Pistachio paste', 'Ingredients', 'g', [['Jar', 1000, false], ['kg', 1000, true]], '28.00', '3', '1'],
    ['Cocoa powder', 'Ingredients', 'g', [['Bag', 1000, false], ['kg', 1000, true]], '9.00', '4', '1'],
    ['Box 100g', 'Packaging', 'piece', [['Pack', 100, false], ['Piece', 1, false]], '12.00', '10', '3'],
    ['Box 200g', 'Packaging', 'piece', [['Pack', 100, false], ['Piece', 1, false]], '16.00', '5', '2'],
    ['Stick bag', 'Packaging', 'piece', [['Pack', 1000, false], ['Piece', 1, false]], '15.00', '3', '1'],
    ['Label', 'Packaging', 'piece', [['Roll', 500, false], ['Piece', 1, false]], '8.00', '4', '1'],
];
$made = 0;
foreach ($materials as [$name, $category, $base, $unitDefs, $cost, $opening, $min]) {
    if ($exists($name)) {
        continue;
    }
    $id = $products->create(['name' => $name, 'category_id' => (string) $cat[$category], 'base_unit' => $base, 'internal_code' => '', 'description' => '',
        'target_margin_pct' => '', 'show_on_pos_grid' => false, 'allow_price_override' => false, 'kind' => 'material']);
    $unitIds = [];
    foreach ($unitDefs as [$type, $size, $fraction]) {
        $unitIds[] = $products->addUnit($id, $type, (string) $size, $fraction, false);
    }
    $products->setCost($id, $cost, (int) $unitDefs[0][1]);
    $stock->adjust($id, $unitIds[0], $opening, 'opening', 'Seed opening stock', null, $admin);
    $products->setMinStock($id, $min, $unitIds[0]);
    $made++;
}
$out("Raw materials and packaging: {$made}");

if (!$exists('Marshmallow mix')) {
    $id = $products->create(['name' => 'Marshmallow mix', 'category_id' => (string) $cat['Marshmallow'], 'base_unit' => 'g', 'internal_code' => '', 'description' => 'Made by production from the ingredients; used by every finished product',
        'target_margin_pct' => '', 'show_on_pos_grid' => false, 'allow_price_override' => false, 'kind' => 'intermediate']);
    $products->addUnit($id, 'kg', '1000', true, false);
    $out('Mix: Marshmallow mix (recipe in Phase F2)');
}

// ---------------------------------------------------------------- recipes (F2): the mix from ingredients, the products from the mix + packaging
$recipes = new App\Services\RecipeService();
$pid = static fn (string $name): int => (int) Database::pdo()->query('SELECT id FROM products WHERE name = ' . Database::pdo()->quote($name))->fetchColumn();
$unitOf = static function (int $productId, string $type): int {
    foreach ((new App\Models\ProductUnit())->forProduct($productId) as $u) {
        if (stripos($u['name'], $type) === 0) {
            return (int) $u['id'];
        }
    }
    throw new RuntimeException("no unit {$type} on product {$productId}");
};
$hasRecipe = static fn (int $productId): bool => (int) Database::pdo()->query("SELECT COUNT(*) FROM recipes WHERE product_id = {$productId}")->fetchColumn() > 0;
$line = static fn (string $name, string $qty, string $type): array => ['material_id' => $pid($name), 'qty' => $qty, 'unit_id' => $unitOf($pid($name), $type)];
$made = 0;
$mixId = $pid('Marshmallow mix');
if ($mixId > 0 && !$hasRecipe($mixId)) {
    $recipes->save(0, ['product_id' => $mixId, 'name' => 'Standard', 'output_qty' => '1', 'output_unit_id' => $unitOf($mixId, 'kg'), 'notes' => 'One kilo of plain mix; flavour and colour are added per product',
        'lines' => [$line('Sugar', '0.45', 'kg'), $line('Glucose syrup', '0.25', 'kg'), $line('Honey', '0.2', 'kg'), $line('Gelatin', '0.03', 'kg'), $line('Food colouring', '0.005', 'kg'), $line('Raspberry flavour', '0.015', 'L')]], $admin);
    $made++;
}
foreach ([['Joy 100g', '10', '1', 'Box 100g', '10'], ['Joy 200g', '10', '2', 'Box 200g', '10'], ['Mini Joy 100g', '10', '1', 'Box 100g', '10'], ['Excellence 100g', '10', '1', 'Box 100g', '10'],
          ['Pistachio 100g', '10', '1', 'Box 100g', '10'], ['Choco Dream 100g', '10', '1', 'Box 100g', '10'], ['Gold 100g', '10', '1', 'Box 100g', '10'], ['Marshmallow Stick 25g', '40', '1', 'Stick bag', '40']] as [$name, $pieces, $mixKg, $box, $boxes]) {
    $id = $pid($name);
    if ($id <= 0 || $mixId <= 0 || $hasRecipe($id)) {
        continue;
    }
    $lines = [['material_id' => $mixId, 'qty' => $mixKg, 'unit_id' => $unitOf($mixId, 'kg')], $line($box, $boxes, 'Piece')];
    if ($name !== 'Marshmallow Stick 25g') {
        $lines[] = $line('Label', $pieces, 'Piece');
    }
    if ($name === 'Pistachio 100g') {
        $lines[] = $line('Pistachio paste', '0.1', 'kg');
    }
    if ($name === 'Choco Dream 100g') {
        $lines[] = $line('Cocoa powder', '0.05', 'kg');
    }
    $recipes->save(0, ['product_id' => $id, 'name' => 'Standard', 'output_qty' => $pieces, 'output_unit_id' => $unitOf($id, 'Piece'), 'notes' => '', 'lines' => $lines], $admin);
    $made++;
}
$out("Recipes: {$made} (the mix, and one per product from the mix and its packaging)");

$out('Done. Sign in as admin.');
