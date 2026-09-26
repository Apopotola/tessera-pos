/**
 * Mirrors Modules\Purchasing\Http\Resources\PurchasingResources. Costs are per unit,
 * excluding VAT, in cents; totals include VAT per each item's tax rate.
 */
import type { DocumentStatus, UserRef, VariantRef } from "@/types/inventory";

export type PurchaseOrderStatus = "draft" | "approved" | "sent" | "partially_received" | "received" | "cancelled";
export type InvoiceMatch = "matched" | "variance";

export interface Supplier {
  id: number;
  name: string;
  kraPin: string | null;
  contactPerson: string | null;
  phone: string | null;
  email: string | null;
  address: string | null;
  paymentTermsDays: number;
  paymentDetails: string | null;
  notes: string | null;
  isActive: boolean;
}

export type SupplierPayload = Omit<Supplier, "id" | "isActive"> & { isActive?: boolean };

export interface SupplierItem {
  variantId: number;
  supplierCode: string | null;
  lastCostCents: number | null;
  lastPurchasedAt: string | null;
}

export interface GoodsReceipt {
  id: number;
  number: string;
  purchaseOrderId: number;
  purchaseOrderNumber: string | null;
  deliveryNoteRef: string | null;
  note: string | null;
  receivedBy: UserRef | null;
  receivedAt: string | null;
  invoiced: boolean;
  valueCents: number;
  lines: { variant: VariantRef; quantityReceived: number; quantityDamaged: number; batchNumber: string | null; expiryDate: string | null }[];
}

export interface PurchaseOrder {
  id: number;
  number: string;
  status: PurchaseOrderStatus;
  supplier: { id: number; name: string };
  branchId: number;
  location: { id: number; name: string };
  expectedDate: string | null;
  note: string | null;
  createdBy: UserRef | null;
  approvedBy: UserRef | null;
  approvedAt: string | null;
  sentAt: string | null;
  cancelReason: string | null;
  createdAt: string | null;
  totals: { netCents: number; vatCents: number; grossCents: number };
  lines: {
    id: number;
    variant: VariantRef;
    quantityOrdered: number;
    quantityReceived: number;
    quantityDamaged: number;
    outstanding: number;
    unitCostCents: number;
    taxRatePercent: number;
    lineTotalCents: number;
  }[];
  receipts: GoodsReceipt[];
}

export interface PurchaseOrderPayload {
  supplierId: number;
  locationId: number;
  expectedDate: string | null;
  note: string | null;
  lines: { variantId: number; quantity: number; unitCostCents: number }[];
}

export interface ReceiveLinePayload {
  lineId: number;
  received: number;
  damaged: number;
  batchNumber: string | null;
  expiryDate: string | null;
}

export interface SupplierInvoice {
  id: number;
  supplier: { id: number; name: string };
  invoiceNumber: string;
  invoiceDate: string;
  dueDate: string;
  subtotalCents: number;
  vatCents: number;
  totalCents: number;
  expectedTotalCents: number;
  varianceCents: number;
  matchStatus: InvoiceMatch;
  note: string | null;
  recordedBy: UserRef | null;
  receiptNumbers: string[];
  createdAt: string | null;
}

export interface SupplierInvoicePayload {
  supplierId: number;
  invoiceNumber: string;
  invoiceDate: string;
  subtotalCents: number;
  vatCents: number;
  totalCents: number;
  grnIds: number[];
  note: string | null;
}

export interface SupplierReturn {
  id: number;
  number: string;
  status: DocumentStatus;
  supplier: { id: number; name: string };
  location: { id: number; name: string };
  reason: string;
  creditNoteRef: string | null;
  /** The supplier's credit note value; lowers what we owe them. */
  creditNoteCents: number | null;
  creditNoteDate: string | null;
  requestedBy: UserRef | null;
  reviewedBy: UserRef | null;
  reviewNote: string | null;
  createdAt: string | null;
  lines: { variant: VariantRef; quantity: number }[];
}

export interface SupplierReturnPayload {
  supplierId: number;
  locationId: number;
  reason: string;
  lines: { variantId: number; quantity: number }[];
}
