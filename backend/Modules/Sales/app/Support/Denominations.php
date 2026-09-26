<?php

namespace Modules\Sales\Support;

use Illuminate\Validation\ValidationException;

/** Kenyan shilling notes and coins, in cents, for counting the drawer. */
final class Denominations
{
    /** KES 1000, 500, 200, 100, 50 notes; KES 20, 10, 5, 1 coins. */
    public const KES = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 100];

    /**
     * @param  array<int|string, int>  $pieces  denomination (cents) => number of pieces
     * @return array{total: int, breakdown: array<string, int>}
     */
    public static function total(array $pieces): array
    {
        $total = 0;
        $breakdown = [];
        foreach ($pieces as $denomination => $count) {
            $value = (int) $denomination;
            if (! in_array($value, self::KES, true) || (int) $count < 0) {
                throw ValidationException::withMessages(['denominations' => 'Unknown note or coin in the count.']);
            }
            if ((int) $count > 0) {
                $breakdown[(string) $value] = (int) $count;
                $total += $value * (int) $count;
            }
        }

        return ['total' => $total, 'breakdown' => $breakdown];
    }
}
