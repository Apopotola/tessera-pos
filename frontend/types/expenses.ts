/** Mirrors Modules\Expenses\Http\Controllers\ExpenseController. Amounts are integer cents. */
import type { Paginated } from "@/types/api";

export type ExpenseStatus = "pending" | "approved" | "rejected";
export type ExpenseSource = "till" | "petty_cash" | "bank" | "mpesa";

export const EXPENSE_SOURCES: Record<ExpenseSource, string> = { till: "Till drawer", petty_cash: "Petty cash", bank: "Bank", mpesa: "M-PESA" };

export interface Expense {
  id: number;
  number: string;
  branch: { id: number; code: string; name: string };
  category: { id: number; name: string };
  /** Negative for a reversal. */
  amountCents: number;
  paidFrom: ExpenseSource;
  payee: string | null;
  description: string;
  reference: string | null;
  spentOn: string;
  status: ExpenseStatus;
  requestedById: number;
  requestedBy: string | null;
  reviewedBy: string | null;
  reviewNote: string | null;
  isReversal: boolean;
  reversed: boolean;
}

export interface ExpensesList extends Paginated<Expense> {
  totals: { approvedCents: number; pendingCents: number; pendingCount: number };
}

export interface ExpenseCategory {
  id: number;
  name: string;
}

export interface ExpensePayload {
  branchId: number;
  categoryId: number;
  amountCents: number;
  paidFrom: Exclude<ExpenseSource, "till">;
  payee: string | null;
  description: string;
  reference: string | null;
  spentOn: string;
}

export interface TillPayoutPayload {
  categoryId: number;
  amountCents: number;
  payee: string | null;
  description: string;
  approvalToken: string;
}
