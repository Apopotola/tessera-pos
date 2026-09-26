"use client";

import dayjs from "dayjs";
import { QRCodeSVG } from "qrcode.react";
import { createPortal } from "react-dom";
import { type Sale, TENDER_LABELS } from "@/types/sales";
import { formatKes } from "@/utils/money";
import classes from "./Receipt.module.css";


const PAPER = { "58mm": classes.paper58, "80mm": "", a4: classes.paperA4 } as const;

/**
 * Till receipt laid out by Settings → Receipts (paper, logo, header and footer, what to show).
 * Rendered on screen as a preview; `ReceiptPrint` sends it to the printer.
 */
export default function Receipt({ sale, copy = false }: { sale: Sale; copy?: boolean }) {
  const cash = sale.tenders.find((t) => t.method === "cash" && t.amountCents >= 0);
  const { receipt } = sale;
  const show = (what: Sale["receipt"]["show"][number]) => receipt.show.includes(what);

  return (
    <div className={`${classes.receipt} ${PAPER[receipt.paperSize]}`}>
      <div className={classes.center}>
        {show("logo") && receipt.logo && (
          // eslint-disable-next-line @next/next/no-img-element -- receipt logo served by the API, printed as-is
          <img src={receipt.logo} alt="" className={classes.logo} />
        )}
        <div className={classes.shop}>{sale.business.name}</div>
        <div>{sale.branch.name}</div>
        {receipt.headerLines.map((line, i) => (
          <div key={i}>{line}</div>
        ))}
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
      {show("cashier") && (
        <div className={classes.row}>
          <span>Served by</span>
          <span>{sale.cashier.name}</span>
        </div>
      )}
      {show("customer") && sale.customer && (
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
              {TENDER_LABELS[t.method]}
              {t.reference ? ` ${t.reference}` : ""}
            </span>
            <span>{formatKes(t.method === "cash" ? (t.tenderedCents ?? t.amountCents) : t.amountCents)}</span>
          </div>
        ))}
      {sale.roundingCents !== 0 && (
        <div className={classes.row}>
          <span>Cash rounding</span>
          <span>{formatKes(sale.roundingCents)}</span>
        </div>
      )}
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
        ) : sale.etimsStatus === "not_required" ? null : (
          <div>eTIMS invoice pending — not yet signed by KRA</div>
        )}
        {receipt.footerLines.map((line, i) => (
          <div key={i}>{line}</div>
        ))}
        {show("return_policy") && <div>Sealed bottles can be returned within {receipt.returnWindowDays} days with this receipt.</div>}
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
