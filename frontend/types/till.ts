import type { TillCustomer } from "@/types/customers";
import type { ReceiptSettings, SalePayload, TenderMethod, TillItem } from "@/types/sales";

/** Mirrors Modules\Organisation\Http\Resources\TillResource. */
export interface Till {
  id: number;
  branchId: number;
  name: string;
  description: string | null;
  defaultFloatCents: number;
  isPaired: boolean;
  pairedAt: string | null;
  lastSeenAt: string | null;
  isActive: boolean;
}

export type QuickButton = "hold" | "discount" | "customer" | "returns" | "price_check" | "open_drawer";

/** Display-only staff card on the till screen (no contact details). */
export interface TillCashier {
  id: number;
  displayName: string;
  initials: string;
  role: string | null;
}

/** GET /organisation/till-context */
export interface TillContext {
  business: { name: string };
  branch: { id: number; code: string; name: string };
  till: Till;
  /** The owner's Settings for this till (Modules\Sales\Services\TillPolicy::forTill); the API re-checks them. */
  policy: {
    /** Largest discount without a manager, % of the line, per role. */
    discountLimits: Record<string, number>;
    discountAboveLimit: "approval" | "blocked";
    priceChangeNeedsApproval: boolean;
    refundNeedsApproval: boolean;
    /** Removing a line worth more than this needs a manager; null = never. */
    voidApprovalThresholdCents: number | null;
    /** Selling more bottles than the shop floor holds. */
    belowZero: "allow" | "approval" | "block";
    /** Accepted methods in button order. */
    paymentMethods: TenderMethod[];
    splitAllowed: boolean;
    /** Cash is rounded to this step in cents (0 = none). */
    cashRoundingCents: number;
    /** Send payment requests to the customer's phone (off: pick their payment only). */
    stkPush: boolean;
    etimsEnabled: boolean;
    sellByTot: boolean;
    /** Confirm the customer is 18 or over before payment. */
    ageCheck: boolean;
    blindCashUp: boolean;
    /** Lock the screen after this many idle minutes (0 = never); the sale in progress is kept. */
    autoLockMinutes: number;
    layout: "tiles" | "list" | "barcode";
    touchMode: "standard" | "large";
    quickButtons: QuickButton[];
    favouritesMode: "top" | "pinned" | "none";
    receipt: ReceiptSettings;
    returnWindowDays: number;
    /** "stk": prompt the phone or pick the customer's payment; "manual": type the code (unverified). */
    mpesaMode: "stk" | "manual";
    /** Demo M-PESA (fake driver): nothing reaches Safaricom. */
    mpesaDemo: boolean;
  };
  cashiers: TillCashier[];
}

export interface PairTillPayload {
  branchId: number;
  name: string;
  description: string | null;
  defaultFloatCents: number;
}

/** Mirrors Modules\Sales\Http\Resources\ShiftResource. Cash figures appear only after close (blind count). */
export interface Shift {
  id: number;
  tillId: number;
  branchId: number;
  user?: { id: number; name: string };
  openingFloatCents: number;
  openedAt: string;
  closedAt: string | null;
  isOpen: boolean;
  expectedCashCents: number | null;
  countedCashCents: number | null;
  varianceCents: number | null;
  /** Cash moved to the safe during the shift. */
  dropsCents: number;
  /** Count by denomination, after closing. */
  countBreakdown: { denominationCents: number; count: number }[] | null;
  varianceReason: string | null;
  reviewedBy?: { id: number; name: string } | null;
  reviewedAt: string | null;
  reviewNote: string | null;
}

/** GET /sales/till/catalogue — what the till keeps on the device to sell offline. */
export interface TillSnapshot {
  generatedAt: string;
  items: (TillItem & { search: string })[];
  barcodes: { code: string; variantId: number; units: number; packName: string | null }[];
  /** First-screen favourites, in order. */
  favouriteIds: number[];
  customers: TillCustomer[];
}

/** Something done offline that the server has not seen yet. */
export type QueuedEntry =
  | {
      kind: "sale";
      clientId: string;
      /** Signed-in cashier when it was rung up; only they can send it (their shift). */
      userId: number;
      localNumber: string;
      totalCents: number;
      queuedAt: string;
      payload: SalePayload;
      status: "pending" | "failed";
      error: string | null;
    }
  | {
      kind: "void";
      clientId: string;
      userId: number;
      queuedAt: string;
      payload: { variantId: number; quantity: number; valueCents: number };
      status: "pending" | "failed";
      error: string | null;
    };
