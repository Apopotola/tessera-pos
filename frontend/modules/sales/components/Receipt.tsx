"use client";

import dayjs from "dayjs";
import { QRCodeSVG } from "qrcode.react";
import { createPortal } from "react-dom";
import type { Sale } from "@/types/sales";
import { formatKes } from "@/utils/money";
import classes from "./Receipt.module.css";

const METHOD_LABEL = { cash: "Cash", mpesa: "M-PESA", card: "Card" } as const;

/** 80 mm till receipt. Rendered on screen as a preview; `ReceiptPrint` sends it to the printer. */
export default function Receipt({ sale, copy = false }: { sale: Sale; copy?: boolean }) {
  const cash = sale.tenders.find((t) => t.method === "cash" && t.amountCents >= 0);

  return (
    <div className={classes.receipt}>
      <div className={classes.center}>
        <div className={classes.shop}>{sale.business.name}</div>
        <div>{sale.branch.name}</div>
        {sale.business.kraPin && <div>PIN: {sale.business.kraPin}</div>}
        {copy && <div className={classes.copy}>*** COPY ***</div>}
      </div>

      <div className={classes.rule} />
      <div className={classes.row}>
        <span>Receipt</span>
        <span>{sale.number}</span>
      </div>
      <div className={classes.row}>
        <span>{dayjs(sale.completedAt).format("DD/MM/YYYY HH:mm")}</span>
        <span>{sale.till.name}</span>
      </div>
      <div className={classes.row}>
        <span>Served by</span>
        <span>{sale.cashier.name}</span>
      </div>
      {sale.customer && (
        <div className={classes.row}>
          <span>Customer</span>
          <span>{sale.customer.name}</span>
        </div>
      )}
      {sale.customerPin && (
        <div className={classes.row}>
          <span>Customer PIN</span>
          <span>{sale.customerPin}</span>
        </div>
      )}
      <div className={classes.rule} />

      {sale.lines.map((line) => (
        <div key={line.id} className={classes.line}>
          <div>
            {line.variant.displayName}
            {line.unit === "tot" && ` (tot ${line.totMl}ml)`}
          </div>
          <div className={classes.row}>
            <span>
              {line.quantity} × {formatKes(line.unitPriceCents)}
            </span>
            <span>{formatKes(line.quantity * line.unitPriceCents)}</span>
          </div>
          {line.discountCents > 0 && (
            <div className={classes.row}>
              <span>&nbsp;&nbsp;Discount</span>
              <span>-{formatKes(line.discountCents)}</span>
            </div>
          )}
        </div>
      ))}

      <div className={classes.rule} />
      {sale.discountCents > 0 && (
        <div className={classes.row}>
          <span>You saved</span>
          <span>{formatKes(sale.discountCents)}</span>
        </div>
      )}
      <div className={`${classes.row} ${classes.total}`}>
        <span>TOTAL</span>
        <span>{formatKes(sale.totalCents)}</span>
      </div>
      {sale.tenders
        .filter((t) => t.amountCents > 0)
        .map((t, i) => (
          <div key={i} className={classes.row}>
            <span>
              {METHOD_LABEL[t.method]}
              {t.reference ? ` ${t.reference}` : ""}
            </span>
            <span>{formatKes(t.method === "cash" ? (t.tenderedCents ?? t.amountCents) : t.amountCents)}</span>
          </div>
        ))}
      {cash && (cash.changeCents ?? 0) > 0 && (
        <div className={classes.row}>
          <span>Change</span>
          <span>{formatKes(cash.changeCents)}</span>
        </div>
      )}
      <div className={classes.row}>
        <span>VAT included</span>
        <span>{formatKes(sale.vatCents)}</span>
      </div>
      {sale.returnedCents > 0 && (
        <div className={classes.row}>
          <span>Refunded</span>
          <span>-{formatKes(sale.returnedCents)}</span>
        </div>
      )}

      <div className={classes.rule} />
      <div className={classes.center}>
        {sale.pendingSync ? (
          <div className={classes.copy}>
            RECORDED OFFLINE — provisional number. The official receipt number and eTIMS invoice follow when the till reconnects.
          </div>
        ) : sale.etims?.status === "signed" ? (
          <div className={classes.etims}>
            <div className={classes.row}>
              <span>CU invoice no.</span>
              <span>{sale.etims.invoiceNumber}</span>
            </div>
            <div className={classes.row}>
              <span>SCU ID</span>
              <span>{sale.etims.scuId}</span>
            </div>
            <div>Internal data: {sale.etims.internalData}</div>
            <div>Signature: {sale.etims.signature}</div>
            {sale.etims.qrPayload && (
              <div className={classes.qr}>
                <QRCodeSVG value={sale.etims.qrPayload} size={96} marginSize={0} />
              </div>
            )}
            {sale.etims.qrPayload?.includes("mock=1") && <div className={classes.copy}>DEMO eTIMS — NOT A KRA INVOICE</div>}
          </div>
        ) : (
          <div>eTIMS invoice pending — not yet signed by KRA</div>
        )}
        <div>{sale.receiptFooter}</div>
      </div>
    </div>
  );
}

/** Mount while printing: only this receipt shows on paper (see the print rules in globals.css). */
export function ReceiptPrint({ sale, copy = false }: { sale: Sale; copy?: boolean }) {
  if (typeof document === "undefined") return null;
  return createPortal(
    <div className="tessera-print">
      <Receipt sale={sale} copy={copy} />
    </div>,
    document.body,
  );
}
