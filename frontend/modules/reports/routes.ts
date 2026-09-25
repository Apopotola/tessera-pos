import type { OpenTabConfig } from "@/store/slices/tabsSlice";

/** Tab for one report (record tab, reload-safe via components/workspace/dynamicRoutes.ts). */
export function reportTab(report: { key: string; title?: string }, parentTabId?: string): OpenTabConfig {
  return {
    title: report.title ?? "Report",
    path: `/reports/${report.key}`,
    view: "reportView",
    recordId: report.key,
    parentTabId: parentTabId ?? null,
    props: { reportKey: report.key },
  };
}
