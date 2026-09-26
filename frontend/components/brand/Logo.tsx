"use client";

import { Group, Text } from "@mantine/core";
import { brand } from "@/app/theme";
import { useBrand } from "@/components/brand/useBrand";

interface LogoProps {
  /** Mark size in px. */
  size?: number;
  /** "light" for dark backgrounds, "dark" for light ones. */
  tone?: "light" | "dark";
  withWordmark?: boolean;
}

/** Four-tile tessera mark: purple, amber, lilac and white/navy squares (Tessera's own mark). */
export function TesseraMark({ size = 28, tone = "light" }: Pick<LogoProps, "size" | "tone">) {
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

/** The client's logo when set (Settings → Branding), otherwise the tessera mark. */
export function LogoMark({ size = 28, tone = "light" }: Pick<LogoProps, "size" | "tone">) {
  const { logo, name } = useBrand();
  // eslint-disable-next-line @next/next/no-img-element -- uploaded logo served by the API, any aspect ratio
  return logo ? <img src={logo} alt={name} style={{ height: size, width: "auto", maxWidth: size * 4, display: "block" }} /> : <TesseraMark size={size} tone={tone} />;
}

/** Brand block for headers and login: the client's logo and name, or the tessera wordmark. */
export default function Logo({ size = 28, tone = "light", withWordmark = true }: LogoProps) {
  const { name, custom } = useBrand();

  return (
    <Group gap={10} wrap="nowrap" aria-label={name}>
      <LogoMark size={size} tone={tone} />
      {withWordmark && (
        <Text
          component="span"
          fw={800}
          fz={custom ? size * 0.66 : size * 0.82}
          lh={1.1}
          c={tone === "light" ? "white" : brand.navy}
          style={{ letterSpacing: "-0.02em", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 260 }}
        >
          {custom ? name : "tessera"}
        </Text>
      )}
    </Group>
  );
}
