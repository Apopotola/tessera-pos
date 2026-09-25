<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Compliance\Services\EtimsProcessor;
use Modules\Inventory\Services\StockQueryService;
use OpenApi\Attributes as OA;

/** eTIMS monitor: every invoice and credit note on its way to KRA, failures, and the daily check. */
class EtimsMonitorController extends Controller
{
    public function __construct(
        private readonly EtimsProcessor $processor,
        private readonly StockQueryService $stock,
    ) {}

    #[OA\Get(path: '/api/v1/compliance/etims/submissions', summary: 'eTIMS submissions with status counts', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Paginated + summary')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_VIEW), 403);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'attention', EtimsSubmission::PENDING, EtimsSubmission::SIGNED, EtimsSubmission::FAILED, EtimsSubmission::REJECTED])],
            'search' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $branchIds = $this->stock->branchIds($request->user(), null);
        $base = EtimsSubmission::query()->whereIn('branch_id', $branchIds);
        $alertBefore = now()->subMinutes((int) config('compliance.etims.pending_alert_minutes', 60));

        $counts = (clone $base)->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');
        $summary = [
            'driver' => config('compliance.etims.driver'),
            'pending' => (int) ($counts[EtimsSubmission::PENDING] ?? 0),
            'signed' => (int) ($counts[EtimsSubmission::SIGNED] ?? 0),
            'failed' => (int) ($counts[EtimsSubmission::FAILED] ?? 0),
            'rejected' => (int) ($counts[EtimsSubmission::REJECTED] ?? 0),
            'waitingOverThreshold' => (clone $base)->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED])->where('created_at', '<', $alertBefore)->count(),
            'alertMinutes' => (int) config('compliance.etims.pending_alert_minutes', 60),
        ];

        $status = $filters['status'] ?? 'all';
        $page = (clone $base)
            ->with('branch')
            ->when($status === 'attention', fn ($q) => $q->whereIn('status', [EtimsSubmission::FAILED, EtimsSubmission::REJECTED]))
            ->when(! in_array($status, ['all', 'attention'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('document_number', 'like', '%'.mb_strtoupper($s).'%'))
            ->orderByDesc('id')
            ->paginate(25);

        $page->setCollection($page->getCollection()->map(fn (EtimsSubmission $s) => [
            'id' => $s->id,
            'documentType' => $s->document_type,
            'documentNumber' => $s->document_number,
            'originalDocumentNumber' => $s->original_document_number,
            'branch' => $s->branch->name,
            'status' => $s->status,
            'attempts' => $s->attempts,
            'nextAttemptAt' => $s->next_attempt_at?->toIso8601String(),
            'lastError' => $s->last_error,
            'kraInvoiceNumber' => $s->kra_invoice_number,
            'signedAt' => $s->kra_signed_at?->toIso8601String(),
            'createdAt' => $s->created_at?->toIso8601String(),
        ]));

        return $this->success('eTIMS submissions.', [...$this->paginated($page), 'summary' => $summary]);
    }

    #[OA\Post(path: '/api/v1/compliance/etims/submissions/{submission}/retry', summary: 'Send again now (after fixing a rejected item, or to skip the back-off)', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Result')])]
    public function retry(Request $request, EtimsSubmission $submission): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_MANAGE), 403);
        abort_unless(in_array($submission->branch_id, $this->stock->branchIds($request->user(), null), true), 403);
        abort_unless($this->processor->enabled(), 422, 'eTIMS is disabled on this server.');

        $result = $this->processor->retry($submission) ?? $submission;

        return $this->success(match ($result->status) {
            EtimsSubmission::SIGNED => "{$result->document_number} signed by KRA.",
            EtimsSubmission::REJECTED => "{$result->document_number} was refused: {$result->last_error}",
            default => "{$result->document_number} is still waiting: {$result->last_error}",
        }, ['id' => $result->id, 'status' => $result->status]);
    }

    #[OA\Get(path: '/api/v1/compliance/etims/reconciliation', summary: 'Daily POS vs KRA-signed invoices per branch', tags: ['Compliance'], responses: [new OA\Response(response: 200, description: 'Rows')])]
    public function reconciliation(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::COMPLIANCE_VIEW), 403);
        $filters = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $branchIds = $this->stock->branchIds($request->user(), null);
        $from = now()->parse($filters['from'])->startOfDay();
        $to = now()->parse($filters['to'])->addDay()->startOfDay();

        // Sales and credit notes by day, with how many KRA has signed. Days in Nairobi time.
        $day = "to_char(timezone('Africa/Nairobi', %s), 'YYYY-MM-DD')";
        $sales = DB::table('sales')
            ->leftJoin('etims_submissions as e', fn ($j) => $j->on('e.document_id', '=', 'sales.id')->where('e.document_type', EtimsSubmission::SALE))
            ->whereIn('sales.branch_id', $branchIds)->whereBetween('sales.completed_at', [$from, $to])
            ->selectRaw(sprintf($day, 'sales.completed_at').' AS day, sales.branch_id')
            ->selectRaw('count(*) AS pos_count, sum(sales.total_cents) AS pos_cents')
            ->selectRaw("count(*) FILTER (WHERE e.status = 'signed') AS signed_count, coalesce(sum(sales.total_cents) FILTER (WHERE e.status = 'signed'), 0) AS signed_cents")
            ->groupBy('day', 'sales.branch_id')->get();

        $returns = DB::table('sale_returns as r')
            ->leftJoin('etims_submissions as e', fn ($j) => $j->on('e.document_id', '=', 'r.id')->where('e.document_type', EtimsSubmission::CREDIT_NOTE))
            ->whereIn('r.branch_id', $branchIds)->whereBetween('r.created_at', [$from, $to])
            ->selectRaw(sprintf($day, 'r.created_at').' AS day, r.branch_id')
            ->selectRaw('count(*) AS pos_count, sum(r.total_cents) AS pos_cents')
            ->selectRaw("count(*) FILTER (WHERE e.status = 'signed') AS signed_count, coalesce(sum(r.total_cents) FILTER (WHERE e.status = 'signed'), 0) AS signed_cents")
            ->groupBy('day', 'r.branch_id')->get()
            ->keyBy(fn ($row) => "{$row->day}|{$row->branch_id}");

        $branches = DB::table('branches')->whereIn('id', $branchIds)->pluck('name', 'id');
        $keys = $sales->map(fn ($row) => "{$row->day}|{$row->branch_id}")->merge($returns->keys())->unique()->sortDesc();
        $salesByKey = $sales->keyBy(fn ($row) => "{$row->day}|{$row->branch_id}");

        $rows = $keys->map(function ($key) use ($salesByKey, $returns, $branches) {
            [$day, $branchId] = explode('|', $key);
            $s = $salesByKey[$key] ?? null;
            $r = $returns[$key] ?? null;
            $row = [
                'day' => $day,
                'branch' => $branches[(int) $branchId] ?? '—',
                'salesCount' => (int) ($s->pos_count ?? 0),
                'salesCents' => (int) ($s->pos_cents ?? 0),
                'signedSalesCount' => (int) ($s->signed_count ?? 0),
                'signedSalesCents' => (int) ($s->signed_cents ?? 0),
                'creditNotesCount' => (int) ($r->pos_count ?? 0),
                'creditNotesCents' => (int) ($r->pos_cents ?? 0),
                'signedCreditNotesCount' => (int) ($r->signed_count ?? 0),
                'signedCreditNotesCents' => (int) ($r->signed_cents ?? 0),
            ];
            $row['matches'] = $row['salesCount'] === $row['signedSalesCount'] && $row['creditNotesCount'] === $row['signedCreditNotesCount'];

            return $row;
        })->values();

        return $this->success('eTIMS daily reconciliation.', $rows);
    }
}
