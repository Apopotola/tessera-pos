import type { Promotion } from "@/types/catalogue";
import { formatKes } from "@/utils/money";

/** "10% off", "KES 200 off each" */
export function promotionOffer(p: Pick<Promotion, "discountType" | "discountValue" | "minQuantity" | "unit">): string {
  const off = p.discountType === "percent" ? `${p.discountValue / 100}% off` : `${formatKes(p.discountValue)} off each`;
  const unit = p.unit === "tot" ? " tots" : p.unit === "bottle" ? " bottles" : "";
  return p.minQuantity > 1 ? `Buy ${p.minQuantity}${unit || " or more"}, ${off}` : `${off}${unit ? ` (${unit.trim()})` : ""}`;
}
