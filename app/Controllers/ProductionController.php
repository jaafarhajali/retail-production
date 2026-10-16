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
use App\Models\ProductionRun;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Services\ProductionService;

/** Production runs (F2): the list, the "Make" screen with its live preview, and the detail of a run. */
final class ProductionController extends Controller
{
    public function index(): void
    {
        $this->render('production/index', ['runs' => (new ProductionRun())->recent(100), 'packings' => (new Packing())->recent(100), 'showCost' => Gate::allows('product.view_cost'), 'canManage' => Gate::allows('production.manage')], 'Production');
    }

    public function create(): void
    {
        $recipes = (new Recipe())->all();
        $byProduct = [];
        foreach ($recipes as $r) {
            $byProduct[(int) $r['product_id']]['name'] = $r['product_name'];
            $byProduct[(int) $r['product_id']]['kind'] = $r['product_kind'];
            $byProduct[(int) $r['product_id']]['recipes'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'default' => (int) $r['is_default'] === 1];
        }
        $this->render('production/new', [
            'byProduct' => $byProduct, 'unitsById' => (new ProductUnit())->forProducts(array_keys($byProduct)),
            'mixes' => (new Product())->forPrint('intermediate'),
            'recipeId' => $this->queryInt('recipe_id'), 'showCost' => Gate::allows('product.view_cost'),
        ], 'Make');
    }

    /** The preview for the Make screen: materials, shortages and cost, as JSON. */
    public function preview(): void
    {
        $in = $this->jsonInput();
        try {
            $p = (new ProductionService())->preview((int) ($in['recipe_id'] ?? 0), (string) ($in['qty'] ?? ''), (int) ($in['unit_id'] ?? 0));
        } catch (\DomainException $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        $showCost = Gate::allows('product.view_cost');
        $this->json([
            'qty_text' => $p['qty_text'], 'batches' => $p['batches'], 'short' => $p['short'],
            'lines' => array_map(static fn (array $l): array => ['name' => $l['material_name'], 'need' => $l['need_text'], 'stock' => $l['stock_text'], 'short' => $l['short'], 'cost' => $showCost ? $l['cost_usd'] : null], $p['lines']),
            'material_cost' => $showCost ? $p['material_cost'] : null, 'cost_per_unit' => $showCost ? $p['cost_per_unit'] : null, 'unit_name' => $p['unit_name'],
        ]);
    }

    public function store(): void
    {
        try {
            $r = (new ProductionService())->run($this->inputInt('recipe_id'), $this->input('qty'), $this->inputInt('unit_id'), $this->input('notes'), Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('production/new', ['recipe_id' => $this->inputInt('recipe_id')], ['form' => $e->getMessage()]);
        }
        Flash::set('success', $r['run_no'] . ': ' . $r['qty_text'] . ' made.');
        redirect('production/view', ['id' => $r['id']]);
    }

    public function view(): void
    {
        $runs = new ProductionRun();
        $run = $runs->find($this->queryInt('id')) ?? throw new HttpException(404);
        $this->render('production/view', ['run' => $run, 'lines' => $runs->lines((int) $run['id']), 'showCost' => Gate::allows('product.view_cost')], $run['run_no']);
    }
}
