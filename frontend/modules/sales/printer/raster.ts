/**
 * The receipt logo as something a thermal printer can print: one bit per dot, black or white,
 * sent with the ESC/POS raster command (GS v 0). Pure functions, tested without a browser.
 */

/** Printable dots across the paper at 203 dpi. */
export const PAPER_DOTS = { 58: 384, 80: 576 } as const;

/** Rows per raster command: some printers drop a single very tall image. */
const BAND_ROWS = 64;

export interface Bitmap {
  /** Bytes per row (each byte is 8 dots, most significant bit on the left). */
  widthBytes: number;
  height: number;
  /** widthBytes × height, 1 = black. */
  bits: Uint8Array;
}

/**
 * RGBA pixels → black and white with Floyd–Steinberg dithering, so a coloured or shaded logo keeps
 * its shape. Transparent parts count as white paper.
 */
export function toBitmap(rgba: Uint8ClampedArray, width: number, height: number, threshold = 128): Bitmap {
  const lum = new Float32Array(width * height);
  for (let i = 0; i < width * height; i++) {
    const alpha = rgba[i * 4 + 3] / 255;
    const grey = 0.299 * rgba[i * 4] + 0.587 * rgba[i * 4 + 1] + 0.114 * rgba[i * 4 + 2];
    lum[i] = grey * alpha + 255 * (1 - alpha);
  }

  const widthBytes = Math.ceil(width / 8);
  const bits = new Uint8Array(widthBytes * height);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const i = y * width + x;
      const old = lum[i];
      const black = old < threshold;
      const error = old - (black ? 0 : 255);
      if (black) bits[y * widthBytes + (x >> 3)] |= 0x80 >> (x & 7);
      if (x + 1 < width) lum[i + 1] += (error * 7) / 16;
      if (y + 1 < height) {
        if (x > 0) lum[i + width - 1] += (error * 3) / 16;
        lum[i + width] += (error * 5) / 16;
        if (x + 1 < width) lum[i + width + 1] += error / 16;
      }
    }
  }
  return { widthBytes, height, bits };
}

/** Raster commands for a bitmap, in bands. Centring is up to the alignment set before it. */
export function rasterCommand(bitmap: Bitmap): Uint8Array {
  const parts: number[] = [];
  for (let top = 0; top < bitmap.height; top += BAND_ROWS) {
    const rows = Math.min(BAND_ROWS, bitmap.height - top);
    parts.push(0x1d, 0x76, 0x30, 0x00, bitmap.widthBytes & 0xff, bitmap.widthBytes >> 8, rows & 0xff, rows >> 8);
    const start = top * bitmap.widthBytes;
    for (const b of bitmap.bits.subarray(start, start + rows * bitmap.widthBytes)) parts.push(b);
  }
  return Uint8Array.from(parts);
}

/** The logo's printed size: at most 3/4 of the paper wide and 160 dots tall, never more than twice its size. */
export function fitLogo(width: number, height: number, paperDots: number): { width: number; height: number } {
  const scale = Math.min((paperDots * 0.75) / width, 160 / height, 2);
  return { width: Math.max(8, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}
