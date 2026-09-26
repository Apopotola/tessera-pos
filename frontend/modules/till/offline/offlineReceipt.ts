import type { CartLine } from "@/modules/till/cart";
import { lineGross, lineTotal } from "@/modules/till/cart";
import type { AuthUser } from "@/types/auth";
import type { TillCustomer } from "@/types/customers";
import type { Sale, TenderPayload } from "@/types/sales";
import type { TillContext, TillSnapshot } from "@/types/till";
import { roundTo } from "@/utils/money";

interface OfflineReceiptInput {
  localNumber: string;
  occurredAt: string;
  context: TillContext;
  user: AuthUser;
  lines: CartLine[];
  tenders: TenderPayload[];
  customer: TillCustomer | null;
  customerPin: string | null;
  snapshot: TillSnapshot | null;
}

/** VAT inside a VAT-inclusive amount, rounded like the server (half up). */
const vatIncluded = (amount: number, ratePercent: number) => (ratePercent <= 0 ? 0 : Math.round((amount * ratePercent) / (100 + ratePercent)));

/**
 * The receipt printed while offline. It follows the same rules as the server (VAT-inclusive
 * prices, M-PESA/card first, cash covers the rest), but its number is provisional: the
 * server assigns the real one, and the eTIMS invoice, when the sale is sent.
 */
export function buildOfflineReceipt(input: OfflineReceiptInput): Sale {
  const { context, lines, tenders, snapshot } = input;
  const rates = new Map((snapshot?.items ?? []).map((i) => [i.variantId, i.taxRatePercent]));

  const saleLines = lines.map((line, i) => {
    const total = lineTotal(line);
    const rate = rates.get(line.variantId) ?? 16;
    return {
      id: -(i + 1),
      variant: { id: line.variantId, displayName: line.displayName, sku: line.sku },
      unit: line.unit,
      totMl: line.totMl,
      quantity: line.quantity,
      listPriceCents: line.listPriceCents,
      unitPriceCents: line.unitPriceCents,
      discountCents: line.discountCents,
      lineTotalCents: total,
      vatCents: vatIncluded(total, rate),
      taxRatePercent: rate,
      returnedQuantity: 0,
      approvedBy: null,
    };
  });

  const totalCents = saleLines.reduce((s, l) => s + l.lineTotalCents, 0);
  const nonCash = tenders.filter((t) => t.method !== "cash");
  const cashGiven = tenders.filter((t) => t.method === "cash").reduce((s, t) => s + t.amountCents, 0);
  const exactCashDue = totalCents - nonCash.reduce((s, t) => s + t.amountCents, 0);
  // Same cash rounding as the server (Settings → Payments).
  const cashDue = exactCashDue > 0 ? roundTo(exactCashDue, context.policy.cashRoundingCents) : exactCashDue;

  return {
    id: 0,
    number: input.localNumber,
    completedAt: input.occurredAt,
    capturedOffline: true,
    pendingSync: true,
    status: "completed",
    etimsStatus: "pending",
    etims: null,
    business: { name: context.business.name, kraPin: null },
    branch: context.branch,
    till: { id: context.till.id, name: context.till.name },
    cashier: { id: input.user.id, name: input.user.name },
    customerPin: input.customerPin ?? input.customer?.kraPin ?? null,
    customer: input.customer ? { id: input.customer.id, name: input.customer.name, isWholesale: input.customer.isWholesale } : null,
    subtotalCents: lines.reduce((s, l) => s + lineGross(l), 0),
    discountCents: lines.reduce((s, l) => s + l.discountCents, 0),
    totalCents,
    roundingCents: cashDue - exactCashDue,
    vatCents: saleLines.reduce((s, l) => s + l.vatCents, 0),
    returnedCents: 0,
    costCents: null,
    grossProfitCents: null,
    lines: saleLines,
    tenders: [
      ...nonCash.map((t) => ({ method: t.method, amountCents: t.amountCents, tenderedCents: null, changeCents: null, reference: t.reference ?? null, cardLast4: t.cardLast4 ?? null, status: "unverified" as const })),
      ...(cashDue > 0 || cashGiven > 0
        ? [{ method: "cash" as const, amountCents: cashDue, tenderedCents: cashGiven, changeCents: cashGiven - cashDue, reference: null, cardLast4: null, status: "confirmed" as const }]
        : []),
    ],
    returns: [],
    receipt: context.policy.receipt,
  };
}
