import type { PurchaseOrderStatus } from "@/types/purchasing";

export const PO_STATUS_LABEL: Record<PurchaseOrderStatus, string> = {
  draft: "Draft — needs approval",
  approved: "Approved",
  sent: "Sent to supplier",
  partially_received: "Partly received",
  received: "Received",
  cancelled: "Cancelled",
};

export const PO_STATUS_COLOR: Record<PurchaseOrderStatus, string> = {
  draft: "yellow",
  approved: "tessera",
  sent: "blue",
  partially_received: "cyan",
  received: "green",
  cancelled: "gray",
};
