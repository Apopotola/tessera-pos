import { paperWidthMm, receiptBytes } from "@/modules/sales/printer/documents";
import { logoRaster } from "@/modules/sales/printer/logo";
import { isDirect, isSupported, sendBytes, type PrinterConnection } from "@/modules/sales/printer/transports";
import type { Sale } from "@/types/sales";
import type { TillPrinter } from "@/types/till";

/**
 * Printing on the till. The connection and serial speed come from Settings (per till); the paired
 * device name is only remembered in this browser, to show which printer was picked.
 */

const DEVICE_KEY = "tessera:printer-device";

export function pairedDeviceName(): string | null {
  try {
    return localStorage.getItem(DEVICE_KEY);
  } catch {
    return null;
  }
}

export function rememberDevice(name: string | null): void {
  try {
    if (name) localStorage.setItem(DEVICE_KEY, name);
    else localStorage.removeItem(DEVICE_KEY);
  } catch {
    // Private browsing: remembered for this visit only.
  }
}

/** This till sends ESC/POS commands straight to its printer (not the print dialog). */
export function printsDirect(printer: TillPrinter): boolean {
  return isDirect(printer.connection) && isSupported(printer.connection);
}

export type PrintOutcome = { via: "printer" } | { via: "browser"; error?: string } | { via: "none" };

/** Send raw bytes to this till's printer; the caller falls back to the print dialog on "browser". */
export async function sendToPrinter(printer: TillPrinter, bytes: () => Uint8Array | Promise<Uint8Array>): Promise<PrintOutcome> {
  const connection: PrinterConnection = printer.connection;
  if (connection === "none") return { via: "none" };
  if (!isDirect(connection) || !isSupported(connection)) return { via: "browser" };
  try {
    await sendBytes(connection, await bytes(), printer.baudRate);
    return { via: "printer" };
  } catch (error) {
    return { via: "browser", error: error instanceof Error ? error.message : "Printer error" };
  }
}

/** A receipt on this till's printer, logo included when the receipt shows it. */
export function printReceipt(printer: TillPrinter, sale: Sale, copy: boolean): Promise<PrintOutcome> {
  return sendToPrinter(printer, async () => {
    const logo = sale.receipt.show.includes("logo") ? await logoRaster(sale.receipt.logo, paperWidthMm(sale.receipt.paperSize)) : null;
    return receiptBytes(sale, { copy, logo });
  });
}
