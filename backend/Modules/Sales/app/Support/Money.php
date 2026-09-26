<?php

namespace Modules\Sales\Support;

final class Money
{
    /** VAT contained in a VAT-inclusive amount: amount × rate ÷ (1 + rate), rounded to the cent. */
    public static function vatIncluded(int $grossCents, int $rateBp): int
    {
        return $rateBp === 0 ? 0 : intdiv($grossCents * $rateBp * 2 + (10000 + $rateBp), 2 * (10000 + $rateBp));
    }

    /** A share of an amount, rounded: used to refund part of a discounted line. */
    public static function proportion(int $amountCents, int $part, int $whole): int
    {
        return $whole === 0 ? 0 : intdiv($amountCents * $part * 2 + $whole, 2 * $whole);
    }

    /** Round to the nearest step (e.g. 500 = KSh 5), halves up; step 0 leaves the amount as it is. */
    public static function roundTo(int $amountCents, int $stepCents): int
    {
        return $stepCents <= 0 ? $amountCents : intdiv($amountCents * 2 + $stepCents, 2 * $stepCents) * $stepCents;
    }
}
