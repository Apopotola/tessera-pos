<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Organisation\Models\Till;
use Modules\Sales\Http\Resources\PublicReceiptResource;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\ReceiptLinkService;
use OpenApi\Attributes as OA;

/** Shareable receipt links: made at the till or in Sales, opened by anyone holding the link. */
class ReceiptLinkController extends Controller
{
    public function __construct(
        private readonly ReceiptLinkService $links,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Post(path: '/api/v1/sales/till/sales/{number}/share-link', summary: 'A shareable receipt link for a sale at this till\'s branch (token shown once)', tags: ['Till'], responses: [
        new OA\Response(response: 201, description: 'data.token for /r/{token}'),
        new OA\Response(response: 404, description: 'No such sale at this branch'),
    ])]
    public function fromTill(Request $request, string $number): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SALES_SELL), 403);
        /** @var Till $till */
        $till = $request->attributes->get('till');
        $sale = Sale::query()->where('number', mb_strtoupper(trim($number)))->where('branch_id', $till->branch_id)->first();
        if (! $sale) {
            return $this->error('No sale with this receipt number at this branch.', 404);
        }

        return $this->success('Receipt link created.', ['token' => $this->links->create($sale, $request->user())], 201);
    }

    #[OA\Post(path: '/api/v1/sales/sales/{sale}/share-link', summary: 'A shareable receipt link from the back office (sales.view at the sale\'s branch)', tags: ['Sales'], responses: [
        new OA\Response(response: 201, description: 'data.token for /r/{token}'),
        new OA\Response(response: 403, description: 'No access to this sale'),
    ])]
    public function fromBackOffice(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::SALES_VIEW), 403);
        abort_unless(in_array($sale->branch_id, $this->stock->branchIds($request->user(), null), true), 403);

        return $this->success('Receipt link created.', ['token' => $this->links->create($sale, $request->user())], 201);
    }

    #[OA\Get(path: '/api/v1/sales/public/receipts/{token}', summary: 'Public: the receipt behind a shared link (no costs, ids or internal notes). Rate-limited; every open is audited.', tags: ['Sales'], responses: [
        new OA\Response(response: 200, description: 'Receipt'),
        new OA\Response(response: 404, description: 'No such link'),
        new OA\Response(response: 429, description: 'Too many requests'),
    ])]
    public function show(string $token): JsonResponse
    {
        $sale = $this->links->open($token);

        return $sale
            ? $this->success('Receipt.', new PublicReceiptResource($sale))
            : $this->error('This receipt link is not valid.', 404);
    }
}
