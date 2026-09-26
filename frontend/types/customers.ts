/** Mirrors Modules\Customers\Http\Resources\CustomerResource. Contact fields only reach managers and above. */
export interface Customer {
  id: number;
  name: string;
  kraPin: string | null;
  isWholesale: boolean;
  isActive: boolean;
  anonymisedAt: string | null;
  contactName?: string | null;
  phone?: string | null;
  email?: string | null;
  notes?: string | null;
  salesCount?: number;
  salesTotalCents?: number;
  lastPurchaseAt?: string | null;
  createdAt: string | null;
}

export interface CustomerPayload {
  name: string;
  kraPin: string | null;
  isWholesale: boolean;
  contactName: string | null;
  phone: string | null;
  email: string | null;
  notes: string | null;
  isActive?: boolean;
}

/** What the till gets: no contact details. */
export interface TillCustomer {
  id: number;
  name: string;
  kraPin: string | null;
  isWholesale: boolean;
  /** Credit account: how much more can go on account without a manager; null = no account. */
  creditAvailableCents?: number | null;
}

export type CustomerType = "all" | "wholesale" | "business";
