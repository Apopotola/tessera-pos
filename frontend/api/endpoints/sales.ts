import { api } from "@/api/client";
import { SALES_URLS } from "@/api/urls";
import type { Shift } from "@/types/till";

/** Till shift endpoints (need a signed-in user on a paired till). */
export const salesApi = {
  currentShift: () => api.get<Shift | null>(SALES_URLS.currentShift),
  startShift: (openingFloatCents?: number) => api.post<Shift>(SALES_URLS.shifts, openingFloatCents == null ? {} : { openingFloatCents }),
  closeShift: (id: number, countedCashCents: number, note: string | null) =>
    api.post<Shift>(SALES_URLS.closeShift(id), { countedCashCents, note }),
};
