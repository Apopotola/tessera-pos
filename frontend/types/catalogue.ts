/**
 * Mirrors Modules\Catalogue\Http\Resources\*. Money is always integer cents (KES).
 * Fields marked optional are only present when the backend loaded that relation.
 */
export type Container = "bottle" | "can" | "keg" | "box" | "pouch" | "other";
export type PriceTier = "retail" | "wholesale" | "tot";
export type PriceStatus = "pending" | "approved" | "rejected";

export interface EntityRef {
  id: number;
  name: string;
}

export interface TaxRate {
  id: number;
  code: string;
  name: string;
  ratePercent: number;
}

export interface Brand {
  id: number;
  name: string;
  country: string | null;
  isActive: boolean;
}

export interface Category {
  id: number;
  parentId: number | null;
  name: string;
  slug: string;
  sortOrder: number;
  isActive: boolean;
  children?: Category[];
}

export interface Barcode {
  id: number;
  code: string;
  packId: number | null;
}

export interface Pack {
  id: number;
  name: string;
  units: number;
  isActive: boolean;
}

export interface PriceSummary {
  id: number;
  priceCents: number;
  minPriceCents: number | null;
  branchId: number | null;
  effectiveFrom: string;
}

export interface Variant {
  id: number;
  productId: number;
  displayName: string;
  volumeMl: number;
  volumeLabel: string;
  /** Sell by tot: tot size in ml, or null when the item is not poured. */
  totMl: number | null;
  container: Container;
  sku: string;
  taxRate?: TaxRate;
  etimsItemClassCode: string | null;
  etimsItemCode: string | null;
  trackBatches: boolean;
  isActive: boolean;
  barcodes?: Barcode[];
  packs?: Pack[];
  currentPrices?: { retail: PriceSummary | null; wholesale: PriceSummary | null; tot: PriceSummary | null };
}

export interface Product {
  id: number;
  name: string;
  brand?: EntityRef | null;
  category?: EntityRef;
  abv: number | null;
  description: string | null;
  isActive: boolean;
  variants?: Variant[];
}

export interface PriceRecord {
  id: number;
  variantId: number;
  variant?: { id: number; displayName: string; sku: string };
  branch?: { id: number; code: string; name: string } | null;
  tier: PriceTier;
  priceCents: number;
  minPriceCents: number | null;
  effectiveFrom: string;
  status: PriceStatus;
  reason: string | null;
  requestedBy?: EntityRef;
  reviewedBy?: EntityRef | null;
  reviewedAt: string | null;
  reviewNote: string | null;
  createdAt: string | null;
}

export interface BarcodeLookup {
  variant: Variant;
  pack: { id: number; name: string; units: number } | null;
  units: number;
}

// ---- Request payloads (camelCase, validated by Laravel Form Requests) ----

export interface ProductFilters {
  search?: string;
  categoryId?: number;
  brandId?: number;
  status?: "active" | "inactive" | "all";
  page?: number;
  perPage?: number;
}

export interface VariantPayload {
  volumeMl: number;
  totMl?: number | null;
  container: Container;
  sku: string;
  taxRateId: number;
  etimsItemClassCode?: string | null;
  trackBatches?: boolean;
  isActive?: boolean;
}

export interface NewVariantPayload extends VariantPayload {
  barcodes?: string[];
  retailPriceCents?: number | null;
}

export interface ProductPayload {
  brandId: number | null;
  categoryId: number;
  name: string;
  abv: number | null;
  description: string | null;
  isActive?: boolean;
}

export interface CreateProductPayload extends ProductPayload {
  variants: NewVariantPayload[];
}

export interface PricePayload {
  tier: PriceTier;
  branchId: number | null;
  priceCents: number;
  minPriceCents: number | null;
  effectiveFrom: string | null;
  reason: string;
}

export interface PackPayload {
  name: string;
  units: number;
  barcode?: string | null;
  isActive?: boolean;
}

export interface BrandPayload {
  name: string;
  country: string | null;
  isActive?: boolean;
}

export interface CategoryPayload {
  name: string;
  parentId: number | null;
  sortOrder?: number;
  isActive?: boolean;
}

export interface WithWarnings {
  warnings: string[];
}
