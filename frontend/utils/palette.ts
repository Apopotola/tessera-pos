import type { MantineColorsTuple } from "@mantine/core";

/** Ten Mantine shades from one brand colour, with the colour itself at `baseIndex`. */
export function shadesFrom(hex: string, baseIndex = 7): MantineColorsTuple {
  const rgb = parse(hex);
  const shades = Array.from({ length: 10 }, (_, i) => {
    if (i === baseIndex) return toHex(rgb);
    if (i < baseIndex) {
      // Lighter: mix towards white (shade 0 is almost white).
      const t = 1 - i / baseIndex;
      return toHex(rgb.map((c) => c + (255 - c) * t * 0.92) as Rgb);
    }
    // Darker: mix towards black.
    const t = (i - baseIndex) / (10 - baseIndex);
    return toHex(rgb.map((c) => c * (1 - t * 0.55)) as Rgb);
  });
  return shades as unknown as MantineColorsTuple;
}

type Rgb = [number, number, number];

function parse(hex: string): Rgb {
  const h = hex.replace("#", "");
  return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as Rgb;
}

function toHex(rgb: Rgb): string {
  return `#${rgb.map((c) => Math.round(Math.max(0, Math.min(255, c))).toString(16).padStart(2, "0")).join("")}`;
}

/** WCAG contrast ratio (4.5 = readable normal text). Mirrors Modules\Settings\Support\Contrast. */
export function contrastRatio(a: string, b: string): number {
  const lum = (hex: string) => {
    const [r, g, bl] = parse(hex).map((c) => {
      const v = c / 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
  };
  const [x, y] = [lum(a), lum(b)];
  return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
}
