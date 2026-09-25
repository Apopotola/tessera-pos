import type { AdjustmentStage, AdjustmentType, CountStatus, DocumentStatus, MovementType, TransferStatus } from "@/types/inventory";

export const ADJUSTMENT_TYPES: { value: AdjustmentType; label: string; hint: string }[] = [
  { value: "breakage", label: "Breakage", hint: "Broken bottles" },
  { value: "damaged", label: "Damaged", hint: "Unsellable, e.g. damaged on arrival" },
  { value: "expired", label: "Expired", hint: "Past its date" },
  { value: "missing", label: "Missing", hint: "Unexplained loss" },
  { value: "found", label: "Found", hint: "Stock that turned up" },
  { value: "opening", label: "Opening stock", hint: "Go-live stock with cost" },
];

export const ADJUSTMENT_LABEL: Record<AdjustmentType, string> = Object.fromEntries(ADJUSTMENT_TYPES.map((t) => [t.value, t.label])) as Record<
  AdjustmentType,
  string
>;

export const STAGES: { value: AdjustmentStage; label: string }[] = [
  { value: "receiving", label: "Receiving" },
  { value: "storage", label: "In storage" },
  { value: "shelf", label: "On the shelf" },
  { value: "sale", label: "During sale" },
  { value: "transit", label: "In transit" },
];

export const MOVEMENT_LABEL: Record<MovementType, string> = {
  opening: "Opening stock",
  found: "Found",
  breakage: "Breakage",
  expired: "Expired",
  damaged: "Damaged",
  missing: "Missing",
  transfer_dispatch: "Transfer out",
  transfer_receive: "Transfer in",
  count_variance: "Count variance",
};

export const DOCUMENT_STATUS_COLOR: Record<DocumentStatus | TransferStatus | CountStatus, string> = {
  pending: "yellow",
  requested: "yellow",
  counting: "blue",
  submitted: "yellow",
  approved: "tessera",
  in_transit: "blue",
  received: "green",
  rejected: "red",
  cancelled: "gray",
};

export const STATUS_LABEL: Record<DocumentStatus | TransferStatus | CountStatus, string> = {
  pending: "Waiting approval",
  requested: "Requested",
  counting: "Counting",
  submitted: "Waiting approval",
  approved: "Approved",
  in_transit: "In transit",
  received: "Received",
  rejected: "Rejected",
  cancelled: "Cancelled",
};
