import { WEEKDAYS, type WeeklyHours } from "@/types/settings";

/**
 * Licensed hours on the till (mirrors Modules\Sales\Support\LicensedHours). The server
 * re-checks every sale; this only stops the cashier early and says when sales open.
 */

const minutes = (time: string) => {
  const [h, m] = time.split(":").map(Number);
  return h * 60 + m;
};

/** Monday = 0, as in the schedule. */
const weekdayOf = (date: Date) => WEEKDAYS[(date.getDay() + 6) % 7];

export function isLicensedOpen(hours: WeeklyHours, at: Date): boolean {
  const now = at.getHours() * 60 + at.getMinutes();
  const yesterday = new Date(at);
  yesterday.setDate(at.getDate() - 1);

  const today = (hours[weekdayOf(at)] ?? []).some(([from, to]) => (minutes(to) > minutes(from) ? now >= minutes(from) && now < minutes(to) : now >= minutes(from)));
  // Yesterday's late period still running after midnight.
  const lateNight = (hours[weekdayOf(yesterday)] ?? []).some(([from, to]) => minutes(to) <= minutes(from) && now < minutes(to));
  return today || lateNight;
}

/** When alcohol sales open next, for the till banner: "at 17:00" / "on Monday at 17:00". */
export function licensedOpensLabel(hours: WeeklyHours, at: Date): string | null {
  for (let d = 0; d <= 7; d++) {
    const day = new Date(at.getFullYear(), at.getMonth(), at.getDate() + d);
    const starts = (hours[weekdayOf(day)] ?? []).map(([from]) => minutes(from)).sort((a, b) => a - b);
    for (const start of starts) {
      const opens = new Date(day.getFullYear(), day.getMonth(), day.getDate(), Math.floor(start / 60), start % 60);
      if (opens > at) {
        const time = opens.toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });
        return d === 0 ? `at ${time}` : `on ${opens.toLocaleDateString("en-GB", { weekday: "long" })} at ${time}`;
      }
    }
  }
  return null;
}
