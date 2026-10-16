<?php

namespace Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BrandedPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Support\Permissions;
use Modules\Reports\Http\Requests\RunReportRequest;
use Modules\Reports\ReportRegistry;
use Modules\Reports\Services\ReportRunner;
use Modules\Reports\Support\Column;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/** Report catalogue, runs and CSV exports. */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
    ) {}

    #[OA\Get(path: '/api/v1/reports', summary: 'Reports this user may run, by group', tags: ['Reports'], responses: [new OA\Response(response: 200, description: 'Catalogue')])]
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REPORTS_VIEW), 403);

        return $this->success('Reports.', [
            'reports' => $this->registry->catalogue($request->user()),
            'canExport' => $request->user()->can(Permissions::REPORTS_EXPORT),
            // Filter choices limited to what this user may see.
            ...$this->runner->filterOptions($request->user()),
        ]);
    }

    #[OA\Get(path: '/api/v1/reports/{key}', summary: 'Run a report (filters: from, to, asAt, branchId, categoryId, brandId, userId, groupBy, status)', tags: ['Reports'], responses: [new OA\Response(response: 200, description: 'Columns, rows, totals')])]
    public function show(RunReportRequest $request, string $key): JsonResponse
    {
        $report = $this->runner->authorize($request->user(), $this->registry->find($key));
        $filters = $this->runner->filters($report, $request->user(), $request->validated());
        $result = $this->runner->run($report, $filters, $request->user());

        return $this->success($report->title(), [
            'report' => $this->runner->definition($report),
            'filters' => $filters->describe(),
            'columns' => array_map(fn (Column $c) => $c->toArray(), $result['columns']),
            'rows' => array_slice($result['rows'], 0, ReportRunner::SCREEN_LIMIT),
            'rowCount' => count($result['rows']),
            'truncated' => count($result['rows']) > ReportRunner::SCREEN_LIMIT,
            'totals' => $result['totals'],
            'notes' => $result['notes'],
        ]);
    }

    #[OA\Get(path: '/api/v1/reports/{key}/export', summary: 'Download a report as CSV, or format=pdf for an A4 PDF with the letterhead (needs reports.export; logged; cost columns only with reports.profit.view)', tags: ['Reports'], responses: [new OA\Response(response: 200, description: 'CSV or PDF')])]
    public function export(RunReportRequest $request, string $key, BrandedPdf $pdf): Response
    {
        $report = $this->runner->authorize($request->user(), $this->registry->find($key));
        $filters = $this->runner->filters($report, $request->user(), $request->validated());
        $result = $this->runner->run($report, $filters, $request->user());
        $format = $request->validated('format') ?? 'csv';
        $this->runner->recordExport($report, $filters, $request->user(), count($result['rows']), $format);

        if ($format === 'pdf') {
            return $pdf->download($this->runner->pdfDocument($report, $filters, $result), $report->key().'-'.now()->format('Ymd-His').'.pdf', $request->user());
        }

        ['columns' => $columns, 'rows' => $rows, 'totals' => $totals] = $result;

        return response()->streamDownload(function () use ($columns, $rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names and "→" correctly
            fputcsv($out, array_map(fn (Column $c) => $c->type === Column::MONEY ? "{$c->label} (KES)" : $c->label, $columns));
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (Column $c) => ReportRunner::csvValue($row[$c->key] ?? null, $c), $columns));
            }
            if ($totals) {
                fputcsv($out, array_map(fn (Column $c, int $i) => $i === 0 ? 'Total' : ReportRunner::csvValue($totals[$c->key] ?? null, $c), $columns, array_keys($columns)));
            }
            fclose($out);
        }, $report->key().'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
