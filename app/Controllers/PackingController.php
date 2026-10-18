<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Models\Packing;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Services\PackingService;

/** Packing (2026-10-14): the mix becomes finished products, the split typed each time; the list lives on the Production page. */
final class PackingController extends Controller
{
    public function create(): void
    {
        $products = new Product();
        $mixes = $products->forPrint('intermediate');
        $materials = $products->forPrint('material');
        $finished = array_values(array_filter($products->forPrint('finished'), static fn (array $p): bool => (int) $p['track_stock'] === 1));
        $ids = array_map('intval', array_merge(array_column($mixes, 'id'), array_column($materials, 'id'), array_column($finished, 'id')));
        $this->render('packing/new', [
            'mixes' => $mixes, 'materials' => $materials, 'finished' => $finished,
            'unitsById' => (new ProductUnit())->forProducts($ids), 'showCost' => Gate::allows('product.view_cost'),
        ], 'Pack');
    }

    /** The preview for the Pack screen, as JSON. */
    public function preview(): void
    {
        $in = $this->jsonInput();
        try {
            $p = (new PackingService())->preview(is_array($in['used'] ?? null) ? array_values($in['used']) : [], is_array($in['made'] ?? null) ? array_values($in['made']) : []);
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $showCost = Gate::allows('product.view_cost');
        $this->json([
            'short' => $p['short'], 'by_value' => $p['by_value'], 'cost' => $showCost ? $p['cost'] : null,
            'held' => $p['held'], 'held_text' => $p['held_text'], 'unknown' => $p['unknown'], 'mismatch' => $p['mismatch'], 'over' => $p['over'],   // what the products hold (2026-10-18)
            'used' => array_map(static fn (array $l): array => ['name' => $l['name'], 'qty' => $l['qty_text'], 'stock' => $l['stock_text'], 'short' => $l['short'], 'auto' => $l['auto'], 'for' => $l['for'], 'cost' => $showCost ? $l['cost_usd'] : null], $p['used']),
            'made' => array_map(static fn (array $l): array => ['name' => $l['name'], 'qty' => $l['qty_text'], 'share' => $l['share_pct'], 'cost' => $showCost ? $l['cost_usd'] : null, 'per_unit' => $showCost ? $l['cost_per_unit'] : null, 'unit' => $l['unit_name']], $p['made']),
        ]);
    }

    public function store(): void
    {
        $used = is_array($_POST['used'] ?? null) ? array_values($_POST['used']) : [];
        $made = is_array($_POST['made'] ?? null) ? array_values($_POST['made']) : [];
        try {
            $r = (new PackingService())->pack($used, $made, $this->input('notes'), Auth::id(), $this->input('confirm_gap') === '1');
        } catch (\DomainException $e) {
            $this->failBack('packing/new', [], ['form' => $e->getMessage()]);
        }
        Flash::set('success', $r['pack_no'] . ': ' . $r['made_text'] . ' made.');
        redirect('packing/view', ['id' => $r['id']]);
    }

    public function view(): void
    {
        $packings = new Packing();
        $k = $packings->find($this->queryInt('id')) ?? throw new HttpException(404);
        $lines = $packings->lines((int) $k['id']);
        $this->render('packing/view', [
            'packing' => $k, 'used' => array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'used')),
            'made' => array_values(array_filter($lines, static fn (array $l): bool => $l['side'] === 'made')), 'showCost' => Gate::allows('product.view_cost'),
        ], $k['pack_no']);
    }
}
