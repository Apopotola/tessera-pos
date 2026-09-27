import { api } from "@/api/client";
import { COMPLIANCE_URLS as U } from "@/api/urls";
import type { EtimsReconciliationRow, EtimsSubmissionsPage, EtimsStatus, LicencePayload, LicenceRegister, Licence } from "@/types/compliance";

export type EtimsFilter = "all" | "attention" | EtimsStatus;

export const complianceApi = {
  submissions: (filters: { status?: EtimsFilter; search?: string; page?: number }) => api.get<EtimsSubmissionsPage>(U.submissions, filters),
  retry: (id: number) => api.post<{ id: number; status: EtimsStatus }>(U.retry(id)),
  reconciliation: (from: string, to: string) => api.get<EtimsReconciliationRow[]>(U.reconciliation, { from, to }),

  // Licence & permit register
  licences: () => api.get<LicenceRegister>(U.licences),
  addLicence: (payload: LicencePayload) => api.post<Licence>(U.licences, payload),
  updateLicence: (id: number, payload: LicencePayload) => api.put<Licence>(U.licence(id), payload),
  removeLicence: (id: number) => api.delete<null>(U.licence(id)),
};
