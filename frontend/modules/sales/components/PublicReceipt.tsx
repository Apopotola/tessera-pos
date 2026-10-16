"use client";

import { Button, Stack } from "@mantine/core";
import { IconPrinter } from "@tabler/icons-react";
import dayjs from "dayjs";
import { QRCodeSVG } from "qrcode.react";
import { useCallback } from "react";
import { salesApi } from "@/api";
import { brand } from "@/app/theme";
import Logo from "@/components/brand/Logo";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";
import { TENDER_LABELS } from "@/types/sales";
import { formatKes } from "@/utils/money";
import classes from "./Receipt.module.css";

/**
 * The receipt behind a shared link (/r/{token}), e.g. sent on WhatsApp. No sign-in: the API returns
 * only what the paper receipt shows, and records each time the link is opened.
 */
export default function PublicReceipt({ token }: { token: string }) {
  const fetcher = useCallback(() => salesApi.publicReceipt(token), [token]);
  const { data: sale, error, loading } = useApiQuery(fetcher);

  return (
    <div style={{ minHeight: "100vh", background: brand.navy, padding: "24px 16px" }}>
      <Stack align="center" gap="lg" maw={420} mx="auto">
        <Logo tone="light" />
        <QueryState loading={loading} error={error} isEmpty={false}>
          {sale && (
            <>
              <div className={`${classes.receipt} tessera-printable`} style={{ borderRadius: 8 }}>
                <div className={classes.center}>
                  {sale.receipt.logo && (
                    // eslint-disable-next-line @next/next/no-img-element -- receipt logo served by the API, printed as-is
                    <img src={sale.receipt.logo} alt="" className={classes.logo} />
                  )}
                  <div className={classes.shop}>{sale.business.name}</div>
                  <div>{sale.branch.name}</div>
                  {[...sale.receipt.headerLines, ...sale.receipt.licenceLines].map((line, i) => (
                    <div key={i}>{line}</div>
                  ))}
                  {sale.business.kraPin && <div>PIN: {sale.business.kraPin}</div>}
                </div>

                <div className={classes.rule} />
                <Row label="Receipt" value={sale.number} />
                <Row label={dayjs(sale.completedAt).format("DD/MM/YYYY HH:mm")} value={sale.till.name} />
                {sale.cashier && <Row label="Served by" value={sale.cashier.name} />}
                {sale.customer && <Row label="Customer" value={sale.customer.name} />}
                {sale.customerPin && <Row label="Customer PIN" value={sale.customerPin} />}
                <div className={classes.rule} />

                {sale.lines.map((line, i) => (
                  <div key={i} className={classes.line}>
                    <div>
                      {line.displayName}
                      {line.unit === "tot" && ` (tot ${line.totMl}ml)`}
                    </div>
                    <Row label={`${line.quantity} × ${formatKes(line.unitPriceCents)}`} value={formatKes(line.quantity * line.unitPriceCents)} />
                    {line.promotionDiscountCents > 0 && <Row label={`  ${line.promotionName ?? "Promotion"}`} value={`-${formatKes(line.promotionDiscountCents)}`} />}
                    {line.discountCents > 0 && <Row label="  Discount" value={`-${formatKes(line.discountCents)}`} />}
                  </div>
                ))}

                <div className={classes.rule} />
                {sale.discountCents > 0 && <Row label="You saved" value={formatKes(sale.discountCents)} />}
                <div className={`${classes.row} ${classes.total}`}>
                  <span>TOTAL</span>
                  <span>{formatKes(sale.totalCents)}</span>
                </div>
                {sale.tenders.map((t, i) => (
                  <Row key={i} label={TENDER_LABELS[t.method]} value={formatKes(t.method === "cash" ? (t.tenderedCents ?? t.amountCents) : t.amountCents)} />
                ))}
                {sale.roundingCents !== 0 && <Row label="Cash rounding" value={formatKes(sale.roundingCents)} />}
                {sale.tenders.some((t) => (t.changeCents ?? 0) > 0) && (
                  <Row label="Change" value={formatKes(sale.tenders.find((t) => (t.changeCents ?? 0) > 0)?.changeCents)} />
                )}
                <Row label="VAT included" value={formatKes(sale.vatCents)} />
                {sale.returnedCents > 0 && <Row label="Refunded" value={`-${formatKes(sale.returnedCents)}`} />}

                <div className={classes.rule} />
                <div className={classes.center}>
                  {sale.etims?.status === "signed" ? (
                    <div className={classes.etims}>
                      <Row label="CU invoice no." value={sale.etims.invoiceNumber ?? ""} />
                      <Row label="SCU ID" value={sale.etims.scuId ?? ""} />
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
                  {sale.receipt.footerLines.map((line, i) => (
                    <div key={i}>{line}</div>
                  ))}
                  {sale.receipt.returnPolicyDays != null && <div>Sealed bottles can be returned within {sale.receipt.returnPolicyDays} days with this receipt.</div>}
                </div>
              </div>
              <Button variant="white" leftSection={<IconPrinter size={18} />} onClick={() => window.print()}>
                Print or save as PDF
              </Button>
            </>
          )}
        </QueryState>
      </Stack>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className={classes.row}>
      <span>{label}</span>
      <span>{value}</span>
    </div>
  );
}
