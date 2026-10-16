import dayjs from "dayjs";
import { EscPos, type PaperColumns } from "@/modules/sales/printer/escpos";
import { type ReceiptSettings, type Sale, TENDER_LABELS } from "@/types/sales";

/**
 * The till receipt and the printer test page as ESC/POS bytes. Same content and order as the on-screen
 * receipt (modules/sales/components/Receipt.tsx), so paper and screen agree.
 */

export function paperColumns(paperSize: ReceiptSettings["paperSize"]): PaperColumns {
  return paperSize === "58mm" ? 32 : 48;
}

export function paperWidthMm(paperSize: ReceiptSettings["paperSize"]): 58 | 80 {
  return paperSize === "58mm" ? 58 : 80;
}

/** 123456 → "1,234.56": plain figures, no currency symbol (it would not fit the columns). */
export function amount(cents: number): string {
  const sign = cents < 0 ? "-" : "";
  const abs = Math.abs(Math.round(cents));
  const whole = Math.floor(abs / 100)
    .toString()
    .replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${sign}${whole}.${String(abs % 100).padStart(2, "0")}`;
}

function header(p: EscPos, business: string, branch: string, receipt: ReceiptSettings, logo: Uint8Array | null): void {
  p.align("center");
  if (logo && receipt.show.includes("logo")) p.image(logo);
  p.bold(true).large(true).paragraph(business, true).large(false).bold(false);
  p.paragraph(branch);
  receipt.headerLines.forEach((line) => p.paragraph(line));
  (receipt.licenceLines ?? []).forEach((line) => p.paragraph(line));
}

export interface ReceiptPrintOptions {
  copy?: boolean;
  /** Raster commands for the receipt logo (logo.ts). */
  logo?: Uint8Array | null;
}

export function receiptBytes(sale: Sale, options: ReceiptPrintOptions = {}): Uint8Array {
  const { copy = false, logo = null } = options;
  const { receipt } = sale;
  const show = (what: ReceiptSettings["show"][number]) => receipt.show.includes(what);
  const p = new EscPos(paperColumns(receipt.paperSize));

  header(p, sale.business.name, sale.branch.name, receipt, logo);
  if (sale.business.kraPin) p.line(`PIN: ${sale.business.kraPin}`);
  if (copy) p.bold(true).line("*** COPY ***").bold(false);

  p.align("left").rule();
  p.row("Receipt", sale.number);
  p.row(dayjs(sale.completedAt).format("DD/MM/YYYY HH:mm"), sale.till.name);
  if (show("cashier")) p.row("Served by", sale.cashier.name);
  if (show("customer") && sale.customer) p.row("Customer", sale.customer.name);
  if (sale.customerPin) p.row("Customer PIN", sale.customerPin);
  p.rule();

  for (const line of sale.lines) {
    p.paragraph(line.unit === "tot" ? `${line.variant.displayName} (tot ${line.totMl}ml)` : line.variant.displayName);
    p.row(`${line.quantity} x ${amount(line.unitPriceCents)}`, amount(line.quantity * line.unitPriceCents));
    if (line.promotionDiscountCents > 0) p.row(line.promotion?.name ?? "Promotion", `-${amount(line.promotionDiscountCents)}`, 2);
    if (line.discountCents > 0) p.row("Discount", `-${amount(line.discountCents)}`, 2);
  }

  p.rule();
  if (sale.discountCents > 0) p.row("You saved", amount(sale.discountCents));
  p.bold(true).row("TOTAL", `KES ${amount(sale.totalCents)}`).bold(false);
  for (const t of sale.tenders.filter((t) => t.amountCents > 0)) {
    p.row(`${TENDER_LABELS[t.method]}${t.reference ? ` ${t.reference}` : ""}`, amount(t.method === "cash" ? (t.tenderedCents ?? t.amountCents) : t.amountCents));
  }
  if (sale.roundingCents !== 0) p.row("Cash rounding", amount(sale.roundingCents));
  const change = sale.tenders.find((t) => t.method === "cash" && t.amountCents >= 0)?.changeCents ?? 0;
  if (change > 0) p.bold(true).row("Change", amount(change)).bold(false);
  p.row("VAT included", amount(sale.vatCents));
  if (sale.returnedCents > 0) p.row("Refunded", `-${amount(sale.returnedCents)}`);

  p.rule().align("center");
  if (sale.pendingSync) {
    p.bold(true).paragraph("RECORDED OFFLINE - provisional number. The official receipt number and eTIMS invoice follow when the till reconnects.").bold(false);
  } else if (sale.etims?.status === "signed") {
    p.align("left");
    p.row("CU invoice no.", sale.etims.invoiceNumber ?? "");
    p.row("SCU ID", sale.etims.scuId ?? "");
    p.paragraph(`Internal data: ${sale.etims.internalData ?? ""}`);
    p.paragraph(`Signature: ${sale.etims.signature ?? ""}`);
    p.align("center");
    if (sale.etims.qrPayload) p.qr(sale.etims.qrPayload, receipt.paperSize === "58mm" ? 4 : 5);
    if (sale.etims.qrPayload?.includes("mock=1")) p.bold(true).line("DEMO eTIMS - NOT A KRA INVOICE").bold(false);
  } else if (sale.etimsStatus !== "not_required") {
    p.paragraph("eTIMS invoice pending - not yet signed by KRA");
  }
  receipt.footerLines.forEach((line) => p.paragraph(line));
  if (show("return_policy")) p.paragraph(`Sealed bottles can be returned within ${receipt.returnWindowDays} days with this receipt.`);

  return p.feed(4).cut().build();
}

/** Proves the connection, the paper width, the logo and the cutter. */
export function testPageBytes(business: string, branch: string, receipt: ReceiptSettings, logo: Uint8Array | null): Uint8Array {
  const p = new EscPos(paperColumns(receipt.paperSize));
  header(p, business, branch, receipt, logo);
  p.bold(true).line("PRINTER TEST").bold(false).align("left").rule();
  p.line(`${p.width} characters per line:`);
  p.line("1234567890".repeat(5).slice(0, p.width));
  p.row("TOTAL", "KES 1,721.00");
  p.rule("=");
  p.align("center").line(dayjs().format("DD/MM/YYYY HH:mm"));
  return p.feed(4).cut().build();
}
