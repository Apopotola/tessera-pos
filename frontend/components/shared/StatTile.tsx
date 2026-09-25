import { Paper, Text } from "@mantine/core";
import type { ReactNode } from "react";
import { brand } from "@/app/theme";

interface StatTileProps {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  /** "dark" inside navy panels, "light" on cream pages. */
  tone?: "dark" | "light";
  /** Amber tile for the figure that needs attention (e.g. a stock gap). */
  highlight?: boolean;
  onClick?: () => void;
}

/** KPI tile in the brand style: small label, large display-font figure. */
export default function StatTile({ label, value, hint, tone = "light", highlight = false, onClick }: StatTileProps) {
  const background = highlight ? brand.amber : tone === "dark" ? brand.navy : "white";
  const color = highlight ? brand.navy : tone === "dark" ? "white" : brand.navy;
  const muted = highlight ? "rgba(28,29,46,0.72)" : tone === "dark" ? "rgba(255,255,255,0.6)" : "var(--mantine-color-dimmed)";

  return (
    <Paper
      p="md"
      radius="lg"
      withBorder={tone === "light" && !highlight}
      onClick={onClick}
      style={{ background, cursor: onClick ? "pointer" : undefined }}
      role={onClick ? "button" : undefined}
    >
      <Text size="xs" c={muted} fw={500}>
        {label}
      </Text>
      <Text className="tessera-display" fz={28} c={color} mt={4}>
        {value}
      </Text>
      {hint && (
        <Text size="xs" c={muted} mt={4}>
          {hint}
        </Text>
      )}
    </Paper>
  );
}
