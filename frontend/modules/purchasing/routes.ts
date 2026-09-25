import type { OpenTabConfig } from "@/store/slices/tabsSlice";

/** Tab for one purchase order (record tab, reload-safe via components/workspace/dynamicRoutes.ts). */
export function purchaseOrderTab(order: { id: number; number?: string }, parentTabId?: string): OpenTabConfig {
  return {
    title: order.number ?? `PO #${order.id}`,
    path: `/purchasing/orders/${order.id}`,
    view: "purchaseOrderDetail",
    recordId: order.id,
    parentTabId: parentTabId ?? null,
    props: { orderId: order.id },
  };
}
