import { api, downloadFile } from "@/api/client";
import { REPORTS_URLS as U } from "@/api/urls";
import type { ReportCatalogue, ReportQuery, ReportRun } from "@/types/reports";

export const reportsApi = {
  catalogue: () => api.get<ReportCatalogue>(U.catalogue),
  run: (key: string, query: ReportQuery) => api.get<ReportRun>(U.report(key), query),
  /** CSV download; needs reports.export and is logged on the server. */
  exportCsv: (key: string, query: ReportQuery) => downloadFile(U.export(key), query, `${key}.csv`),
};
