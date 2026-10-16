<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Flash;
use App\Core\Gate;
use App\Core\HttpException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Recipe;
use App\Services\ProductionService;
use App\Services\RecipeService;

/**
 * Recipes (F2): one page lists them by product, one form writes a recipe and its lines.
 * Since 2026-10-15 the screens call them "Flavours": each recipe is one flavour of the mix. With a single mix the
 * mix is never asked for; a second mix brings the choice back.
 */
final class RecipeController extends Controller
{
    public function index(): void
    {
        $model = new Recipe();
        $recipes = $model->all();
        $svc = new RecipeService();
        $showCost = Gate::allows('product.view_cost');
        foreach ($recipes as &$r) {
            $r['estimate'] = $showCost ? $svc->estimate((int) $r['id']) : null;
            // what goes into one batch, in a line: "450 g Sugar, 30 g Gelatin"
            $r['summary'] = implode(', ', array_map(static fn (array $l): string => ProductionService::baseText((int) $l['qty_base'], $l['base_unit']) . ' ' . $l['material_name'], $model->lines((int) $r['id'])));
        }
        unset($r);
        $mixes = (new Product())->forPrint('intermediate');
        $this->render('recipes/index', ['recipes' => $recipes, 'mixes' => $mixes, 'showCost' => $showCost, 'canManage' => Gate::allows('production.manage')], 'Flavours');
    }

    public function edit(): void
    {
        $id = $this->queryInt('id');
        $recipes = new Recipe();
        $recipe = $id > 0 ? ($recipes->find($id) ?? throw new HttpException(404)) : null;
        $products = new Product();
        $made = $products->forPrint('intermediate');   // a recipe makes a mix; products are packed from it
        $materials = array_merge($products->forPrint('material'), $products->forPrint('intermediate'));   // what it can use
        $ids = array_map('intval', array_merge(array_column($made, 'id'), array_column($materials, 'id')));
        $this->render('recipes/edit', [
            'recipe' => $recipe, 'lines' => $recipe === null ? [] : $recipes->lines($id), 'made' => $made, 'materials' => $materials,
            'unitsById' => (new ProductUnit())->forProducts(array_values(array_unique($ids))),
            'productId' => $recipe === null ? $this->queryInt('product_id') : (int) $recipe['product_id'],
        ], $recipe === null ? 'Add a flavour' : (count($made) === 1 ? $recipe['name'] : $recipe['product_name'] . ' · ' . $recipe['name']));
    }

    public function save(): void
    {
        $id = $this->inputInt('id');
        $in = [
            'product_id' => $this->input('product_id'), 'name' => $this->input('name'), 'output_qty' => $this->input('output_qty'), 'output_unit_id' => $this->input('output_unit_id'),
            'notes' => $this->input('notes'), 'is_default' => isset($_POST['is_default']), 'is_default_given' => true,
            'lines' => is_array($_POST['lines'] ?? null) ? array_values($_POST['lines']) : [],
        ];
        try {
            $id = (new RecipeService())->save($id, $in, Auth::id());
        } catch (\DomainException $e) {
            $this->failBack('recipes/edit', $id > 0 ? ['id' => $id] : ['product_id' => (int) $in['product_id']], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Flavour saved.');
        redirect('recipes');
    }

    public function delete(): void
    {
        $id = $this->inputInt('id');
        try {
            (new RecipeService())->delete($id);
        } catch (\DomainException $e) {
            $this->failBack('recipes/edit', ['id' => $id], ['form' => $e->getMessage()]);
        }
        Flash::set('success', 'Flavour removed.');
        redirect('recipes');
    }
}
