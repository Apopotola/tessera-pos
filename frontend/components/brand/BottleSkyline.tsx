import { brand } from "@/app/theme";

type Shape = "wine" | "spirit" | "stubby";

interface Bottle {
  shape: Shape;
  color: string;
  height: number;
}

const BOTTLES: Bottle[] = [
  { shape: "wine", color: brand.purple, height: 205 },
  { shape: "spirit", color: brand.amber, height: 165 },
  { shape: "wine", color: brand.lilac, height: 225 },
  { shape: "stubby", color: brand.slate, height: 145 },
  { shape: "wine", color: brand.purple, height: 195 },
  { shape: "spirit", color: brand.amber, height: 175 },
  { shape: "wine", color: brand.slate, height: 215 },
  { shape: "stubby", color: brand.lilac, height: 155 },
  { shape: "wine", color: brand.purple, height: 235 },
];

const WIDTH = 36;
const GAP = 30;

/** One bottle silhouette standing on y = baseline. */
function bottlePath(x: number, baseline: number, { shape, height }: Bottle): string {
  const top = baseline - height;
  const neckW = shape === "stubby" ? 16 : 10;
  const neckX = x + (WIDTH - neckW) / 2;
  const neckH = shape === "wine" ? height * 0.3 : shape === "spirit" ? height * 0.16 : height * 0.14;
  const shoulder = shape === "wine" ? 22 : 8;
  const bodyTop = top + neckH;
  const r = 6;

  return [
    `M${neckX} ${top}`,
    `h${neckW}`,
    `v${neckH}`,
    // right shoulder curves out to full width
    `C${neckX + neckW} ${bodyTop + shoulder * 0.4} ${x + WIDTH} ${bodyTop + shoulder * 0.5} ${x + WIDTH} ${bodyTop + shoulder}`,
    `V${baseline - r}`,
    `q0 ${r} -${r} ${r}`,
    `H${x + r}`,
    `q-${r} 0 -${r} -${r}`,
    `V${bodyTop + shoulder}`,
    `C${x} ${bodyTop + shoulder * 0.5} ${neckX} ${bodyTop + shoulder * 0.4} ${neckX} ${bodyTop}`,
    "Z",
  ].join(" ");
}

/** Decorative row of bottles in brand colours, sitting on a shelf line. */
export default function BottleSkyline({ height = 240, withShelf = true }: { height?: number; withShelf?: boolean }) {
  const baseline = 240;
  const width = BOTTLES.length * WIDTH + (BOTTLES.length - 1) * GAP;

  return (
    <svg viewBox={`0 0 ${width} ${baseline + 6}`} height={height} width="100%" preserveAspectRatio="xMinYMax meet" aria-hidden="true" style={{ display: "block" }}>
      {BOTTLES.map((bottle, i) => (
        <path key={i} d={bottlePath(i * (WIDTH + GAP), baseline, bottle)} fill={bottle.color} />
      ))}
      {withShelf && <rect x={-40} y={baseline} width={width + 80} height={4} fill={brand.slate} />}
    </svg>
  );
}
