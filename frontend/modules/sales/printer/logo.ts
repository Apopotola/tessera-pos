import { PAPER_DOTS, fitLogo, rasterCommand, toBitmap } from "@/modules/sales/printer/raster";

/**
 * The receipt logo (Settings → Branding → Receipt logo) as printer commands, made once per logo and
 * paper width and kept on the till (browser storage), so receipts still carry it when the till is offline.
 */

const PREFIX = "tessera:receipt-logo:";
const memory = new Map<string, Uint8Array>();

function toBase64(bytes: Uint8Array): string {
  let binary = "";
  bytes.forEach((b) => (binary += String.fromCharCode(b)));
  return btoa(binary);
}

function fromBase64(text: string): Uint8Array {
  return Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
}

export async function logoRaster(url: string | null, paperWidth: 58 | 80): Promise<Uint8Array | null> {
  if (!url) return null;
  const dots = PAPER_DOTS[paperWidth];
  const key = `${PREFIX}${dots}:${url}`;

  const cached = memory.get(key);
  if (cached) return cached;
  try {
    const stored = localStorage.getItem(key);
    if (stored) {
      const bytes = fromBase64(stored);
      memory.set(key, bytes);
      return bytes;
    }
  } catch {
    // Storage unavailable: make it again below.
  }

  try {
    // The logo is a public branding file; the API sends CORS headers, so the canvas can read it.
    const image = await createImageBitmap(await (await fetch(url, { mode: "cors" })).blob());
    const size = fitLogo(image.width, image.height, dots);
    const canvas = document.createElement("canvas");
    canvas.width = size.width;
    canvas.height = size.height;
    const context = canvas.getContext("2d");
    if (!context) return null;
    context.drawImage(image, 0, 0, size.width, size.height);
    const bytes = rasterCommand(toBitmap(context.getImageData(0, 0, size.width, size.height).data, size.width, size.height));

    memory.set(key, bytes);
    try {
      // Only the current logo is kept.
      Object.keys(localStorage)
        .filter((k) => k.startsWith(PREFIX) && k !== key)
        .forEach((k) => localStorage.removeItem(k));
      localStorage.setItem(key, toBase64(bytes));
    } catch {
      // Storage full or blocked: it is still kept for this visit.
    }
    return bytes;
  } catch {
    // Offline before the logo was ever loaded, or not an image: the receipt prints without it.
    return null;
  }
}
