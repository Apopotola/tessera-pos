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
  // Long figures shrink to the tile width; short ones ("4", "1 / 3") stay large.
  const length = typeof value === "string" || typeof value === "number" ? String(value).length : 6;
  const fontSize = `clamp(16px, ${Math.min(30, Math.round(175 / Math.max(length, 1)))}cqi, 28px)`;
  const muted = highlight ? "rgba(28,29,46,0.72)" : tone === "dark" ? "rgba(255,255,255,0.6)" : "var(--mantine-color-dimmed)";

  return (
    <Paper
      p="md"
      radius="lg"
      withBorder={tone === "light" && !highlight}
      onClick={onClick}
      // The figure scales with the tile width so long amounts (Ksh 439,670.00) never get cut off.
      style={{ background, cursor: onClick ? "pointer" : undefined, containerType: "inline-size" }}
      role={onClick ? "button" : undefined}
    >
      <Text size="xs" c={muted} fw={500}>
        {label}
      </Text>
      <Text className="tessera-display" fz={fontSize} c={color} mt={4} style={{ whiteSpace: "nowrap" }}>
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
