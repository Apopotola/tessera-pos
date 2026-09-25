import { Group, Text } from "@mantine/core";
import { brand } from "@/app/theme";

interface LogoProps {
  /** Mark size in px. */
  size?: number;
  /** "light" for dark backgrounds, "dark" for light ones. */
  tone?: "light" | "dark";
  withWordmark?: boolean;
}

/** Four-tile tessera mark: purple, amber, lilac and white/navy squares. */
export function LogoMark({ size = 28, tone = "light" }: Pick<LogoProps, "size" | "tone">) {
  const fourth = tone === "light" ? "#ffffff" : brand.navy;

  return (
    <svg width={size} height={size} viewBox="0 0 28 28" aria-hidden="true">
      <rect x="0" y="0" width="12.5" height="12.5" rx="3" fill={brand.lilac} />
      <rect x="15.5" y="0" width="12.5" height="12.5" rx="3" fill={brand.amber} />
      <rect x="0" y="15.5" width="12.5" height="12.5" rx="3" fill={brand.lilac} />
      <rect x="15.5" y="15.5" width="12.5" height="12.5" rx="3" fill={fourth} />
    </svg>
  );
}

export default function Logo({ size = 28, tone = "light", withWordmark = true }: LogoProps) {
  return (
    <Group gap={10} wrap="nowrap" aria-label="Tessera">
      <LogoMark size={size} tone={tone} />
      {withWordmark && (
        <Text component="span" fw={800} fz={size * 0.82} lh={1} c={tone === "light" ? "white" : brand.navy} style={{ letterSpacing: "-0.02em" }}>
          tessera
        </Text>
      )}
    </Group>
  );
}
