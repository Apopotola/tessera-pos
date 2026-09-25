/**
 * Mirrors Modules\Inventory\Http\Resources\* and StockQueryService. Quantities are whole units
 * (bottles/cans). Cost fields are null for users without reports.profit.view.
 */
export type LocationType = "shop_floor" | "store" | "warehouse" | "quarantine" | "transit";
export type AdjustmentType = "opening" | "found" | "breakage" | "expired" | "damaged" | "missing";
export type AdjustmentStage = "receiving" | "storage" | "shelf" | "sale" | "transit";
export type DocumentStatus = "pending" | "approved" | "rejected";
export type TransferStatus = "requested" | "approved" | "in_transit" | "received" | "cancelled";
export type CountStatus = "counting" | "submitted" | "approved" | "rejected";
export type MovementType =
  | "opening"
  | "found"
  | "breakage"
  | "expired"
  | "damaged"
  | "missing"
  | "transfer_dispatch"
  | "transfer_receive"
  | "count_variance";

export interface UserRef {
  id: number;
  name: string;
}

export interface VariantRef {
  id: number;
  displayName: string;
  sku: string;
}

export interface StockLocation {
  id: number;
  branchId: number;
  branchCode?: string;
  name: string;
  type: LocationType;
}

export interface StockRow {
  variantId: number;
  displayName: string;
  sku: string;
  branchId: number;
  branchCode: string | null;
  available: number;
  onFloor: number;
  inStore: number;
  quarantined: number;
  inTransit: number;
  reorderLevel: number | null;
  reorderQuantity: number | null;
  isLow: boolean;
  avgCostCents: number | null;
  valueCents: number | null;
}

export interface StockFilters {
  branchId?: number;
  search?: string;
  categoryId?: number;
  lowOnly?: boolean;
  page?: number;
}

export interface Movement {
  id: number;
  occurredAt: string;
  branchCode: string;
  location: string;
  variant: VariantRef;
  quantity: number;
  type: MovementType;
  reference: string;
  documentType: string;
  documentId: number;
  reason: string | null;
  user: UserRef | null;
  approvedBy: UserRef | null;
  unitCostCents: number | null;
}

export interface MovementFilters {
  branchId?: number;
  variantId?: number;
  type?: MovementType;
  from?: string;
  to?: string;
  page?: number;
}

export interface Adjustment {
  id: number;
  number: string;
  branchId: number;
  location: StockLocation;
  type: AdjustmentType;
  direction: 1 | -1;
  stage: AdjustmentStage | null;
  status: DocumentStatus;
  reason: string;
  requestedBy: UserRef | null;
  reviewedBy: UserRef | null;
  reviewedAt: string | null;
  reviewNote: string | null;
  source: { type: string; id: number } | null;
  createdAt: string | null;
  lines: { id: number; variant: VariantRef; quantity: number; unitCostCents: number | null }[];
}

export interface AdjustmentPayload {
  locationId: number;
  type: AdjustmentType;
  stage: AdjustmentStage | null;
  reason: string;
  lines: { variantId: number; quantity: number; unitCostCents?: number | null }[];
}

export interface TransferEnd {
  branchCode: string;
  branchName: string;
  location: StockLocation;
}

export interface Transfer {
  id: number;
  number: string;
  status: TransferStatus;
  from: TransferEnd;
  to: TransferEnd;
  note: string | null;
  requestedBy: UserRef | null;
  approvedBy: UserRef | null;
  approvedAt: string | null;
  dispatchedBy: UserRef | null;
  dispatchedAt: string | null;
  receivedBy: UserRef | null;
  receivedAt: string | null;
  createdAt: string | null;
  lines: {
    id: number;
    variant: VariantRef;
    quantityRequested: number;
    quantityDispatched: number | null;
    quantityReceived: number | null;
  }[];
}

export interface TransferPayload {
  fromLocationId: number;
  toLocationId: number;
  note: string | null;
  lines: { variantId: number; quantity: number }[];
}

export interface StockCount {
  id: number;
  number: string;
  branchId: number;
  location: StockLocation;
  status: CountStatus;
  note: string | null;
  createdBy: UserRef | null;
  submittedBy: UserRef | null;
  submittedAt: string | null;
  reviewedBy: UserRef | null;
  reviewedAt: string | null;
  reviewNote: string | null;
  createdAt: string | null;
  lineCount: number;
  /** Omitted in list responses. expectedQuantity/variance are null while counting (blind). */
  lines?: {
    id: number;
    variant: VariantRef;
    countedQuantity: number | null;
    expectedQuantity: number | null;
    variance: number | null;
    varianceValueCents: number | null;
  }[];
}
