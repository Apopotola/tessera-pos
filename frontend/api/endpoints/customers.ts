import { api, downloadFile } from "@/api/client";
import { CUSTOMERS_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type { Customer, CustomerPayload, CustomerType, TillCustomer } from "@/types/customers";
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
};
