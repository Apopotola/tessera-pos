import type { PromotionRule } from "@/types/sales";

/**
 * Mirrors Modules\Catalogue\Support\PromotionEngine so the till shows the same prices the API
 * will charge (also offline). The API's result is the one recorded on the sale.
 */

export interface PromotionLine {
  variantId: number;
  categoryIds: number[];
  brandId: number | null;
  unit: "bottle" | "tot";
  quantity: number;
  grossCents: number;
  /** Changed price or wholesale price: no promotion on top. */
  eligible: boolean;
}

export interface AppliedPromotion {
  promotionId: number | null;
  name: string | null;
  discountCents: number;
}

const pad = (n: number) => String(n).padStart(2, "0");
const localDate = (at: Date) => `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`;
const isoWeekday = (at: Date) => ((at.getDay() + 6) % 7) + 1;

export function runsAt(rule: PromotionRule, at: Date, branchId: number): boolean {
  const date = localDate(at);
  if (date < rule.startsOn || date > rule.endsOn) return false;
  if (rule.weekdays?.length && !rule.weekdays.includes(isoWeekday(at))) return false;
  if (rule.branchIds?.length && !rule.branchIds.includes(branchId)) return false;
  if (rule.timeFrom && rule.timeTo) {
    const time = `${pad(at.getHours())}:${pad(at.getMinutes())}`;
    // A window may run past midnight (22:00–02:00).
    const inside = rule.timeFrom <= rule.timeTo ? time >= rule.timeFrom && time < rule.timeTo : time >= rule.timeFrom || time < rule.timeTo;
    if (!inside) return false;
  }
  return true;
}

function matches(rule: PromotionRule, line: PromotionLine): boolean {
  if (!line.eligible || (rule.unit !== "any" && rule.unit !== line.unit)) return false;
  if (!rule.categoryIds.length && !rule.brandIds.length && !rule.variantIds.length) return true;
  return (
    rule.variantIds.includes(line.variantId) ||
    line.categoryIds.some((id) => rule.categoryIds.includes(id)) ||
    (line.brandId !== null && rule.brandIds.includes(line.brandId))
  );
}

/** The best promotion per line (ties: the older promotion), same order as `lines`. */
export function applyPromotions(rules: PromotionRule[], lines: PromotionLine[]): AppliedPromotion[] {
  const best: AppliedPromotion[] = lines.map(() => ({ promotionId: null, name: null, discountCents: 0 }));

  for (const rule of [...rules].sort((a, b) => a.id - b.id)) {
    const matching = lines.map((line, i) => (matches(rule, line) ? i : -1)).filter((i) => i >= 0);
    const quantity = matching.reduce((sum, i) => sum + lines[i].quantity, 0);
    if (!matching.length || quantity < Math.max(1, rule.minQuantity)) continue;

    for (const i of matching) {
      const gross = lines[i].grossCents;
      const discount = rule.discountType === "percent" ? Math.floor((gross * rule.discountValue) / 10000) : Math.min(gross, rule.discountValue * lines[i].quantity);
      if (discount > best[i].discountCents) best[i] = { promotionId: rule.id, name: rule.name, discountCents: discount };
    }
  }
  return best;
}
