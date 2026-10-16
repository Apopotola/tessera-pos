import { Badge } from "@mantine/core";
import type { StockStatus } from "@/types/inventory";

const STATUS: Record<StockStatus, { label: string; color: string }> = {
  in_stock: { label: "In stock", color: "green" },
  low: { label: "Low", color: "amber" },
  out: { label: "Out of stock", color: "red" },
};

/** In stock / Low / Out of stock pill for the stock screens. */
export default function StockStatusBadge({ status }: { status: StockStatus }) {
  return (
    <Badge color={STATUS[status].color} variant="light" radius="sm">
      {STATUS[status].label}
    </Badge>
  );
}
