"use client";

import dayjs from "dayjs";
import { QRCodeSVG } from "qrcode.react";
import { createPortal } from "react-dom";
import { COPY_LABEL, type ReceiptCopyFor } from "@/modules/sales/printer/documents";
import { type Sale, TENDER_LABELS } from "@/types/sales";
import { formatKes } from "@/utils/money";
import classes from "./Receipt.module.css";


const PAPER = { "58mm": classes.paper58, "80mm": "", a4: classes.paperA4 } as const;

/**
 * Till receipt laid out by Settings → Receipts (paper, logo, header and footer, what to show).
 * Rendered on screen as a preview; `ReceiptPrint` sends it to the printer. As a `bill` it is the
 * customer's bill before paying: no receipt number, payments or eTIMS, and marked as not a tax invoice.
 */
interface ReceiptProps {
  sale: Sale;
  copy?: boolean;
  bill?: boolean;
  copyFor?: ReceiptCopyFor;
  /** The receipt's online copy (a share link): a QR on the customer's copy when there is no eTIMS QR. */
  link?: string | null;
}

export default function Receipt({ sale, copy = false, bill = false, copyFor, link = null }: ReceiptProps) {
  const cash = sale.tenders.find((t) => t.method === "cash" && t.amountCents >= 0);
  const { receipt } = sale;
  const show = (what: Sale["receipt"]["show"][number]) => receipt.show.includes(what);
  const cashierCopy = copyFor === "cashier";

  return (
    <div className={`${classes.receipt} ${PAPER[receipt.paperSize]}`}>
      {/* Watermark: the shop's name repeated faintly behind the receipt, so a photocopy or a fake stands out. */}
      <div className={classes.watermark} aria-hidden>
        {Array.from({ length: 40 }, (_, i) => (
          <span key={i}>{sale.business.name}</span>
        ))}
      </div>
      <div className={classes.flourish} aria-hidden>
        ✦ ✦ ✦
      </div>
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
        {(receipt.licenceLines ?? []).map((line) => (
          <div key={line}>{line}</div>
        ))}
        {sale.business.kraPin && <div>PIN: {sale.business.kraPin}</div>}
        {copyFor && <div className={classes.banner}>{COPY_LABEL[copyFor]}</div>}
        {copy && <div className={classes.copy}>*** COPY ***</div>}
        {bill && <div className={classes.copy}>BILL — NOT PAID</div>}
      </div>

      <div className={classes.rule} />
      {!bill && (
        <div className={classes.row}>
          <span>Receipt</span>
          <span>{sale.number}</span>
        </div>
      )}
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
          {line.promotionDiscountCents > 0 && (
            <div className={classes.row}>
              <span>&nbsp;&nbsp;{line.promotion?.name ?? "Promotion"}</span>
              <span>-{formatKes(line.promotionDiscountCents)}</span>
            </div>
          )}
          {line.discountCents > 0 && (
            <div className={classes.row}>
              <span>&nbsp;&nbsp;Discount</span>
              <span>-{formatKes(line.discountCents)}</span>
            </div>
          )}
        </div>
      ))}

      <div className={classes.doubleRule} />
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
      {!bill &&
        sale.tenders
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
      {!bill && sale.roundingCents !== 0 && (
        <div className={classes.row}>
          <span>Cash rounding</span>
          <span>{formatKes(sale.roundingCents)}</span>
        </div>
      )}
      {!bill && cash && (cash.changeCents ?? 0) > 0 && (
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
        {bill ? (
          <div className={classes.copy}>NOT A TAX INVOICE. Your receipt and eTIMS invoice are printed when you pay.</div>
        ) : sale.pendingSync ? (
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
            {sale.etims.qrPayload && !cashierCopy && (
              <div className={classes.qr}>
                <QRCodeSVG value={sale.etims.qrPayload} size={96} marginSize={0} />
              </div>
            )}
            {sale.etims.qrPayload?.includes("mock=1") && <div className={classes.copy}>DEMO eTIMS — NOT A KRA INVOICE</div>}
          </div>
        ) : sale.etimsStatus === "not_required" ? null : (
          <div>eTIMS invoice pending — not yet signed by KRA</div>
        )}
        {link && !bill && !cashierCopy && !sale.etims?.qrPayload && (
          <div className={classes.qr}>
            <QRCodeSVG value={link} size={96} marginSize={0} />
          </div>
        )}
        {link && !bill && !cashierCopy && !sale.etims?.qrPayload && <div>Scan for your receipt online</div>}
        {cashierCopy ? (
          <div className={classes.signature}>Cashier sign</div>
        ) : (
          <>
            {receipt.footerLines.map((line, i) => (
              <div key={i}>{line}</div>
            ))}
            {!bill && show("return_policy") && <div>Sealed bottles can be returned within {receipt.returnWindowDays} days with this receipt.</div>}
            {!bill && <div className={classes.thanks}>Thank you · Cheers!</div>}
          </>
        )}
      </div>
      <div className={classes.flourish} aria-hidden>
        ✦ ✦ ✦
      </div>
    </div>
  );
}

/**
 * Mount while printing: only these receipts show on paper (see the print rules in globals.css).
 * With `copies`, one receipt per copy, each on its own page (the printer cuts between them).
 */
export function ReceiptPrint({ sale, copy = false, bill = false, copies, link = null }: Omit<ReceiptProps, "copyFor"> & { copies?: ReceiptCopyFor[] }) {
  if (typeof document === "undefined") return null;
  return createPortal(
    <div className="tessera-print">
      {copies ? (
        copies.map((copyFor) => (
          <div key={copyFor} className={classes.page}>
            <Receipt sale={sale} copyFor={copyFor} bill={bill} link={link} />
          </div>
        ))
      ) : (
        <Receipt sale={sale} copy={copy} bill={bill} />
      )}
    </div>,
    document.body,
  );
}
