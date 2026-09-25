<?php

namespace Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Http\Requests\BarcodeRequest;
use Modules\Catalogue\Http\Requests\PackRequest;
use Modules\Catalogue\Http\Requests\VariantRequest;
use Modules\Catalogue\Http\Resources\VariantResource;
use Modules\Catalogue\Models\Barcode;
use Modules\Catalogue\Models\Pack;
use Modules\Catalogue\Models\Product;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Services\BarcodeLookupService;
use Modules\Catalogue\Services\CatalogueService;
use Modules\Catalogue\Services\PriceResolver;
use OpenApi\Attributes as OA;

/** Variants (pack sizes) and what hangs off them: barcodes and packs. */
class VariantController extends Controller
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly PriceResolver $prices,
        private readonly BarcodeLookupService $lookup,
    ) {}

    #[OA\Post(path: '/api/v1/catalogue/products/{product}/variants', summary: 'Add a size to a product', tags: ['Catalogue'], responses: [
        new OA\Response(response: 201, description: 'Created; data = {variant, warnings}'),
    ])]
    public function store(VariantRequest $request, Product $product): JsonResponse
    {
        ['variant' => $variant, 'warnings' => $warnings] = $this->catalogue->createVariant($product, $request->validated(), $request->user());

        return $this->success('Variant created.', ['variant' => $this->resource($variant), 'warnings' => $warnings], 201);
    }

    #[OA\Put(path: '/api/v1/catalogue/variants/{variant}', summary: 'Update a variant', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function update(VariantRequest $request, ProductVariant $variant): JsonResponse
    {
        return $this->success('Variant updated.', $this->resource($this->catalogue->updateVariant($variant, $request->validated())));
    }

    #[OA\Post(path: '/api/v1/catalogue/variants/{variant}/barcodes', summary: 'Add a bottle or pack barcode', tags: ['Catalogue'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function storeBarcode(BarcodeRequest $request, ProductVariant $variant): JsonResponse
    {
        $pack = $request->filled('packId') ? Pack::query()->findOrFail($request->integer('packId')) : null;
        $this->catalogue->addBarcode($variant, $request->validated('code'), $pack);

        return $this->success('Barcode added.', $this->resource($variant), 201);
    }

    #[OA\Delete(path: '/api/v1/catalogue/variants/{variant}/barcodes/{barcode}', summary: 'Remove a barcode', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Removed')])]
    public function destroyBarcode(Request $request, ProductVariant $variant, Barcode $barcode): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_MANAGE), 403);
        $this->catalogue->removeBarcode($barcode);

        return $this->success('Barcode removed.', $this->resource($variant));
    }

    #[OA\Post(path: '/api/v1/catalogue/variants/{variant}/packs', summary: 'Add a case/crate pack', tags: ['Catalogue'], responses: [new OA\Response(response: 201, description: 'Created')])]
    public function storePack(PackRequest $request, ProductVariant $variant): JsonResponse
    {
        $this->catalogue->createPack($variant, $request->validated());

        return $this->success('Pack added.', $this->resource($variant), 201);
    }

    #[OA\Put(path: '/api/v1/catalogue/packs/{pack}', summary: 'Update a pack', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Updated')])]
    public function updatePack(PackRequest $request, Pack $pack): JsonResponse
    {
        $this->catalogue->updatePack($pack, $request->validated());

        return $this->success('Pack updated.', $this->resource($pack->variant));
    }

    #[OA\Get(
        path: '/api/v1/catalogue/lookup/{code}',
        summary: 'Resolve a scanned barcode to its variant (and pack units)',
        tags: ['Catalogue'],
        parameters: [new OA\Parameter(name: 'branchId', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: '{variant, pack, units}'), new OA\Response(response: 404, description: 'Unknown barcode')],
    )]
    public function lookup(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        $barcode = $this->lookup->find($code);
        if (! $barcode) {
            return $this->error('No product has this barcode.', 404);
        }

        return $this->success('Barcode resolved.', [
            'variant' => $this->resource($barcode->variant, $request->integer('branchId') ?: null),
            'pack' => $barcode->pack ? ['id' => $barcode->pack->id, 'name' => $barcode->pack->name, 'units' => $barcode->pack->units] : null,
            'units' => $barcode->pack->units ?? 1,
        ]);
    }

    private function resource(ProductVariant $variant, ?int $branchId = null): VariantResource
    {
        $variant->load(['product', 'taxRate', 'barcodes', 'packs']);
        $variant->setRelation('currentPrices', $this->prices->currentForVariants([$variant->id], $branchId)[$variant->id] ?? []);

        return new VariantResource($variant);
    }
}
