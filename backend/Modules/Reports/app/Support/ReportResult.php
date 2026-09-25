<?php

namespace Modules\Reports\Support;

/** Rows keyed by column key; totals are computed from columns marked `total`. */
final readonly class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $rows,
        public array $notes = [],
    ) {}
}
