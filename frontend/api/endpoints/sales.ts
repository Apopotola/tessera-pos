import { api } from "@/api/client";
import { PING_URL, SALES_URLS as U } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type { ApprovalAction, Approval, OpenBottle, ParkedLine, ParkedSale, ReturnPayload, Sale, SalePayload, ScanResult, ShiftDetail, ShiftRow, TillItem } from "@/types/sales";
import type { Shift, TillSnapshot } from "@/types/till";

export const salesApi = {
  // Till shift (signed-in cashier on a paired till)
  currentShift: () => api.get<Shift | null>(U.currentShift),
  startShift: (openingFloatCents?: number) => api.post<Shift>(U.shifts, openingFloatCents == null ? {} : { openingFloatCents }),
  /** Blind close; with `denominations` (cents => pieces) the server adds up the count itself. */
  closeShift: (id: number, countedCashCents: number, note: string | null, denominations?: Record<string, number>) =>
    api.post<Shift>(U.closeShift(id), { countedCashCents, note, denominations }),
  cashDrop: (shiftId: number, amountCents: number, note: string | null, approvalToken: string) =>
    api.post<{ id: number; amountCents: number; dropsCents: number }>(U.cashDrops(shiftId), { amountCents, note, approvalToken }),
  varianceReason: (shiftId: number, reason: string) => api.post<Shift>(U.varianceReason(shiftId), { reason }),

  // Selling
  searchItems: (search: string) => api.get<TillItem[]>(U.tillItems, { search }),
  /** First-screen favourites (Settings → Sales screen). */
  favourites: () => api.get<TillItem[]>(U.tillFavourites),
  tillCatalogue: () => api.get<TillSnapshot>(U.tillCatalogue),
  ping: () => api.get<{ time: string }>(PING_URL),
  scan: (code: string) => api.get<ScanResult>(U.tillScan(code)),
  approvers: (action: ApprovalAction) => api.get<{ id: number; name: string }[]>(U.tillApprovers, { action }),
  approve: (approverId: number, pin: string, action: ApprovalAction) => api.post<Approval>(U.tillApprovals, { approverId, pin, action }),
  completeSale: (payload: SalePayload) => api.post<Sale>(U.tillSales, payload),
  findSale: (number: string) => api.get<Sale>(U.tillSale(number)),
  returnItems: (payload: ReturnPayload) => api.post<Sale>(U.tillReturns, payload),
  logVoid: (variantId: number, quantity: number, valueCents: number, reason: string | null, approvalToken: string | null) =>
    api.post<null>(U.tillVoids, { variantId, quantity, valueCents, reason, approvalToken }),
  parked: () => api.get<ParkedSale[]>(U.tillParked),
  park: (label: string | null, lines: ParkedLine[], totalCents: number) => api.post<ParkedSale>(U.tillParked, { label, lines, totalCents }),
  recall: (id: number) => api.post<ParkedSale>(U.tillRecall(id)),

  // Back office
  sales: (filters: { from?: string; to?: string; search?: string; page?: number } = {}) => api.get<Paginated<Sale>>(U.sales, filters),
  shifts: (page = 1, status?: "to_review") => api.get<Paginated<ShiftRow>>(U.shiftHistory, { page, status }),
  shiftDetail: (id: number) => api.get<ShiftDetail>(U.shiftDetail(id)),
  reviewShift: (id: number, note: string | null) => api.post<Shift>(U.reviewShift(id), { note }),
  openBottles: (status: "open" | "closed", page = 1) => api.get<Paginated<OpenBottle>>(U.openBottles, { status, page }),
  writeOffBottle: (id: number, reason: string) => api.post<{ id: number; status: string }>(U.writeOffBottle(id), { reason }),
};
