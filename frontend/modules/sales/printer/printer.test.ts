import { expect, test } from "@playwright/test";
import { amount, receiptBytes, testPageBytes } from "@/modules/sales/printer/documents";
import { EscPos, columns, toAscii, wrap } from "@/modules/sales/printer/escpos";
import { PAPER_DOTS, fitLogo, rasterCommand, toBitmap } from "@/modules/sales/printer/raster";
import { rawbtUrl } from "@/modules/sales/printer/transports";
import type { ReceiptSettings, Sale } from "@/types/sales";

/** The printable text of an ESC/POS job, one string per line (commands and the QR payload stripped). */
function printed(bytes: Uint8Array): string[] {
  const text: string[] = [];
  let i = 0;
  while (i < bytes.length) {
    const b = bytes[i];
    if (b === 0x1d && bytes[i + 1] === 0x28 && bytes[i + 2] === 0x6b) {
      i += 5 + (bytes[i + 3] | (bytes[i + 4] << 8)); // GS ( k pL pH + data
      continue;
    }
    if (b === 0x1b || b === 0x1d) {
      const command = bytes[i + 1];
      i += b === 0x1d && command === 0x56 ? 4 : b === 0x1b && command === 0x70 ? 5 : command === 0x40 ? 2 : 3;
      continue;
    }
    text.push(String.fromCharCode(b));
    i += 1;
  }
  return text.join("").split("\n");
}

const has = (bytes: Uint8Array, sequence: number[]) => bytes.some((_, i) => sequence.every((v, j) => bytes[i + j] === v));

const receipt: ReceiptSettings = {
  headerLines: ["Moi Avenue, Nairobi"],
  footerLines: ["Thank you for shopping with us."],
  show: ["cashier"],
  paperSize: "58mm",
  printBehaviour: "always",
  returnWindowDays: 7,
  licenceLines: ["Liquor licence No. NCC/123"],
  logo: null,
};

const sale = {
  id: 1,
  number: "INV-000042",
  completedAt: "2026-10-16T17:30:00+03:00",
  capturedOffline: false,
  status: "completed",
  etimsStatus: "signed",
  etims: { status: "signed", invoiceNumber: "CU0001/42", signature: "ABCD-EFGH-IJKL-MNOP", internalData: "XYZ123", qrPayload: "https://etims.kra.go.ke/verify?x=1", scuId: "KRACU0100001", signedAt: null },
  business: { name: "Tessera Wines & Spirits", kraPin: "P051234567X" },
  branch: { id: 1, code: "MAIN", name: "Main branch" },
  till: { id: 1, name: "Till 1" },
  cashier: { id: 3, name: "Otieno Kamau" },
  customerPin: null,
  customer: null,
  subtotalCents: 520000,
  discountCents: 0,
  totalCents: 520000,
  roundingCents: 0,
  vatCents: 71724,
  returnedCents: 0,
  costCents: null,
  grossProfitCents: null,
  lines: [
    {
      id: 1, variant: { id: 9, displayName: "Johnnie Walker Black Label 750 ml", sku: "JW-BL-750" }, unit: "bottle", totMl: null, quantity: 1,
      listPriceCents: 480000, unitPriceCents: 480000, discountCents: 0, promotionDiscountCents: 0, promotion: null, lineTotalCents: 480000,
      vatCents: 66207, taxRatePercent: 16, returnedQuantity: 0, approvedBy: null,
    },
    {
      id: 2, variant: { id: 10, displayName: "Tonic water 500 ml", sku: "TONIC" }, unit: "bottle", totMl: null, quantity: 2,
      listPriceCents: 20000, unitPriceCents: 20000, discountCents: 0, promotionDiscountCents: 0, promotion: null, lineTotalCents: 40000,
      vatCents: 5517, taxRatePercent: 16, returnedQuantity: 0, approvedBy: null,
    },
  ],
  tenders: [{ method: "cash", amountCents: 520000, tenderedCents: 600000, changeCents: 80000, reference: null, cardLast4: null, status: "confirmed" }],
  returns: [],
  receipt,
} as Sale;

