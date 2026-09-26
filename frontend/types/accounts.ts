/**
 * Credit accounts (receivables) and supplier balances (payables).
 * Mirrors Modules\Customers\Http\Controllers\CustomerAccountController and
 * Modules\Purchasing\Http\Controllers\SupplierAccountController. Amounts are integer cents.
 */

/** App\Support\Aging buckets. */
export type AgingBucket = "0_30" | "31_60" | "61_90" | "over_90";

export const AGING_LABELS: Record<AgingBucket, string> = { "0_30": "0–30 days", "31_60": "31–60 days", "61_90": "61–90 days", over_90: "90+ days" };

export interface CustomerAccount {
  hasAccount: boolean;
  /** null = no credit account; 0 = on hold. */
  creditLimitCents: number | null;
  creditTermsDays: number;
  balanceCents: number;
  availableCents: number | null;
  overdueCents: number;
  aging: Record<AgingBucket, number>;
  oldestDays: number | null;
}

export interface SupplierAccount {
  balanceCents: number;
  /** Past the invoices' due dates. */
  dueCents: number;
  /** Invoices that do not match the goods received. */
  onQueryCents: number;
  paymentTermsDays: number;
  aging: Record<AgingBucket, number>;
  oldestDays: number | null;
}

export interface AccountsList<T> {
  asAt: string;
  items: ({ id: number; name: string } & T)[];
  totals: { balanceCents: number; aging: Record<AgingBucket, number> } & Partial<Record<"overdueCents" | "dueCents" | "onQueryCents", number>>;
}

export interface StatementLine {
  date: string;
  dueDate: string | null;
  type: "sale" | "return" | "payment" | "reversal" | "invoice" | "credit_note";
  reference: string;
  description: string;
  /** Positive = owed more. */
  amountCents: number;
  balanceCents: number;
}

export interface Statement {
  from: string;
  to: string;
  openingCents: number;
  lines: StatementLine[];
  closingCents: number;
}

export interface AccountPayment {
  id: number;
  number: string;
  amountCents: number;
  method: string;
  reference: string | null;
  /** Customer payments: receivedAt; supplier payments: paidOn. */
  receivedAt?: string;
  paidOn?: string;
  note: string | null;
  isReversal: boolean;
  reversed: boolean;
  recordedBy: string | null;
}

export const CUSTOMER_PAYMENT_METHODS = [
  { value: "cash", label: "Cash" },
  { value: "mpesa", label: "M-PESA" },
  { value: "bank", label: "Bank transfer" },
  { value: "card", label: "Card" },
  { value: "cheque", label: "Cheque" },
];

export const SUPPLIER_PAYMENT_METHODS = [
  { value: "bank", label: "Bank transfer" },
  { value: "mpesa", label: "M-PESA" },
  { value: "cash", label: "Cash" },
  { value: "cheque", label: "Cheque" },
];

export interface CustomerPaymentPayload {
  amountCents: number;
  method: string;
  reference: string | null;
  branchId: number;
  note: string | null;
}

export interface SupplierPaymentPayload {
  amountCents: number;
  method: string;
  reference: string | null;
  paidOn: string | null;
  note: string | null;
}
