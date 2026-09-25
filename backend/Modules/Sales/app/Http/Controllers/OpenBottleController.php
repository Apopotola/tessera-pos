<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Inventory\Services\StockQueryService;
use Modules\Sales\Models\OpenBottle;
use Modules\Sales\Models\OpenBottlePour;
use Modules\Sales\Services\OpenBottleService;
use OpenApi\Attributes as OA;

/** Back office: bottles being sold by the tot, what each poured and what was written off. */
class OpenBottleController extends Controller
{
    public function __construct(
        private readonly OpenBottleService $bottles,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/sales/open-bottles', summary: 'Open (or closed) bottles sold by the tot', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Paginated')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::INVENTORY_VIEW), 403);
        $status = $request->validate(['status' => ['nullable', Rule::in([OpenBottle::OPEN, 'closed'])], 'page' => ['nullable', 'integer', 'min:1']])['status'] ?? OpenBottle::OPEN;
        $showCost = $request->user()->can(Permissions::REPORTS_PROFIT_VIEW);

        $page = OpenBottle::query()
            ->with(['variant.product', 'branch', 'opener', 'closer'])
            ->withSum(['pours as sold_ml' => fn ($q) => $q->where('kind', OpenBottlePour::SALE)], 'ml')
            ->withSum(['pours as written_off_ml' => fn ($q) => $q->where('kind', OpenBottlePour::WRITE_OFF)], 'ml')
            ->whereIn('branch_id', $this->stock->branchIds($request->user(), null))
            ->when($status === OpenBottle::OPEN, fn ($q) => $q->where('status', OpenBottle::OPEN), fn ($q) => $q->where('status', '!=', OpenBottle::OPEN))
            ->orderByDesc('opened_at')
            ->paginate(25);

        $page->setCollection($page->getCollection()->map(fn (OpenBottle $b) => [
            'id' => $b->id,
            'number' => $b->number,
            'status' => $b->status,
            'branch' => ['id' => $b->branch->id, 'name' => $b->branch->name],
            'variant' => ['id' => $b->variant->id, 'displayName' => $b->variant->display_name, 'totMl' => $b->variant->tot_ml],
            'volumeMl' => $b->volume_ml,
            'remainingMl' => $b->remainingMl(),
            'soldMl' => (int) $b->sold_ml,
            'writtenOffMl' => (int) $b->written_off_ml,
            'costCents' => $showCost ? $b->unit_cost_cents : null,
            'openedBy' => $b->opener->name,
            'openedAt' => $b->opened_at->toIso8601String(),
            'closedBy' => $b->closer?->name,
            'closedAt' => $b->closed_at?->toIso8601String(),
        ]));

        return $this->success('Open bottles.', $this->paginated($page));
    }

    #[OA\Post(path: '/api/v1/sales/open-bottles/{openBottle}/write-off', summary: 'Write off what is left in an open bottle (manager)', tags: ['Sales'], responses: [new OA\Response(response: 200, description: 'Written off')])]
    public function writeOff(Request $request, OpenBottle $openBottle): JsonResponse
    {
        abort_unless(in_array($openBottle->branch_id, $this->stock->branchIds($request->user(), null), true), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        $bottle = $this->bottles->writeOff($openBottle, $request->user(), $reason);

        return $this->success("{$bottle->number} written off.", ['id' => $bottle->id, 'status' => $bottle->status]);
    }
}
