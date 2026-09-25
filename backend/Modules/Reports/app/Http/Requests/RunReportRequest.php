<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Reports\ReportRegistry;

/** Filters for GET /reports/{key} and /reports/{key}/export. Permissions are checked by ReportRunner. */
class RunReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $report = app(ReportRegistry::class)->find((string) $this->route('key'));

        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'asAt' => ['nullable', 'date', 'before_or_equal:today'],
            'branchId' => ['nullable', 'integer'],
            'categoryId' => ['nullable', 'integer'],
            'brandId' => ['nullable', 'integer'],
            'userId' => ['nullable', 'integer'],
            'groupBy' => ['nullable', Rule::in(array_keys($report?->groupings() ?? []))],
            'status' => ['nullable', Rule::in(array_filter(array_keys($report?->statuses() ?? [])))],
        ];
    }
}
