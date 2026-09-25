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
  salesToday: {
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
  } | null;
  compliance: { etimsDriver: string; waitingOverThreshold: number; rejected: number } | null;
  purchasing: { ordersAwaitingApproval: number; ordersAwaitingDelivery: number; invoicesWithVariance: number } | null;
  staff: { active: number; cashiersWithoutPin: number } | null;
}
