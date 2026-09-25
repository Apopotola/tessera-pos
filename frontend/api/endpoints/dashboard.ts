import { api } from "@/api/client";
import { DASHBOARD_URLS } from "@/api/urls";
import type { DashboardSummary } from "@/types/dashboard";

export const dashboardApi = {
  summary: () => api.get<DashboardSummary>(DASHBOARD_URLS.summary),
};
