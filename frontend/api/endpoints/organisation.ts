import { api } from "@/api/client";
import { ORGANISATION_URLS } from "@/api/urls";
import type { Branch } from "@/types/organisation";

export const organisationApi = {
  branches: () => api.get<Branch[]>(ORGANISATION_URLS.branches),
};
