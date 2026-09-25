/** Mirrors Modules\Compliance (eTIMS). Amounts are integer cents. */
import type { Paginated } from "@/types/api";

export type EtimsStatus = "pending" | "signed" | "failed" | "rejected";

/** KRA details printed on a receipt once the invoice is signed. */
export interface EtimsReceipt {
  status: EtimsStatus;
  invoiceNumber: string | null;
  signature: string | null;
  internalData: string | null;
  qrPayload: string | null;
  scuId: string | null;
  signedAt: string | null;
}

export interface EtimsSubmissionRow {
  id: number;
  documentType: "sale" | "credit_note";
  documentNumber: string;
  originalDocumentNumber: string | null;
  branch: string;
  status: EtimsStatus;
  attempts: number;
  nextAttemptAt: string | null;
  lastError: string | null;
  kraInvoiceNumber: string | null;
  signedAt: string | null;
  createdAt: string | null;
}

export interface EtimsSubmissionsPage extends Paginated<EtimsSubmissionRow> {
  summary: {
    driver: "fake" | "disabled" | string;
    pending: number;
    signed: number;
    failed: number;
    rejected: number;
    waitingOverThreshold: number;
    alertMinutes: number;
  };
}

export interface EtimsReconciliationRow {
  day: string;
  branch: string;
  salesCount: number;
  salesCents: number;
  signedSalesCount: number;
  signedSalesCents: number;
  creditNotesCount: number;
  creditNotesCents: number;
  signedCreditNotesCount: number;
  signedCreditNotesCents: number;
  matches: boolean;
}
