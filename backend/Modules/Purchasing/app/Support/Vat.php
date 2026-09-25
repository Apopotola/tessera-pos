<?php

namespace Modules\Purchasing\Support;

/** VAT on a VAT-exclusive amount; rate in basis points (1600 = 16%), rounded to the nearest cent. */
final class Vat
{
    public static function on(int $netCents, int $rateBp): int
    {
        return intdiv($netCents * $rateBp + 5000, 10000);
    }

    /** @return array{net: int, vat: int, gross: int} */
    public static function line(int $quantity, int $unitCostCents, int $rateBp): array
    {
        $net = $quantity * $unitCostCents;
        $vat = self::on($net, $rateBp);

        return ['net' => $net, 'vat' => $vat, 'gross' => $net + $vat];
    }
}
