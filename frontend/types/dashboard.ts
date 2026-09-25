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
  staff: { active: number; cashiersWithoutPin: number } | null;
}
