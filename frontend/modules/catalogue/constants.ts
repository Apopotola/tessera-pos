import type { Container, PriceStatus, PriceTier } from "@/types/catalogue";

export const CONTAINER_OPTIONS: { value: Container; label: string }[] = [
  { value: "bottle", label: "Bottle" },
  { value: "can", label: "Can" },
  { value: "keg", label: "Keg" },
  { value: "box", label: "Box / cask" },
  { value: "pouch", label: "Pouch" },
  { value: "other", label: "Other" },
];

export const TIER_LABELS: Record<PriceTier, string> = {
  retail: "Retail",
  wholesale: "Wholesale",
};

export const PRICE_STATUS_COLORS: Record<PriceStatus, string> = {
  pending: "yellow",
  approved: "green",
  rejected: "red",
};
