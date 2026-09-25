import { Badge } from "@mantine/core";

/** Workflow status pill (pending, in transit, approved…) with brand colours. */
export default function DocStatusBadge({ label, color }: { label: string; color: string }) {
  return (
    <Badge color={color} variant="light" radius="sm">
      {label}
    </Badge>
  );
}
