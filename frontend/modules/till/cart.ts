import { applyPromotions, runsAt } from "@/modules/till/promotions";
import type { ParkedLine, PromotionRule, SaleLinePayload, SaleUnit, TillItem } from "@/types/sales";

/** One cart line. Prices are the server's VAT-inclusive price for the unit; the API re-prices on submit. */
export interface CartLine {
  variantId: number;
  unit: SaleUnit;
  totMl: number | null;
  displayName: string;
  sku: string;
  listPriceCents: number;
  unitPriceCents: number;
  /** Bottle prices from the price list; the wholesale one applies to wholesale customers. */
  retailPriceCents: number;
  wholesalePriceCents: number | null;
  discountCents: number;
  quantity: number;
  onFloor: number;
  /** Manager approval for a price override or a discount above the cashier's limit. */
  approvalToken: string | null;
  approvedBy: string | null;
  /** For promotions (unknown on recalled parked lines: only item-targeted promotions apply then). */
  categoryIds: number[];
  brandId: number | null;
  /** Set by withPromotions(): the promotion's discount on this line. */
  promotionCents?: number;
  promotionName?: string | null;
}

/** A bottle and a tot of the same item are separate lines. */
export const lineKey = (line: Pick<CartLine, "variantId" | "unit">) => `${line.variantId}:${line.unit}`;
export const sameLine = (a: Pick<CartLine, "variantId" | "unit">, b: Pick<CartLine, "variantId" | "unit">) => lineKey(a) === lineKey(b);

export const lineGross = (line: CartLine) => line.quantity * line.unitPriceCents;
export const lineTotal = (line: CartLine) => lineGross(line) - line.discountCents - (line.promotionCents ?? 0);
export const lineName = (line: Pick<CartLine, "displayName" | "unit" | "totMl">) =>
  line.unit === "tot" ? `${line.displayName} — tot ${line.totMl}ml` : line.displayName;

export function newLine(item: TillItem, quantity: number, unit: SaleUnit = "bottle", wholesale = false): CartLine {
  const retail = (unit === "tot" ? item.totPriceCents : item.priceCents) ?? 0;
  const wholesalePrice = unit === "bottle" ? item.wholesalePriceCents : null;
  const price = wholesale && wholesalePrice ? wholesalePrice : retail;
  return {
    variantId: item.variantId,
    unit,
    totMl: unit === "tot" ? item.totMl : null,
    displayName: item.displayName,
    sku: item.sku,
    listPriceCents: price,
    unitPriceCents: price,
    retailPriceCents: retail,
    wholesalePriceCents: wholesalePrice,
    discountCents: 0,
    quantity,
    onFloor: item.onFloor,
    approvalToken: null,
    approvedBy: null,
    categoryIds: item.categoryIds ?? [],
    brandId: item.brandId ?? null,
  };
}

/**
 * Promotions running now at this branch, applied the way the API will (best one per line).
 * Lines at a changed price or at the wholesale price get none.
 */
export function withPromotions(lines: CartLine[], rules: PromotionRule[], at: Date, branchId: number): CartLine[] {
  const running = rules.filter((rule) => runsAt(rule, at, branchId));
  const applied = applyPromotions(
    running,
    lines.map((l) => ({
      variantId: l.variantId,
      categoryIds: l.categoryIds,
      brandId: l.brandId,
      unit: l.unit,
      quantity: l.quantity,
      grossCents: lineGross(l),
      eligible: l.unitPriceCents === l.listPriceCents && l.listPriceCents === (l.unit === "bottle" ? l.retailPriceCents : l.listPriceCents),
    })),
  );
  return lines.map((l, i) => ({ ...l, promotionCents: applied[i].discountCents, promotionName: applied[i].name }));
}

export function cartTotals(lines: CartLine[]) {
  const subtotal = lines.reduce((sum, l) => sum + lineGross(l), 0);
  const promotions = lines.reduce((sum, l) => sum + (l.promotionCents ?? 0), 0);
  const discount = lines.reduce((sum, l) => sum + l.discountCents, 0) + promotions;
  return { items: lines.reduce((sum, l) => sum + l.quantity, 0), subtotal, discount, promotions, total: subtotal - discount };
}

export function toPayload(line: CartLine): SaleLinePayload {
  return {
    variantId: line.variantId,
    unit: line.unit,
    quantity: line.quantity,
    unitPriceCents: line.unitPriceCents === line.listPriceCents ? null : line.unitPriceCents,
    discountCents: line.discountCents || null,
    approvalToken: line.approvalToken,
  };
}

/** Same rule as SaleService: a discount above the limit (or any override) needs a manager. */
/** Settings → Staff: this cashier's discount limit and what needs a manager. */
export interface ApprovalRules {
  limitPercent: number;
  priceChangeNeedsApproval: boolean;
  /** Discounts above the limit are refused outright (not even a manager). */
  blockBigDiscounts: boolean;
}

export function needsApproval(line: CartLine, rules: ApprovalRules): "override" | "discount" | "blocked" | null {
  const bigDiscount = line.discountCents > Math.floor((lineGross(line) * rules.limitPercent) / 100);
  if (bigDiscount && rules.blockBigDiscounts) return "blocked";
  if (line.unitPriceCents !== line.listPriceCents && rules.priceChangeNeedsApproval) return "override";
  if (bigDiscount) return "discount";
  return null;
}

export function toParked(line: CartLine): ParkedLine {
  const { variantId, unit, quantity, displayName, sku, listPriceCents, unitPriceCents, discountCents, totMl } = line;
  return { variantId, unit, quantity, displayName, sku, listPriceCents, unitPriceCents, discountCents, totMl };
}

/**
 * Parked lines come back without approvals (tokens are single-use and short-lived), so any
 * line that needed a manager returns at list price with no discount.
 */
export function fromParked(line: ParkedLine, rules: ApprovalRules): { line: CartLine; reset: boolean } {
  const restored: CartLine = { ...line, retailPriceCents: line.listPriceCents, wholesalePriceCents: null, onFloor: 0, approvalToken: null, approvedBy: null, categoryIds: [], brandId: null };
  if (needsApproval(restored, rules)) {
    return { line: { ...restored, unitPriceCents: restored.listPriceCents, discountCents: 0 }, reset: true };
  }
  return { line: restored, reset: false };
}

/**
 * Customer changed: bottles at the list price move to the wholesale (or back to the retail)
 * price. Lines with a manager-approved price or discount keep it (the API re-checks).
 */
export function repriceForCustomer(lines: CartLine[], wholesale: boolean): CartLine[] {
  return lines.map((line) => {
    if (line.unit !== "bottle" || line.approvalToken || line.unitPriceCents !== line.listPriceCents) return line;
    const price = wholesale && line.wholesalePriceCents ? line.wholesalePriceCents : line.retailPriceCents;
    return { ...line, listPriceCents: price, unitPriceCents: price };
  });
}

/** Barcode scanners type digits then Enter. */
export const looksLikeBarcode = (value: string) => /^\d{6,}$/.test(value.trim());

export function newClientId(): string {
  return crypto.randomUUID();
}
