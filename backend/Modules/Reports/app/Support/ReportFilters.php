<?php

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Validated filters for one report run. Dates are in the business timezone; `to` is exclusive. */
final readonly class ReportFilters
{
    /** @param list<int> $branchIds branches the user may see, narrowed by the branch filter */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $asAt,
        public array $branchIds,
        public ?int $categoryId,
        public ?int $brandId,
        public ?int $userId,
        public ?string $groupBy,
        public ?string $status,
    ) {}

    /** The category and its sub-categories. @return list<int>|null */
    public function categoryIds(): ?array
    {
        if (! $this->categoryId) {
            return null;
        }

        return DB::table('categories')->where('id', $this->categoryId)->orWhere('parent_id', $this->categoryId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->subDay()->toDateString(),
            'asAt' => $this->asAt->toDateString(),
            'branchIds' => $this->branchIds,
            'categoryId' => $this->categoryId,
            'brandId' => $this->brandId,
            'userId' => $this->userId,
            'groupBy' => $this->groupBy,
            'status' => $this->status,
        ];
    }
}
