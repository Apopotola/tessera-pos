/**
 * A small ESC/POS command writer for 58 mm (32 columns) and 80 mm (48 columns) thermal printers.
 * Only commands every ESC/POS printer understands are used: initialise, align, bold, double size,
 * raster images (the logo), QR code (the eTIMS QR), feed, partial cut and the cash drawer pulse.
 * Text is sent as plain ASCII, so any code page works.
 */

const ESC = 0x1b;
const GS = 0x1d;
const LF = 0x0a;

export type Align = "left" | "center" | "right";
export type PaperColumns = 32 | 48;

/** Characters receipts use that printer code pages lack, and the ASCII stand-in for each. */
const REPLACEMENTS: Record<string, string> = {
  "−": "-",
  "–": "-",
  "—": "-",
  "·": "-",
  "×": "x",
  "…": "...",
  "‘": "'",
  "’": "'",
  "“": '"',
  "”": '"',
  " ": " ",
  " ": " ",
};

/** Plain printable ASCII: known symbols swapped, accents dropped (é → e), anything else becomes "?". */
export function toAscii(text: string): string {
  return [...text.normalize("NFD")]
    .map((ch) => REPLACEMENTS[ch] ?? ch)
    .join("")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^\x20-\x7e\n]/g, "?");
}

/** Lines of at most `width` characters, broken at spaces where possible. */
export function wrap(text: string, width: number): string[] {
  const lines: string[] = [];
  for (const paragraph of toAscii(text).split("\n")) {
    let line = "";
    for (const word of paragraph.split(/\s+/).filter(Boolean)) {
      if (word.length > width) {
        // A word longer than the paper (an eTIMS signature) is cut into pieces.
        if (line) lines.push(line);
        const pieces = word.match(new RegExp(`.{1,${width}}`, "g")) ?? [];
        line = pieces.pop() ?? "";
        lines.push(...pieces);
        continue;
      }
      if (!line) line = word;
      else if (line.length + 1 + word.length <= width) line += ` ${word}`;
      else {
        lines.push(line);
        line = word;
      }
    }
    lines.push(line);
  }
  return lines;
}

/** Label on the left, value on the right; a long label wraps above the value. */
export function columns(left: string, right: string, width: number, indent = 0): string[] {
  const pad = " ".repeat(indent);
  const value = toAscii(right);
  const room = width - value.length - 1 - indent;
  const labels = wrap(left, Math.max(room, 8));
  const last = labels.pop() ?? "";

  if (last.length <= room) {
    return [...labels.map((l) => pad + l), pad + last + " ".repeat(width - indent - last.length - value.length) + value];
  }
  return [...labels.map((l) => pad + l), pad + last, " ".repeat(Math.max(width - value.length, 0)) + value];
}

export class EscPos {
  private readonly bytes: number[] = [];

  constructor(public readonly width: PaperColumns) {
    this.raw(ESC, 0x40); // initialise
  }

  private raw(...values: number[]): this {
    this.bytes.push(...values);
    return this;
  }

  align(align: Align): this {
    return this.raw(ESC, 0x61, align === "left" ? 0 : align === "center" ? 1 : 2);
  }

  bold(on: boolean): this {
    return this.raw(ESC, 0x45, on ? 1 : 0);
  }

  /** Double width and height for the business name and the total. */
  large(on: boolean): this {
    return this.raw(GS, 0x21, on ? 0x11 : 0x00);
  }

  text(value: string): this {
    for (const ch of toAscii(value)) this.bytes.push(ch.charCodeAt(0));
    return this;
  }

  line(value = ""): this {
    return this.text(value).raw(LF);
  }

  lines(values: string[]): this {
    values.forEach((v) => this.line(v));
    return this;
  }

  /** Wrapped to the paper (half as many characters fit when large). */
  paragraph(value: string, large = false): this {
    return this.lines(wrap(value, large ? this.width / 2 : this.width));
  }

  row(left: string, right: string, indent = 0): this {
    return this.lines(columns(left, right, this.width, indent));
  }

  rule(char = "-"): this {
    return this.line(char.repeat(this.width));
  }

  feed(lines: number): this {
    return this.raw(ESC, 0x64, lines);
  }

  /** Feed past the cutter, then a partial cut. */
  cut(): this {
    return this.raw(GS, 0x56, 0x42, 0x03);
  }

  /** Ready-made raster commands (see raster.ts), on their own line. */
  image(commands: Uint8Array): this {
    this.bytes.push(...commands);
    return this.raw(LF);
  }

  /** A QR code printed by the printer itself (GS ( k, model 2, error level M). */
  qr(data: string, moduleSize = 5): this {
    const payload = [...toAscii(data)].map((c) => c.charCodeAt(0));
    const length = payload.length + 3;
    return this.raw(GS, 0x28, 0x6b, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00) // model 2
      .raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x43, moduleSize) // dot size
      .raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x45, 0x31) // error correction M
      .raw(GS, 0x28, 0x6b, length & 0xff, length >> 8, 0x31, 0x50, 0x30, ...payload) // store
      .raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x51, 0x30) // print
      .raw(LF);
  }

  /** Pulse the drawer pin (pin 2, 50 ms on, 500 ms off) to open a cash drawer plugged into the printer. */
  openDrawer(): this {
    return this.raw(ESC, 0x70, 0x00, 0x19, 0xfa);
  }

  build(): Uint8Array {
    return Uint8Array.from(this.bytes);
  }
}
