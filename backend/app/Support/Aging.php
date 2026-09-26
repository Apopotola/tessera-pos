<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Aged balances (receivables and payables): payments and credits settle the oldest
 * amounts first, and what is left is bucketed by age: 0–30, 31–60, 61–90, 90+ days.
 */
final class Aging
{
    public const BUCKETS = ['0_30' => '0–30 days', '31_60' => '31–60 days', '61_90' => '61–90 days', 'over_90' => '90+ days'];

    /**
     * @param  list<array{date: CarbonInterface|string, due?: CarbonInterface|string|null, amount: int}>  $debits  what was charged (invoices, sales on account)
     * @param  int  $credits  everything paid or credited back
     * @return array{balanceCents: int, buckets: array<string, int>, overdueCents: int, oldestDays: int|null}
     */
    public static function of(array $debits, int $credits, ?CarbonInterface $asAt = null): array
    {
        $asAt = CarbonImmutable::parse($asAt ?? now())->startOfDay();
        usort($debits, fn ($a, $b) => strcmp((string) CarbonImmutable::parse($a['date']), (string) CarbonImmutable::parse($b['date'])));

        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0);
        $overdue = 0;
        $oldest = null;
        $left = $credits;
        $charged = 0;

        foreach ($debits as $debit) {
            $amount = (int) $debit['amount'];
            $charged += $amount;
            $settled = min($amount, max($left, 0));
            $left -= $settled;
            $open = $amount - $settled;
            if ($open <= 0) {
                continue;
            }

            $days = (int) CarbonImmutable::parse($debit['date'])->startOfDay()->diffInDays($asAt, false);
            $oldest ??= $days;
            $buckets[match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            }] += $open;
            if (! empty($debit['due']) && CarbonImmutable::parse($debit['due'])->startOfDay()->lt($asAt)) {
                $overdue += $open;
            }
        }

        return ['balanceCents' => $charged - $credits, 'buckets' => $buckets, 'overdueCents' => $overdue, 'oldestDays' => $oldest];
    }
}
