/** Money travels as integer cents; convert only at the UI edge. */
const KES = new Intl.NumberFormat("en-KE", { style: "currency", currency: "KES", minimumFractionDigits: 2 });

export function formatKes(cents: number | null | undefined): string {
  return cents == null ? "—" : KES.format(cents / 100);
}

export function kesToCents(kes: number): number {
  return Math.round(kes * 100);
}

export function centsToKes(cents: number): number {
  return cents / 100;
}

/** Round to the nearest step (e.g. 500 = KSh 5), halves up. Mirrors Modules\Sales\Support\Money::roundTo. */
export function roundTo(cents: number, stepCents: number): number {
  return stepCents > 0 ? Math.floor((cents * 2 + stepCents) / (2 * stepCents)) * stepCents : cents;
}

/** Mantine NumberInput yields number | string; returns null for empty input. */
export function optionalKesToCents(value: number | string | null | undefined): number | null {
  if (value === "" || value == null) return null;
  const n = typeof value === "number" ? value : Number(value);
  return Number.isFinite(n) ? kesToCents(n) : null;
}
