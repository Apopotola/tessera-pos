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
  policy: {
    discountLimitPercent: number;
    voidApprovalThresholdCents: number;
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
}
