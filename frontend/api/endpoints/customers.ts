import { api, downloadFile } from "@/api/client";
import { CUSTOMERS_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type { Customer, CustomerPayload, CustomerType, TillCustomer } from "@/types/customers";
import type { AccountPayment, AccountsList, CustomerAccount, CustomerPaymentPayload, Statement } from "@/types/accounts";
import type { Sale } from "@/types/sales";

export const customersApi = {
  list: (filters: { search?: string; type?: CustomerType; page?: number }) => api.get<Paginated<Customer>>(U.customers, filters),
  get: (id: number) => api.get<Customer>(U.customer(id)),
  create: (payload: CustomerPayload) => api.post<Customer>(U.customers, payload),
  update: (id: number, payload: CustomerPayload) => api.put<Customer>(U.customer(id), payload),
  sales: (id: number, page = 1) => api.get<Paginated<Sale>>(U.sales(id), { page }),
  /** Data subject request (Owner/Admin; logged). */
  exportData: (id: number) => downloadFile(U.export(id), {}, `customer-${id}-data.json`),
  anonymise: (id: number, reason: string) => api.post<Customer>(U.anonymise(id), { reason }),
  /** Till: by name or KRA PIN, no contact details. */
  tillSearch: (search: string) => api.get<TillCustomer[]>(U.tillSearch, { search }),

  // Credit accounts (receivables)
  accounts: (asAt?: string) => api.get<AccountsList<CustomerAccount & { kraPin: string | null }>>(U.accounts, asAt ? { asAt } : undefined),
  account: (id: number) => api.get<CustomerAccount>(U.account(id)),
  setCredit: (id: number, creditLimitCents: number | null, creditTermsDays: number) => api.put<CustomerAccount>(U.credit(id), { creditLimitCents, creditTermsDays }),
  statement: (id: number, from: string, to: string) => api.get<Statement>(U.statement(id), { from, to }),
  payments: (id: number) => api.get<AccountPayment[]>(U.payments(id)),
  receivePayment: (id: number, payload: CustomerPaymentPayload) => api.post<AccountPayment>(U.payments(id), payload),
  reversePayment: (paymentId: number, reason: string) => api.post<AccountPayment>(U.reversePayment(paymentId), { reason }),
};
