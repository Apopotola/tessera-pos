<?php

namespace Modules\Sales\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\Settings\Support\SettingsRegistry;

/**
 * The hours a branch's liquor licence allows alcohol to be sold (Settings → Sales screen).
 * Each weekday has zero or more windows "HH:MM"–"HH:MM"; a window whose end is not after
 * its start runs past midnight (20:00–02:00 covers the small hours of the next day).
 * A day with no windows means no alcohol sales that day. Times are in the app timezone.
 * Mirrored for the till screen in frontend/modules/till/licensedHours.ts.
 */
final class LicensedHours
{
    /** @param array<string, list<array{0: string, 1: string}>> $schedule */
    public function __construct(private readonly array $schedule) {}

    public function isOpen(CarbonInterface $at): bool
    {
        $at = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));
        $minute = $at->hour * 60 + $at->minute;

        foreach ($this->windows($at) as [$from, $to]) {
            if ($to > $from ? $minute >= $from && $minute < $to : $minute >= $from) {
                return true;
            }
        }
        // Yesterday's late window still running after midnight.
        foreach ($this->windows($at->subDay()) as [$from, $to]) {
            if ($to <= $from && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /** The next time alcohol sales open after $at (null = no licensed hours in the week ahead). */
    public function nextOpening(CarbonInterface $at): ?CarbonImmutable
    {
        $at = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'));

        for ($d = 0; $d <= 7; $d++) {
            $day = $at->addDays($d)->startOfDay();
            $starts = array_map(fn ($w) => $w[0], $this->windows($day));
            sort($starts);
            foreach ($starts as $from) {
                $opens = $day->addMinutes($from);
                if ($opens->gt($at)) {
                    return $opens;
                }
            }
        }

        return null;
    }

    /** "Sales open again at 17:00" / "… on Monday at 14:00", for messages. */
    public function whenOpen(CarbonInterface $at): string
    {
        $next = $this->nextOpening($at);
        if (! $next) {
            return 'No licensed hours are set for the coming week.';
        }

        return $next->isSameDay($at) ? "Sales open again at {$next->format('H:i')}." : "Sales open again on {$next->format('l')} at {$next->format('H:i')}.";
    }

    /** @return list<array{0: int, 1: int}> minutes after midnight */
    private function windows(CarbonInterface $day): array
    {
        $key = SettingsRegistry::WEEKDAYS[$day->dayOfWeekIso - 1];

        return array_map(fn ($w) => [self::minutes($w[0]), self::minutes($w[1])], $this->schedule[$key] ?? []);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
