import { api } from "@/api/client";
import { ORGANISATION_URLS } from "@/api/urls";
import type { Branch } from "@/types/organisation";
import type { PairTillPayload, Till, TillContext } from "@/types/till";

export const organisationApi = {
  branches: () => api.get<Branch[]>(ORGANISATION_URLS.branches),
  tills: () => api.get<Till[]>(ORGANISATION_URLS.tills),
  pairTill: (payload: PairTillPayload) => api.post<{ till: Till; deviceToken: string }>(ORGANISATION_URLS.pairTill, payload),
  unpairTill: (id: number) => api.post<Till>(ORGANISATION_URLS.unpairTill(id)),
  tillContext: () => api.get<TillContext>(ORGANISATION_URLS.tillContext),
};
