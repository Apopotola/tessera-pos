<?php

namespace Modules\Catalogue\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Catalogue\Enums\PriceStatus;
use Modules\Catalogue\Http\Requests\PriceChangeRequest;
use Modules\Catalogue\Http\Requests\ReviewPriceRequest;
use Modules\Catalogue\Http\Resources\PriceResource;
use Modules\Catalogue\Models\ProductVariant;
use Modules\Catalogue\Models\VariantPrice;
use Modules\Catalogue\Services\PriceService;
use OpenApi\Attributes as OA;

class PriceController extends Controller
{
    private const RELATIONS = ['variant.product', 'branch', 'requester', 'reviewer'];

    public function __construct(private readonly PriceService $prices) {}

    #[OA\Get(
        path: '/api/v1/catalogue/prices',
        summary: 'Price requests by status (default: pending), oldest first',
        tags: ['Catalogue'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected']))],
        responses: [new OA\Response(response: 200, description: 'Price requests page')],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAny([Permissions::PRICES_MANAGE, Permissions::PRICES_APPROVE]), 403);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PriceStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = PriceStatus::from($filters['status'] ?? PriceStatus::Pending->value);

        $page = VariantPrice::query()
            ->with(self::RELATIONS)
            ->where('status', $status)
            ->orderBy($status === PriceStatus::Pending ? 'created_at' : 'reviewed_at', $status === PriceStatus::Pending ? 'asc' : 'desc')
            ->paginate($filters['perPage'] ?? 25);

        return $this->success('Price requests retrieved.', [
            'items' => PriceResource::collection($page->items()),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
        ]);
    }

    #[OA\Get(path: '/api/v1/catalogue/variants/{variant}/prices', summary: 'Price history of a variant (newest first)', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'History')])]
    public function history(Request $request, ProductVariant $variant): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::CATALOGUE_VIEW), 403);

        $history = $variant->prices()
            ->with(['branch', 'requester', 'reviewer'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return $this->success('Price history retrieved.', PriceResource::collection($history));
    }

    #[OA\Post(
        path: '/api/v1/catalogue/variants/{variant}/prices',
        summary: 'Request a price change (approvers apply it immediately)',
        tags: ['Catalogue'],
        responses: [new OA\Response(response: 201, description: 'data = {price, warnings}'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(PriceChangeRequest $request, ProductVariant $variant): JsonResponse
    {
        ['price' => $price, 'warnings' => $warnings] = $this->prices->request($variant, $request->validated(), $request->user());
        $applied = $price->status === PriceStatus::Approved;

        return $this->success($applied ? 'Price applied.' : 'Price change sent for approval.', [
            'price' => new PriceResource($price->load(self::RELATIONS)),
            'warnings' => $warnings,
        ], 201);
    }

    #[OA\Post(path: '/api/v1/catalogue/prices/{price}/approve', summary: 'Approve a pending price (not your own)', tags: ['Catalogue'], responses: [
        new OA\Response(response: 200, description: 'Approved'),
        new OA\Response(response: 403, description: 'Own request or missing permission'),
    ])]
    public function approve(ReviewPriceRequest $request, VariantPrice $price): JsonResponse
    {
        $price = $this->prices->approve($price, $request->user(), $request->validated('note'));

        return $this->success('Price approved.', new PriceResource($price->load(self::RELATIONS)));
    }

    #[OA\Post(path: '/api/v1/catalogue/prices/{price}/reject', summary: 'Reject a pending price with a note', tags: ['Catalogue'], responses: [new OA\Response(response: 200, description: 'Rejected')])]
    public function reject(ReviewPriceRequest $request, VariantPrice $price): JsonResponse
    {
        $price = $this->prices->reject($price, $request->user(), $request->validated('note'));

        return $this->success('Price rejected.', new PriceResource($price->load(self::RELATIONS)));
    }
}
