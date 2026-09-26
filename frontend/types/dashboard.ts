/** Mirrors Modules\Dashboard\Services\DashboardService::summaryFor(). null = not permitted. */
export interface DashboardSummary {
  branches: { id: number; code: string; name: string }[];
  catalogue: { activeProducts: number; activeVariants: number } | null;
  pendingPriceChanges: number | null;
  tills: { total: number; connected: number } | null;
  openShifts:
    | {
        id: number;
        cashier: string;
        till: string;
        branchCode: string;
        openedAt: string;
        openingFloatCents: number;
      }[]
    | null;
  cashUps: { toReview: number; withDifference: number } | null;
  salesToday: {
    /** Same weekday last week, up to the same time of day. */
    lastWeekNetCents: number;
    /** Gross margin % of net sales ex VAT (null without cost access or sales). */
    marginPercent: number | null;
    transactions: number;
    grossCents: number;
    refundsCents: number;
    netCents: number;
    cashCents: number;
    mpesaCents: number;
    cardCents: number;
    grossProfitCents: number | null;
  } | null;
  inventory: {
    lowStock: number;
    pendingApprovals: number;
    stockValueCents: number | null;
    lossesThisMonthCents: number | null;
    /** At or below their level, fast movers first. */
    lowStockTop: { variantId: number; displayName: string; branchCode: string; available: number; level: number; soldLast30Days: number }[];
  } | null;
  /** Today, per cashier (owners and managers). */
  exceptions: {
    byCashier: ({ cashier: string } & Record<ExceptionKind, Tally>)[];
    totals: Record<ExceptionKind, Tally>;
  } | null;
  shrinkage: { lossesCents: number; cogsCents: number; percentOfCogs: number | null } | null;
  /** Closed cash-ups of the last 30 days per cashier. */
  cashVariance: {
    allowedCents: number;
    byCashier: { cashier: string; shifts: number; netCents: number; shortCents: number; overAllowed: number }[];
  } | null;
  movers: {
    /** Last 30 days by net sales. */
    top: { variantId: number; displayName: string; bottles: number; tots: number; netCents: number }[];
    /** In stock, no sale in 60 days. */
    slowCount: number;
    slowValueCents: number | null;
    slow: { variantId: number; displayName: string; quantity: number; valueCents: number | null }[];
  } | null;
  /** Month to date per outlet (multi-branch only). */
  branchComparison: { branchId: number; code: string; name: string; netCents: number; transactions: number; marginPercent: number | null; lossesCents: number | null }[] | null;
  compliance: {
    etimsDriver: string;
    waitingOverThreshold: number;
    pending: number;
    failed: number;
    rejected: number;
    oldestPendingMinutes: number | null;
  } | null;
  purchasing: { ordersAwaitingApproval: number; ordersAwaitingDelivery: number; invoicesWithVariance: number } | null;
  staff: { active: number; cashiersWithoutPin: number } | null;
}

export type ExceptionKind = "discounts" | "voids" | "refunds";

export interface Tally {
  count: number;
  cents: number;
}
