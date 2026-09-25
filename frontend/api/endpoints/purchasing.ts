import { api } from "@/api/client";
import { PURCHASING_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type { DocumentStatus } from "@/types/inventory";
import type {
  GoodsReceipt,
  InvoiceMatch,
  PurchaseOrder,
  PurchaseOrderPayload,
  PurchaseOrderStatus,
  ReceiveLinePayload,
  Supplier,
  SupplierInvoice,
  SupplierInvoicePayload,
  SupplierItem,
  SupplierPayload,
  SupplierReturn,
  SupplierReturnPayload,
} from "@/types/purchasing";

export const purchasingApi = {
  suppliers: () => api.get<Supplier[]>(U.suppliers),
  createSupplier: (payload: SupplierPayload) => api.post<Supplier>(U.suppliers, payload),
  updateSupplier: (id: number, payload: SupplierPayload) => api.put<Supplier>(U.supplier(id), payload),
  supplierItems: (id: number) => api.get<SupplierItem[]>(U.supplierItems(id)),

  orders: (status?: PurchaseOrderStatus | "open", page = 1, supplierId?: number) => api.get<Paginated<PurchaseOrder>>(U.orders, { status, page, supplierId }),
  order: (id: number) => api.get<PurchaseOrder>(U.order(id)),
  createOrder: (payload: PurchaseOrderPayload) => api.post<PurchaseOrder>(U.orders, payload),
  updateOrder: (id: number, payload: PurchaseOrderPayload) => api.put<PurchaseOrder>(U.order(id), payload),
  approveOrder: (id: number) => api.post<PurchaseOrder>(U.orderAction(id, "approve")),
  sendOrder: (id: number) => api.post<PurchaseOrder>(U.orderAction(id, "send")),
  cancelOrder: (id: number, note: string) => api.post<PurchaseOrder>(U.orderAction(id, "cancel"), { note }),
  receive: (id: number, lines: ReceiveLinePayload[], deliveryNoteRef: string | null, note: string | null) =>
    api.post<PurchaseOrder>(U.orderAction(id, "receive"), { lines, deliveryNoteRef, note }),
  receipts: (supplierId: number, uninvoiced = true) => api.get<GoodsReceipt[]>(U.receipts, { supplierId, uninvoiced: uninvoiced ? 1 : undefined }),

  invoices: (match?: InvoiceMatch, page = 1) => api.get<Paginated<SupplierInvoice>>(U.invoices, { match, page }),
  recordInvoice: (payload: SupplierInvoicePayload) => api.post<SupplierInvoice>(U.invoices, payload),

  returns: (status?: DocumentStatus, page = 1) => api.get<Paginated<SupplierReturn>>(U.returns, { status, page }),
  createReturn: (payload: SupplierReturnPayload) => api.post<SupplierReturn>(U.returns, payload),
  approveReturn: (id: number) => api.post<SupplierReturn>(U.returnAction(id, "approve")),
  rejectReturn: (id: number, note: string) => api.post<SupplierReturn>(U.returnAction(id, "reject"), { note }),
  recordCreditNote: (id: number, reference: string) => api.post<SupplierReturn>(U.returnAction(id, "credit-note"), { reference }),
};
