<?php

namespace Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\StoreProductRequest;
use Modules\Catalogue\Http\Requests\UpdateProductRequest;
use Modules\Catalogue\Http\Resources\ProductResource;
use Modules\Catalogue\Models\Category;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Services\CatalogueService;
use Modules\Catalogue\Services\PriceResolver;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly PriceResolver $prices,
    ) {}

    #[OA\Get(
        path: '/api/v1/catalogue/products',
        summary: 'Paginated product list with variant summaries',
        tags: ['Catalogue'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Name, brand, SKU or barcode', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'categoryId', in: 'query', required: false, description: 'Includes subcategories', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'brandId', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'all'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Products page')],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'categoryId' => ['nullable', 'integer'],
            'brandId' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Product::query()
            ->with(['brand', 'category', 'variants'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $term) => $this->applySearch($q, $term))
            ->when($filters['categoryId'] ?? null, function (Builder $q, int $categoryId) {
                $ids = Category::query()->where('parent_id', $categoryId)->pluck('id')->push($categoryId);
                $q->whereIn('category_id', $ids);
            })
            ->when($filters['brandId'] ?? null, fn (Builder $q, int $brandId) => $q->where('brand_id', $brandId))
            ->when(($filters['status'] ?? 'active') !== 'all', fn (Builder $q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy('name')
            ->paginate($filters['perPage'] ?? 25);

        return $this->success('Products retrieved.', [
            'items' => ProductResource::collection($page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/catalogue/products/{product}',
        summary: 'Product with variants, barcodes, packs and current prices',
        tags: ['Catalogue'],
        parameters: [new OA\Parameter(name: 'branchId', in: 'query', required: false, description: 'Resolve branch-specific prices', schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product detail'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);
        $branchId = $request->integer('branchId') ?: null;

        return $this->success('Product retrieved.', new ProductResource($this->loadDetail($product, $branchId)));
    }

    #[OA\Post(path: '/api/v1/catalogue/products', summary: 'Create a product with its variants', tags: ['Catalogue'], responses: [
        new OA\Response(response: 201, description: 'Created; data = {product, warnings}'),
        new OA\Response(response: 422, description: 'Validation error'),
    ])]
    public function store(StoreProductRequest $request): JsonResponse
    {
        ['product' => $product, 'warnings' => $warnings] = $this->catalogue->createProduct($request->validated(), $request->user());

        return $this->success('Product created.', [
            'product' => new ProductResource($this->loadDetail($product, null)),
            'warnings' => $warnings,
        ], 201);
    }

    #[OA\Put(path: '/api/v1/catalogue/products/{product}', summary: 'Update product details', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->catalogue->updateProduct($product, $request->validated());

        return $this->success('Product updated.', new ProductResource($this->loadDetail($product, null)));
    }

    private function loadDetail(Product $product, ?int $branchId): Product
    {
        $product->load(['brand', 'category', 'variants.taxRate', 'variants.barcodes', 'variants.packs']);
        $current = $this->prices->currentForVariants($product->variants->modelKeys(), $branchId);

        foreach ($product->variants as $variant) {
            $variant->setRelation('currentPrices', $current[$variant->id] ?? []);
        }

        return $product;
    }

    /** @param Builder<Product> $query */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(function (Builder $q) use ($like, $term) {
            $q->whereRaw('lower(products.name) like ?', [$like])
                ->orWhereHas('brand', fn (Builder $b) => $b->whereRaw('lower(name) like ?', [$like]))
                ->orWhereHas('variants', fn (Builder $v) => $v->whereRaw('lower(sku) like ?', [$like])
                    ->orWhereHas('barcodes', fn (Builder $bc) => $bc->where('code', trim($term))));
        });
    }
}
