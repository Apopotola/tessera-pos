/**
 * How the bytes reach the printer. The connection is chosen per till in Settings → Integrations →
 * Receipt printer; the device itself is paired once in this browser (the browser keeps the permission).
 * - usb:     WebUSB (Chrome / Edge on Windows, macOS, Linux, ChromeOS and Android)
 * - serial:  Web Serial (desktop Chrome / Edge) for serial or USB-serial printers, and paired Bluetooth
 *            printers that appear as a COM port
 * - rawbt:   the free RawBT app on Android, which prints to Bluetooth, USB and network printers
 * - browser: the print dialog with the 58/80 mm stylesheet, for any printer the computer knows
 */

// Only the parts of WebUSB / Web Serial used here (they are not in TypeScript's DOM library).
interface UsbEndpoint {
  endpointNumber: number;
  direction: "in" | "out";
  type: "bulk" | "interrupt" | "isochronous";
}
interface UsbInterface {
  interfaceNumber: number;
  alternate: { endpoints: UsbEndpoint[] };
}
interface UsbDevice {
  productName?: string;
  vendorId: number;
  productId: number;
  opened: boolean;
  configuration: { interfaces: UsbInterface[] } | null;
  open(): Promise<void>;
  selectConfiguration(value: number): Promise<void>;
  claimInterface(number: number): Promise<void>;
  transferOut(endpoint: number, data: BufferSource): Promise<unknown>;
}
interface Usb {
  requestDevice(options: { filters: object[] }): Promise<UsbDevice>;
  getDevices(): Promise<UsbDevice[]>;
}
interface SerialPortLike {
  writable: WritableStream<Uint8Array> | null;
  open(options: { baudRate: number }): Promise<void>;
  getInfo(): { usbVendorId?: number; usbProductId?: number };
}
interface Serial {
  requestPort(): Promise<SerialPortLike>;
  getPorts(): Promise<SerialPortLike[]>;
}

type WebDevices = Navigator & { usb?: Usb; serial?: Serial };
const devices = (): WebDevices => (typeof navigator === "undefined" ? ({} as WebDevices) : (navigator as WebDevices));

export type PrinterConnection = "browser" | "usb" | "serial" | "rawbt" | "none";
export type DirectConnection = "usb" | "serial" | "rawbt";

export function isDirect(connection: PrinterConnection): connection is DirectConnection {
  return connection === "usb" || connection === "serial" || connection === "rawbt";
}

/** This browser can use the connection. */
export function isSupported(connection: PrinterConnection): boolean {
  if (connection === "usb") return Boolean(devices().usb);
  if (connection === "serial") return Boolean(devices().serial);
  if (connection === "rawbt") return typeof navigator !== "undefined" && /Android/i.test(navigator.userAgent);
  return true;
}

export class PrinterError extends Error {}

// ---------------------------------------------------------------- USB

let usbDevice: UsbDevice | null = null;
let usbEndpoint: number | null = null;

/** Ask the cashier to pick the printer (once per device; the browser remembers the permission). */
export async function pairUsb(): Promise<string> {
  const usb = devices().usb;
  if (!usb) throw new PrinterError("This browser cannot reach USB printers. Use Chrome or Edge.");
  usbDevice = await usb.requestDevice({ filters: [] });
  usbEndpoint = null;
  return usbDevice.productName ?? `USB ${usbDevice.vendorId.toString(16)}:${usbDevice.productId.toString(16)}`;
}

async function usbReady(): Promise<{ device: UsbDevice; endpoint: number }> {
  const usb = devices().usb;
  if (!usb) throw new PrinterError("This browser cannot reach USB printers.");
  usbDevice ??= (await usb.getDevices())[0] ?? null;
  if (!usbDevice) throw new PrinterError("No USB printer has been chosen on this till yet.");

  if (!usbDevice.opened) {
    await usbDevice.open();
    if (!usbDevice.configuration) await usbDevice.selectConfiguration(1);
    usbEndpoint = null;
  }
  if (usbEndpoint === null) {
    // The printer's interface is the one with a bulk OUT endpoint.
    const found = usbDevice.configuration?.interfaces
      .map((i) => ({ i, e: i.alternate.endpoints.find((e) => e.direction === "out" && e.type === "bulk") }))
      .find((x) => x.e);
    if (!found?.e) throw new PrinterError("That USB device is not a printer this till can drive.");
    await usbDevice.claimInterface(found.i.interfaceNumber);
    usbEndpoint = found.e.endpointNumber;
  }
  return { device: usbDevice, endpoint: usbEndpoint };
}

async function sendUsb(bytes: Uint8Array): Promise<void> {
  const { device, endpoint } = await usbReady();
  // Small chunks: some printers have tiny buffers.
  for (let i = 0; i < bytes.length; i += 512) {
    await device.transferOut(endpoint, bytes.slice(i, i + 512));
  }
}

// ---------------------------------------------------------------- Serial

let serialPort: SerialPortLike | null = null;

export async function pairSerial(baudRate: number): Promise<string> {
  const serial = devices().serial;
  if (!serial) throw new PrinterError("This browser cannot reach serial printers. Use Chrome or Edge on a computer.");
  serialPort = await serial.requestPort();
  await serialPort.open({ baudRate });
  const info = serialPort.getInfo();
  return info.usbVendorId ? `Serial ${info.usbVendorId.toString(16)}:${(info.usbProductId ?? 0).toString(16)}` : "Serial port";
}

async function sendSerial(bytes: Uint8Array, baudRate: number): Promise<void> {
  const serial = devices().serial;
  if (!serial) throw new PrinterError("This browser cannot reach serial printers.");
  if (!serialPort) {
    serialPort = (await serial.getPorts())[0] ?? null;
    if (!serialPort) throw new PrinterError("No serial printer has been chosen on this till yet.");
    await serialPort.open({ baudRate });
  }
  if (!serialPort.writable) throw new PrinterError("The serial printer is busy or unplugged.");
  const writer = serialPort.writable.getWriter();
  try {
    await writer.write(bytes);
  } finally {
    writer.releaseLock();
  }
}

// ---------------------------------------------------------------- RawBT (Android)

function base64(bytes: Uint8Array): string {
  let binary = "";
  bytes.forEach((b) => (binary += String.fromCharCode(b)));
  return btoa(binary);
}

/** The intent RawBT registers; Android hands the bytes to the app, which prints and returns. */
export function rawbtUrl(bytes: Uint8Array): string {
  return `intent:base64,${base64(bytes)}#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;`;
}

// ----------------------------------------------------------------

export async function sendBytes(connection: DirectConnection, bytes: Uint8Array, baudRate: number): Promise<void> {
  if (connection === "usb") return sendUsb(bytes);
  if (connection === "serial") return sendSerial(bytes, baudRate);
  window.location.href = rawbtUrl(bytes);
}
