import { api } from "@/api/client";
import { PAYMENTS_URLS as U } from "@/api/urls";
import type { MpesaConfirmation, MpesaConfirmationRow, MpesaConfirmationsPage, StkRequest, UnverifiedMpesaTender } from "@/types/payments";

export const paymentsApi = {
  // Till (paired device)
  sendStk: (phone: string, amountCents: number) => api.post<StkRequest>(U.stk, { phone, amountCents }),
  stkStatus: (id: number) => api.get<StkRequest>(U.stkStatus(id)),
  unallocated: () => api.get<MpesaConfirmation[]>(U.unallocated),
  /** Demo driver only: pretend a customer paid the till. */
  demoTillPayment: (amountCents: number) => api.post<MpesaConfirmation>(U.demoTillPayment, { amountCents }),

  // Back office
  confirmations: (filters: { from?: string; to?: string; status?: "all" | "matched" | "unallocated"; search?: string; page?: number }) =>
    api.get<MpesaConfirmationsPage>(U.confirmations, filters),
  unverified: () => api.get<UnverifiedMpesaTender[]>(U.unverified),
  match: (confirmationId: number, tenderId: number) => api.post<MpesaConfirmationRow>(U.match(confirmationId), { tenderId }),
};
