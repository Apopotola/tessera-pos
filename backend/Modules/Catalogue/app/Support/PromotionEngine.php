<?php

namespace Modules\Catalogue\Support;

use Carbon\CarbonInterface;

/**
 * Applies promotions to sale lines. Mirrored by frontend/modules/till/promotions.ts so the till
 * shows the same totals (also offline); the API's result is the one recorded.
 *
 * Rules: a promotion runs on its dates, weekdays, time window and branches. A line matches
 * when its unit matches and its item, category (or parent category) or brand is targeted
 * (no targets = every item). Lines at a changed price or at the wholesale price never match.
 * When the matching quantity in the sale reaches the minimum, each matching line gets the
 * discount; a line takes only its best promotion (ties: the older promotion).
 */
final class PromotionEngine
{
    /**
     * @param  array<string, mixed>  $rule  Promotion::rule()
     */
    public static function runsAt(array $rule, CarbonInterface $at, int $branchId): bool
    {
        $date = $at->toDateString();
        if ($date < $rule['startsOn'] || $date > $rule['endsOn']) {
            return false;
        }
        if (! empty($rule['weekdays']) && ! in_array($at->isoWeekday(), $rule['weekdays'], true)) {
            return false;
        }
        if (! empty($rule['branchIds']) && ! in_array($branchId, $rule['branchIds'], true)) {
            return false;
        }
        if ($rule['timeFrom'] && $rule['timeTo']) {
            $time = $at->format('H:i');
            // A window may run past midnight (22:00–02:00).
            $inside = $rule['timeFrom'] <= $rule['timeTo']
                ? $time >= $rule['timeFrom'] && $time < $rule['timeTo']
                : $time >= $rule['timeFrom'] || $time < $rule['timeTo'];
            if (! $inside) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rules  running promotions
     * @param  list<array{variantId: int, categoryIds: list<int>, brandId: int|null, unit: string, quantity: int, grossCents: int, eligible: bool}>  $lines
     * @return list<array{promotionId: int|null, name: string|null, discountCents: int}> one per line, same order
     */
    public static function apply(array $rules, array $lines): array
    {
        $best = array_fill(0, count($lines), ['promotionId' => null, 'name' => null, 'discountCents' => 0]);

        foreach ($rules as $rule) {
            $matching = array_keys(array_filter($lines, fn ($line) => self::matches($rule, $line)));
            $quantity = array_sum(array_map(fn ($i) => $lines[$i]['quantity'], $matching));
            if ($matching === [] || $quantity < max(1, (int) $rule['minQuantity'])) {
                continue;
            }

            foreach ($matching as $i) {
                $gross = (int) $lines[$i]['grossCents'];
                $discount = $rule['discountType'] === 'percent'
                    ? intdiv($gross * (int) $rule['discountValue'], 10000)
                    : min($gross, (int) $rule['discountValue'] * (int) $lines[$i]['quantity']);
                if ($discount > $best[$i]['discountCents']) {
                    $best[$i] = ['promotionId' => (int) $rule['id'], 'name' => $rule['name'], 'discountCents' => $discount];
                }
            }
        }

        return $best;
    }

    /** @param array<string, mixed> $rule @param array<string, mixed> $line */
    private static function matches(array $rule, array $line): bool
    {
        if (! $line['eligible'] || ($rule['unit'] !== 'any' && $rule['unit'] !== $line['unit'])) {
            return false;
        }
        if ($rule['categoryIds'] === [] && $rule['brandIds'] === [] && $rule['variantIds'] === []) {
            return true;
        }

        return in_array($line['variantId'], $rule['variantIds'], true)
            || array_intersect($line['categoryIds'], $rule['categoryIds']) !== []
            || ($line['brandId'] !== null && in_array($line['brandId'], $rule['brandIds'], true));
    }
}
