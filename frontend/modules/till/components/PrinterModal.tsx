"use client";

import { Alert, Button, Group, Modal, Stack, Text } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconCash, IconPlug, IconPrinter, IconUsb } from "@tabler/icons-react";
import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import receiptClasses from "@/modules/sales/components/Receipt.module.css";
import { ApiError, salesApi } from "@/api";
import { pairedDeviceName, printsDirect, rememberDevice, sendToPrinter } from "@/modules/sales/printer";
import { drawerBytes, paperWidthMm, testPageBytes } from "@/modules/sales/printer/documents";
import { logoRaster } from "@/modules/sales/printer/logo";
import { isDirect, isSupported, pairSerial, pairUsb } from "@/modules/sales/printer/transports";
import { useAppSelector } from "@/store/hooks";
import type { TillContext } from "@/types/till";

const CONNECTION_LABELS = {
  browser: "Print dialog (any printer this computer knows)",
  usb: "USB thermal printer, sent directly",
  serial: "Serial or Bluetooth printer (COM port), sent directly",
  rawbt: "RawBT app on this Android device",
  none: "No printer",
} as const;

/**
 * This till's receipt printer: the connection chosen in Settings → Integrations, pairing the device in
 * this browser, and a test page (paper width, logo, cutter).
 */
export default function PrinterModal({ context, onClose }: { context: TillContext; onClose: () => void }) {
  const { printer, receipt } = context.policy;
  const [device, setDevice] = useState(pairedDeviceName);
  const [busy, setBusy] = useState(false);
  const supported = isSupported(printer.connection);
  const paper = paperWidthMm(receipt.paperSize);
  // Opening the drawer without a sale is the owner's alone, and logged.
  const canTestDrawer = useAppSelector((state) => state.auth.user?.permissions.includes("sales.drawer.test") ?? false);
  // The print dialog version of the test page: mounted, printed, unmounted.
  const [browserTest, setBrowserTest] = useState(false);
  useEffect(() => {
    if (!browserTest) return;
    const timer = window.setTimeout(() => {
      window.print();
      setBrowserTest(false);
    }, 50);
    return () => window.clearTimeout(timer);
  }, [browserTest]);

  const pair = async () => {
    setBusy(true);
    try {
      const name = printer.connection === "usb" ? await pairUsb() : await pairSerial(printer.baudRate);
      rememberDevice(name);
      setDevice(name);
      notifications.show({ color: "green", message: `${name} is this till's printer.` });
    } catch (e) {
      // Closing the browser's chooser is not an error worth showing.
      if (e instanceof Error && e.name !== "NotFoundError") notifications.show({ color: "red", message: e.message });
    } finally {
      setBusy(false);
    }
  };

  const test = async () => {
    if (busy) return;
    setBusy(true);
    try {
      const outcome = await sendToPrinter(printer, async () => {
        const logo = receipt.show.includes("logo") ? await logoRaster(receipt.logo, paper) : null;
        return testPageBytes(context.business.name, context.branch.name, receipt, logo);
      });
      if (outcome.via === "printer") notifications.show({ color: "green", message: "Test page sent to the printer." });
      else if (outcome.via === "browser") {
        if (outcome.error) notifications.show({ color: "yellow", message: `The printer did not answer (${outcome.error}). Opening the print dialog instead.` });
        setBrowserTest(true);
      }
    } finally {
      setBusy(false);
    }
  };

  const testDrawer = async () => {
    if (busy) return;
    setBusy(true);
    try {
      await salesApi.drawerTest();
      const outcome = await sendToPrinter(printer, drawerBytes);
      notifications.show(
        outcome.via === "printer"
          ? { color: "green", message: "Drawer opened. The test is in the audit log." }
          : { color: "red", message: `The drawer did not open${outcome.via === "browser" && outcome.error ? ` (${outcome.error})` : ""}. Check the printer and the drawer cable.` },
      );
    } catch (e) {
      notifications.show({ color: "red", message: e instanceof ApiError ? e.message : "Could not test the drawer." });
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Receipt printer" centered>
      <Stack>
        <div>
          <Text size="sm" c="dimmed">
            Connection (Settings → Integrations → Receipt printer)
          </Text>
          <Text fw={600}>{CONNECTION_LABELS[printer.connection]}</Text>
          <Text size="sm" c="dimmed">
            {paper} mm paper{printer.connection === "serial" ? ` · ${printer.baudRate} baud` : ""}
          </Text>
        </div>

        {isDirect(printer.connection) && !supported && (
          <Alert color="yellow">This browser cannot use this connection. Use Chrome or Edge (RawBT needs Android). Receipts open the print dialog until then.</Alert>
        )}
        {printer.connection === "none" && <Alert color="gray">Receipts are not printed on this till. The Print button still opens the print dialog.</Alert>}

        {(printer.connection === "usb" || printer.connection === "serial") && supported && (
          <Group>
            <Button variant="default" leftSection={printer.connection === "usb" ? <IconUsb size={18} /> : <IconPlug size={18} />} loading={busy} onClick={() => void pair()}>
              Choose printer
            </Button>
            <Text size="sm" c="dimmed">
              {device ? `Chosen: ${device}` : "No printer chosen on this till yet."}
            </Text>
          </Group>
        )}
        {printer.connection === "rawbt" && supported && <Text size="sm">Install RawBT from Google Play and pick the printer there once. Receipts then print without a dialog.</Text>}

        {printer.cashDrawer && (
          <Text size="sm" c="dimmed">
            Cash drawer: opens for sales that took cash{printsDirect(printer) ? "" : " (needs a direct printer on this browser)"}.
          </Text>
        )}

        <Group justify="flex-end">
          {canTestDrawer && printer.cashDrawer && printsDirect(printer) && (
            <Button variant="default" leftSection={<IconCash size={18} />} loading={busy} onClick={() => void testDrawer()}>
              Test drawer
            </Button>
          )}
          <Button variant="default" onClick={onClose}>
            Close
          </Button>
          <Button leftSection={<IconPrinter size={18} />} loading={busy} disabled={printer.connection === "none"} onClick={() => void test()}>
            Print test page
          </Button>
        </Group>
      </Stack>
      {browserTest &&
        createPortal(
          <div className="tessera-print">
            <div className={`${receiptClasses.receipt} ${paper === 58 ? receiptClasses.paper58 : ""}`}>
              <div className={receiptClasses.center}>
                <div className={receiptClasses.shop}>{context.business.name}</div>
                <div>{context.branch.name}</div>
                <div className={receiptClasses.copy}>PRINTER TEST</div>
              </div>
              <div className={receiptClasses.rule} />
              <div>{paper} mm paper</div>
              <div>1234567890123456789012345678901234567890</div>
            </div>
          </div>,
          document.body,
        )}
    </Modal>
  );
}
