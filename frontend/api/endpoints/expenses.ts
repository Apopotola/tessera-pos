import { api } from "@/api/client";
import { EXPENSES_URLS as U } from "@/api/urls";
import type { Expense, ExpenseCategory, ExpensePayload, ExpensesList, ExpenseStatus, TillPayoutPayload } from "@/types/expenses";

export const expensesApi = {
  list: (filters: { status?: ExpenseStatus | null; branchId?: number | null; from?: string | null; to?: string | null; page?: number }) => api.get<ExpensesList>(U.expenses, filters),
  categories: () => api.get<ExpenseCategory[]>(U.categories),
  create: (payload: ExpensePayload) => api.post<Expense>(U.expenses, payload),
  approve: (id: number) => api.post<Expense>(U.action(id, "approve")),
  reject: (id: number, note: string) => api.post<Expense>(U.action(id, "reject"), { note }),
  reverse: (id: number, note: string) => api.post<Expense>(U.action(id, "reverse"), { note }),
  /** Till: cash paid out of the drawer, witnessed with a manager's PIN. */
  tillPayout: (payload: TillPayoutPayload) => api.post<Expense>(U.tillPayout, payload),
};
