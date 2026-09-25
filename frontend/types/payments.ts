/** Mirrors Modules\Payments controllers. Amounts are integer cents. */
import type { Paginated } from "@/types/api";

/** What the till sees of an M-PESA confirmation (no full phone number). */
export interface MpesaConfirmation {
  id: number;
  receipt: string;
  amountCents: number;
  source: "stk" | "c2b";
  phoneMasked: string | null;
  payerName: string | null;
  transactedAt: string;
}

export interface StkRequest {
  id: number;
  status: "pending" | "paid" | "failed";
  phoneMasked: string;
  amountCents: number;
  resultDescription: string | null;
  createdAt: string | null;
  timeoutSeconds: number;
  confirmation: MpesaConfirmation | null;
}

/** Back-office row: whether and where the payment was used. */
export interface MpesaConfirmationRow extends MpesaConfirmation {
  billReference: string | null;
  allocated: boolean;
  sale: { id: number; number: string } | null;
  allocatedBy: string | null;
  allocatedAt: string | null;
}

export interface MpesaConfirmationsPage extends Paginated<MpesaConfirmationRow> {
  summary: { matchedCount: number; matchedCents: number; unallocatedCount: number; unallocatedCents: number };
}

/** An M-PESA tender typed in while M-PESA ran in manual mode. */
export interface UnverifiedMpesaTender {
  id: number;
  amountCents: number;
  reference: string | null;
  createdAt: string | null;
  sale: { id: number; number: string } | null;
  cashier: string;
  suggestedConfirmationId: number | null;
}
