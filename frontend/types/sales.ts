/** Mirrors Modules\Sales resources. Prices are VAT-inclusive cents. */
import type { EtimsReceipt, EtimsStatus } from "@/types/compliance";

export type TenderMethod = "cash" | "mpesa" | "card";
export type ApprovalAction = "discount" | "override" | "void" | "refund" | "cash_drop";
/** A bottle off the shelf, or a tot poured from the open bottle. */
export type SaleUnit = "bottle" | "tot";

export interface TillItem {
  variantId: number;
  displayName: string;
  sku: string;
  priceCents: number | null;
  minPriceCents: number | null;
  /** Charged to wholesale customers when set. */
  wholesalePriceCents: number | null;
  taxRatePercent: number;
  onFloor: number;
  /** Sell by tot: null when the item is not poured. */
  totMl: number | null;
  totPriceCents: number | null;
  /** ml left in the bottle currently open at the bar (null = none open). */
  openBottleMl: number | null;
}

export interface ScanResult {
  item: TillItem;
  units: number;
  packName: string | null;
}

export interface Approval {
  token: string;
  approver: { id: number; name: string };
  expiresInSeconds: number;
}

export interface SaleLinePayload {
  variantId: number;
  unit: SaleUnit;
  quantity: number;
  unitPriceCents?: number | null;
  discountCents?: number | null;
  approvalToken?: string | null;
}

export interface TenderPayload {
  method: TenderMethod;
  amountCents: number;
  reference?: string | null;
  cardLast4?: string | null;
  /** M-PESA: the Safaricom confirmation this payment uses. */
  confirmationId?: number | null;
}

export interface SalePayload {
  clientId: string;
  /** Offline till: when the sale really happened (ISO 8601). */
  occurredAt?: string | null;
  customerId?: number | null;
  customerPin?: string | null;
  lines: SaleLinePayload[];
  tenders: TenderPayload[];
}

export interface Sale {
  id: number;
  number: string;
  completedAt: string;
  /** Rung up on an offline till and sent later. */
  capturedOffline: boolean;
  /** Client-side only: a receipt printed offline, not yet on the server. */
  pendingSync?: boolean;
  status: "completed" | "partially_returned" | "returned";
  etimsStatus: EtimsStatus;
  /** KRA details once signed; null while pending. */
  etims: EtimsReceipt | null;
  business: { name: string; kraPin: string | null };
  branch: { id: number; code: string; name: string };
  till: { id: number; name: string };
  cashier: { id: number; name: string };
  customerPin: string | null;
  customer: { id: number; name: string; isWholesale: boolean } | null;
  subtotalCents: number;
  discountCents: number;
  totalCents: number;
  vatCents: number;
  returnedCents: number;
  costCents: number | null;
  grossProfitCents: number | null;
  lines: {
    id: number;
    variant: { id: number; displayName: string; sku: string };
    unit: SaleUnit;
    totMl: number | null;
    quantity: number;
    listPriceCents: number;
    unitPriceCents: number;
    discountCents: number;
    lineTotalCents: number;
    vatCents: number;
    taxRatePercent: number;
    returnedQuantity: number;
    approvedBy: { id: number; name: string } | null;
  }[];
  tenders: {
    method: TenderMethod;
    amountCents: number;
    tenderedCents: number | null;
    changeCents: number | null;
    reference: string | null;
    cardLast4: string | null;
    status: "confirmed" | "unverified";
  }[];
  returns: { id: number; number: string; totalCents: number; reason: string; etimsStatus: EtimsStatus; createdAt: string | null }[];
  receiptFooter: string;
}

export interface ReturnPayload {
  saleId: number;
  reason: string;
  approvalToken: string;
  lines: { saleLineId: number; quantity: number; restock: boolean }[];
}

export interface ShiftRow {
  id: number;
  tillId: number;
  tillName: string;
  branchId: number;
  user?: { id: number; name: string };
  openingFloatCents: number;
  openedAt: string;
  closedAt: string | null;
  isOpen: boolean;
  expectedCashCents: number | null;
  countedCashCents: number | null;
  varianceCents: number | null;
  salesCount: number;
  takings: { cashCents: number; mpesaCents: number; cardCents: number };
  closeNote: string | null;
  /** Cash moved to the safe during the shift. */
  dropsCents: number;
  /** Count by denomination, after closing. */
  countBreakdown: { denominationCents: number; count: number }[] | null;
  varianceReason: string | null;
  reviewedBy?: { id: number; name: string } | null;
  reviewedAt: string | null;
  reviewNote: string | null;
}

/** GET /sales/shifts/{id}/detail */
export interface ShiftDetail extends ShiftRow {
  drops: { id: number; amountCents: number; note: string | null; witness: string; at: string }[];
}

/** A cart put aside at the till (GET/POST /sales/till/parked). */
export interface ParkedLine {
  variantId: number;
  unit: SaleUnit;
  quantity: number;
  displayName: string;
  sku: string;
  listPriceCents: number;
  unitPriceCents: number;
  discountCents: number;
  totMl: number | null;
}

export interface ParkedSale {
  id: number;
  label: string;
  lines: ParkedLine[];
  totalCents: number;
  parkedBy: string | null;
  createdAt: string | null;
}

/** GET /sales/open-bottles */
export interface OpenBottle {
  id: number;
  number: string;
  status: "open" | "finished" | "written_off";
  branch: { id: number; name: string };
  variant: { id: number; displayName: string; totMl: number | null };
  volumeMl: number;
  remainingMl: number;
  soldMl: number;
  writtenOffMl: number;
  costCents: number | null;
  openedBy: string;
  openedAt: string;
  closedBy: string | null;
  closedAt: string | null;
}