test("text is plain ASCII the printer can show", () => {
  expect(toAscii("Café – 2 × KES 1 000…")).toBe("Cafe - 2 x KES 1 000...");
  expect(toAscii("☺")).toBe("?");
});

test("long words and labels wrap to the paper", () => {
  expect(wrap("a".repeat(70), 32)).toEqual(["a".repeat(32), "a".repeat(32), "a".repeat(6)]);
  expect(columns("Total", "1,234.00", 20)).toEqual(["Total       1,234.00"]);
  for (const line of columns("Johnnie Walker Black Label 750 ml", "4,800.00", 32)) expect(line.length).toBeLessThanOrEqual(32);
});

test("money prints as plain figures", () => {
  expect(amount(520000)).toBe("5,200.00");
  expect(amount(-1550)).toBe("-15.50");
  expect(amount(5)).toBe("0.05");
});

test("a 58 mm receipt fits 32 columns and matches the screen receipt", () => {
  const bytes = receiptBytes(sale);
  const lines = printed(bytes);
  for (const line of lines) expect(line.length).toBeLessThanOrEqual(32);
  const text = lines.join("\n");
  expect(text).toContain("INV-000042");
  expect(text).toContain("Liquor licence No. NCC/123");
  expect(text).toContain("Served by");
  expect(text).toContain("KES 5,200.00");
  expect(text).toContain("Change");
  expect(text).toContain("800.00");
  expect(text).toContain("CU invoice no.");
  expect(text).not.toContain("*** COPY ***");
  // eTIMS QR printed by the printer, then the cut.
  expect(has(bytes, [0x1d, 0x28, 0x6b])).toBe(true);
  expect(has(bytes, [0x1d, 0x56, 0x42])).toBe(true);
});

test("80 mm paper uses 48 columns; a reprint is marked as a copy", () => {
  const lines = printed(receiptBytes({ ...sale, receipt: { ...receipt, paperSize: "80mm" } }, { copy: true }));
  expect(lines.some((l) => l.length > 32)).toBe(true);
  for (const line of lines) expect(line.length).toBeLessThanOrEqual(48);
  expect(lines).toContain("*** COPY ***");
});

test("a receipt never opens the cash drawer by itself", () => {
  expect(has(receiptBytes(sale), [0x1b, 0x70])).toBe(false);
  expect(has(new EscPos(48).openDrawer().build(), [0x1b, 0x70, 0x00])).toBe(true);
});

test("offline receipts say so", () => {
  const text = printed(receiptBytes({ ...sale, pendingSync: true, etims: null, etimsStatus: "pending" })).join(" ");
  expect(text).toContain("RECORDED OFFLINE");
});

test("the test page proves the width and the cutter", () => {
  const lines = printed(testPageBytes("Tessera", "Main branch", receipt, null));
  expect(lines).toContain("PRINTER TEST");
  expect(lines).toContain("12345678901234567890123456789012");
});

test("the logo is dithered to black and white and fits the paper", () => {
  // 8 × 2 pixels: black on the left half, transparent (white paper) on the right.
  const rgba = new Uint8ClampedArray(8 * 2 * 4);
  for (let y = 0; y < 2; y++) for (let x = 0; x < 4; x++) rgba.set([0, 0, 0, 255], (y * 8 + x) * 4);
  const bitmap = toBitmap(rgba, 8, 2);
  expect(Array.from(bitmap.bits)).toEqual([0xf0, 0xf0]);
  expect(Array.from(rasterCommand(bitmap))).toEqual([0x1d, 0x76, 0x30, 0x00, 1, 0, 2, 0, 0xf0, 0xf0]);

  const size = fitLogo(1200, 400, PAPER_DOTS[58]);
  expect(size.width).toBeLessThanOrEqual(PAPER_DOTS[58] * 0.75);
  expect(size.height).toBeLessThanOrEqual(160);
});

test("RawBT gets the bytes as an Android intent", () => {
  expect(rawbtUrl(Uint8Array.from([0x1b, 0x40]))).toBe("intent:base64,G0A=#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;");
});
