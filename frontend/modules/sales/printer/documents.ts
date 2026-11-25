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

/** After each sale the till prints one receipt for the customer and one the cashier keeps. */
export type ReceiptCopyFor = "customer" | "cashier";

export const COPY_LABEL: Record<ReceiptCopyFor, string> = { customer: "CUSTOMER COPY", cashier: "CASHIER COPY" };

export interface ReceiptPrintOptions {
  copy?: boolean;
  copyFor?: ReceiptCopyFor;
  /** The customer's bill before paying (see Receipt.tsx): no number, payments or eTIMS. */
  bill?: boolean;
  /** Raster commands for the receipt logo (logo.ts). */
  logo?: Uint8Array | null;
}

export function receiptBytes(sale: Sale, options: ReceiptPrintOptions = {}): Uint8Array {
  const { copy = false, bill = false, logo = null, copyFor } = options;
  const { receipt } = sale;
  const show = (what: ReceiptSettings["show"][number]) => receipt.show.includes(what);
  const p = new EscPos(paperColumns(receipt.paperSize));
  const cashierCopy = copyFor === "cashier";

  p.align("center").line("* ~ * ~ * ~ * ~ *");
  header(p, sale.business.name, sale.branch.name, receipt, logo);
  if (sale.business.kraPin) p.line(`PIN: ${sale.business.kraPin}`);
  if (copyFor) p.feed(1).bold(true).invert(true).line(`  ${COPY_LABEL[copyFor]}  `).invert(false).bold(false);
  if (copy) p.bold(true).line("*** COPY ***").bold(false);
  if (bill) p.bold(true).large(true).paragraph("BILL - NOT PAID", true).large(false).bold(false);

  p.align("left").rule();
  if (!bill) p.row("Receipt", sale.number);
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

  p.rule("=");
  if (sale.discountCents > 0) p.row("You saved", amount(sale.discountCents));
  p.bold(true).row("TOTAL", `KES ${amount(sale.totalCents)}`).bold(false);
  p.rule("=");
  for (const t of bill ? [] : sale.tenders.filter((t) => t.amountCents > 0)) {
    p.row(`${TENDER_LABELS[t.method]}${t.reference ? ` ${t.reference}` : ""}`, amount(t.method === "cash" ? (t.tenderedCents ?? t.amountCents) : t.amountCents));
  }
  if (!bill && sale.roundingCents !== 0) p.row("Cash rounding", amount(sale.roundingCents));
  const change = sale.tenders.find((t) => t.method === "cash" && t.amountCents >= 0)?.changeCents ?? 0;
  if (!bill && change > 0) p.bold(true).row("Change", amount(change)).bold(false);
  p.row("VAT included", amount(sale.vatCents));
  if (sale.returnedCents > 0) p.row("Refunded", `-${amount(sale.returnedCents)}`);

  p.rule().align("center");
  if (bill) {
    p.bold(true).paragraph("NOT A TAX INVOICE. Your receipt and eTIMS invoice are printed when you pay.").bold(false);
  } else if (sale.pendingSync) {
    p.bold(true).paragraph("RECORDED OFFLINE - provisional number. The official receipt number and eTIMS invoice follow when the till reconnects.").bold(false);
  } else if (sale.etims?.status === "signed") {
    p.align("left");
    p.row("CU invoice no.", sale.etims.invoiceNumber ?? "");
    p.row("SCU ID", sale.etims.scuId ?? "");
    p.paragraph(`Internal data: ${sale.etims.internalData ?? ""}`);
    p.paragraph(`Signature: ${sale.etims.signature ?? ""}`);
    p.align("center");
    // The cashier's copy keeps the invoice number; the QR is for the customer.
    if (sale.etims.qrPayload && !cashierCopy) p.qr(sale.etims.qrPayload, receipt.paperSize === "58mm" ? 4 : 5);
    if (sale.etims.qrPayload?.includes("mock=1")) p.bold(true).line("DEMO eTIMS - NOT A KRA INVOICE").bold(false);
  } else if (sale.etimsStatus !== "not_required") {
    p.paragraph("eTIMS invoice pending - not yet signed by KRA");
  }
  if (cashierCopy) {
    p.feed(2).align("left").line(`Cashier sign: ${"_".repeat(p.width - 14)}`).align("center");
  } else {
    receipt.footerLines.forEach((line) => p.paragraph(line));
    if (!bill && show("return_policy")) p.paragraph(`Sealed bottles can be returned within ${receipt.returnWindowDays} days with this receipt.`);
    if (!bill) p.feed(1).bold(true).line("THANK YOU - CHEERS!").bold(false);
  }
  p.line("* ~ * ~ * ~ * ~ *");

  return p.feed(4).cut().build();
}

/** The sale put cash in the drawer (a cash tender above zero). */
export function tookCash(sale: Pick<Sale, "tenders">): boolean {
  return sale.tenders.some((t) => t.method === "cash" && t.amountCents > 0);
}

/** Only the drawer pulse (pin 2), sent on its own so the drawer opens before the receipt prints. */
export function drawerBytes(): Uint8Array {
  return new EscPos(48).openDrawer().build();
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
