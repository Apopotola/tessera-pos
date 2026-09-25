import type { OpenTabConfig } from "@/store/slices/tabsSlice";

/** Tab for one customer (record tab, reload-safe via components/workspace/dynamicRoutes.ts). */
export function customerTab(customer: { id: number; name?: string }, parentTabId?: string): OpenTabConfig {
  return {
    title: customer.name ?? `Customer #${customer.id}`,
    path: `/customers/${customer.id}`,
    view: "customerDetail",
    recordId: customer.id,
    parentTabId: parentTabId ?? null,
    props: { customerId: customer.id },
  };
}
