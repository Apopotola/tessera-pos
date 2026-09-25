<?php

namespace Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\BrandRequest;
use Modules\Catalogue\Http\Requests\CategoryRequest;
use Modules\Catalogue\Http\Resources\BrandResource;
use Modules\Catalogue\Http\Resources\CategoryResource;
use Modules\Catalogue\Http\Resources\TaxRateResource;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\TaxRate;
use Modules\Catalogue\Services\CatalogueService;
use OpenApi\Attributes as OA;

/** Brands, categories and tax rates — the reference data products hang off. */
class TaxonomyController extends Controller
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    #[OA\Get(path: '/api/v1/catalogue/tax-rates', summary: 'Active eTIMS tax rates', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Tax rates')])]
    public function taxRates(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        return $this->success('Tax rates retrieved.', TaxRateResource::collection(TaxRate::query()->where('is_active', true)->orderBy('code')->get()));
    }

    #[OA\Get(path: '/api/v1/catalogue/brands', summary: 'All brands (A–Z)', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Brands')])]
    public function brands(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        return $this->success('Brands retrieved.', BrandResource::collection(Brand::query()->orderBy('name')->get()));
    }

    #[OA\Post(path: '/api/v1/catalogue/brands', summary: 'Create a brand', tags: ['Catalogue'], responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeBrand(BrandRequest $request): JsonResponse
    {
        return $this->success('Brand created.', new BrandResource($this->catalogue->createBrand($request->validated())), 201);
    }

    #[OA\Put(path: '/api/v1/catalogue/brands/{brand}', summary: 'Update a brand', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function updateBrand(BrandRequest $request, Brand $brand): JsonResponse
    {
        return $this->success('Brand updated.', new BrandResource($this->catalogue->updateBrand($brand, $request->validated())));
    }

    #[OA\Get(path: '/api/v1/catalogue/categories', summary: 'Category tree (two levels)', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Categories with children')])]
    public function categories(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        $tree = Category::query()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success('Categories retrieved.', CategoryResource::collection($tree));
    }

    #[OA\Post(path: '/api/v1/catalogue/categories', summary: 'Create a category or subcategory', tags: ['Catalogue'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function storeCategory(CategoryRequest $request): JsonResponse
    {
        return $this->success('Category created.', new CategoryResource($this->catalogue->createCategory($request->validated())), 201);
    }

    #[OA\Put(path: '/api/v1/catalogue/categories/{category}', summary: 'Update a category', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function updateCategory(CategoryRequest $request, Category $category): JsonResponse
    {
        return $this->success('Category updated.', new CategoryResource($this->catalogue->updateCategory($category, $request->validated())));
    }
}
