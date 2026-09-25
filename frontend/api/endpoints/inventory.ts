import { api } from "@/api/client";
import { INVENTORY_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type {
  Adjustment,
  AdjustmentPayload,
  CountStatus,
  DocumentStatus,
  Movement,
  MovementFilters,
  StockCount,
  StockFilters,
  StockLocation,
  StockRow,
  Transfer,
  TransferPayload,
  TransferStatus,
} from "@/types/inventory";

/** lineId → quantity, as the dispatch/receive endpoints expect. */
type LineQuantities = Record<number, number>;

export const inventoryApi = {
  stock: (filters: StockFilters = {}) => api.get<Paginated<StockRow>>(U.stock, { ...filters, lowOnly: filters.lowOnly ? 1 : undefined }),
  movements: (filters: MovementFilters = {}) => api.get<Paginated<Movement>>(U.movements, filters),
  locations: () => api.get<StockLocation[]>(U.locations),
  setReorderLevel: (branchId: number, variantId: number, reorderLevel: number | null, reorderQuantity: number | null) =>
    api.put<null>(U.reorderLevels, { branchId, variantId, reorderLevel, reorderQuantity }),

  adjustments: (status?: DocumentStatus, page = 1) => api.get<Paginated<Adjustment>>(U.adjustments, { status, page }),
  createAdjustment: (payload: AdjustmentPayload) => api.post<Adjustment>(U.adjustments, payload),
  approveAdjustment: (id: number, note: string | null = null) => api.post<Adjustment>(U.approveAdjustment(id), { note }),
  rejectAdjustment: (id: number, note: string) => api.post<Adjustment>(U.rejectAdjustment(id), { note }),

  transfers: (status?: TransferStatus | "open", page = 1) => api.get<Paginated<Transfer>>(U.transfers, { status, page }),
  createTransfer: (payload: TransferPayload) => api.post<Transfer>(U.transfers, payload),
  approveTransfer: (id: number) => api.post<Transfer>(U.transferAction(id, "approve")),
  dispatchTransfer: (id: number, quantities: LineQuantities) => api.post<Transfer>(U.transferAction(id, "dispatch"), { quantities }),
  receiveTransfer: (id: number, quantities: LineQuantities, note: string | null) =>
    api.post<Transfer>(U.transferAction(id, "receive"), { quantities, note }),
  cancelTransfer: (id: number, note: string) => api.post<Transfer>(U.transferAction(id, "cancel"), { note }),

  counts: (status?: CountStatus, page = 1) => api.get<Paginated<StockCount>>(U.counts, { status, page }),
  count: (id: number) => api.get<StockCount>(U.count(id)),
  startCount: (locationId: number, note: string | null) => api.post<StockCount>(U.counts, { locationId, note }),
  recordCount: (id: number, lines: { variantId: number; countedQuantity: number | null }[]) => api.put<StockCount>(U.countLines(id), { lines }),
  submitCount: (id: number) => api.post<StockCount>(U.countAction(id, "submit")),
  approveCount: (id: number, note: string | null = null) => api.post<StockCount>(U.countAction(id, "approve"), { note }),
  rejectCount: (id: number, note: string) => api.post<StockCount>(U.countAction(id, "reject"), { note }),
};
