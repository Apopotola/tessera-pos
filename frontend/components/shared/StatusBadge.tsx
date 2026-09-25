import { Badge } from "@mantine/core";

interface StatusBadgeProps {
  active: boolean;
  activeLabel?: string;
  inactiveLabel?: string;
}

/** Consistent on/off status pill (Active / Inactive, Connected / Not connected, Set / Not set…). */
export default function StatusBadge({ active, activeLabel = "Active", inactiveLabel = "Inactive" }: StatusBadgeProps) {
  return (
    <Badge color={active ? "green" : "gray"} variant="light" radius="sm">
      {active ? activeLabel : inactiveLabel}
    </Badge>
  );
}
