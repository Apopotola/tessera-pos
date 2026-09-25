import type { OpenTabConfig } from "@/store/slices/tabsSlice";

/** Tab for one stock count sheet (record tab, reload-safe via components/workspace/dynamicRoutes.ts). */
export function countSheetTab(count: { id: number; number?: string }, parentTabId?: string): OpenTabConfig {
  return {
    title: count.number ?? `Count #${count.id}`,
    path: `/inventory/counts/${count.id}`,
    view: "stockCountSheet",
    recordId: count.id,
    parentTabId: parentTabId ?? null,
    props: { countId: count.id },
  };
}
