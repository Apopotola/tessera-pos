/**
 * Centralized backend URL resolution and endpoint constants.
 * Every API path in the app comes from here — do not build URLs in components.
 *
 * Sanctum cookies are host-scoped: localhost and 127.0.0.1 are different cookie
 * jars, so in local dev the API host always follows the browser's host.
 */

const LOCAL_HOSTS = ["localhost", "127.0.0.1", "::1"];

function resolveBackendOrigin(): string {
  const envOrigin = (process.env.NEXT_PUBLIC_BACKEND_URL || "").replace(/\/+$/, "").replace(/\/api$/i, "");
  const localPort = process.env.NEXT_PUBLIC_LOCAL_BACKEND_PORT || "8010";

  if (typeof window === "undefined") {
    return envOrigin || `http://localhost:${localPort}`;
  }

  const browserHost = window.location.hostname;
  const isBrowserLocal = LOCAL_HOSTS.includes(browserHost);

  if (!envOrigin) {
    if (isBrowserLocal) {
      return `${window.location.protocol}//${browserHost}:${localPort}`;
    }
    // Production behind a reverse proxy: same origin.
    return "";
  }

  try {
    const parsed = new URL(envOrigin);
    if (isBrowserLocal && LOCAL_HOSTS.includes(parsed.hostname) && parsed.hostname !== browserHost) {
      parsed.hostname = browserHost;
      return parsed.toString().replace(/\/+$/, "");
    }
  } catch {
    // Fall through to the configured value.
  }

  return envOrigin;
}

export const BACKEND_ORIGIN = resolveBackendOrigin();
export const API_BASE_URL = `${BACKEND_ORIGIN}/api/v1`;

/** Absolute: the CSRF endpoint lives outside the /api/v1 prefix. */
export const CSRF_COOKIE_URL = `${BACKEND_ORIGIN}/sanctum/csrf-cookie`;

/** Paths below are relative to API_BASE_URL. */
export const AUTH_URLS = {
  login: "/auth/login",
  pinLogin: "/auth/pin-login",
  logout: "/auth/logout",
  me: "/auth/me",
  users: "/auth/users",
  user: (id: number) => `/auth/users/${id}`,
  userPin: (id: number) => `/auth/users/${id}/pin`,
} as const;

export const SALES_URLS = {
  currentShift: "/sales/shifts/current",
  shifts: "/sales/shifts",
  closeShift: (id: number) => `/sales/shifts/${id}/close`,
  cashDrops: (id: number) => `/sales/shifts/${id}/cash-drops`,
  varianceReason: (id: number) => `/sales/shifts/${id}/variance-reason`,
  shiftDetail: (id: number) => `/sales/shifts/${id}/detail`,
  reviewShift: (id: number) => `/sales/shifts/${id}/review`,
  tillItems: "/sales/till/items",
  tillCatalogue: "/sales/till/catalogue",
  tillScan: (code: string) => `/sales/till/scan/${encodeURIComponent(code)}`,
  tillApprovers: "/sales/till/approvers",
  tillApprovals: "/sales/till/approvals",
  tillSales: "/sales/till/sales",
  tillSale: (number: string) => `/sales/till/sales/${encodeURIComponent(number)}`,
  tillReturns: "/sales/till/returns",
  tillVoids: "/sales/till/voids",
  tillParked: "/sales/till/parked",
  tillRecall: (id: number) => `/sales/till/parked/${id}/recall`,
  openBottles: "/sales/open-bottles",
  writeOffBottle: (id: number) => `/sales/open-bottles/${id}/write-off`,
  sales: "/sales/sales",
  shiftHistory: "/sales/shifts",
} as const;

export const AUTHORIZATION_URLS = {
  menus: "/authorization/menus",
} as const;

export const ORGANISATION_URLS = {
  branches: "/organisation/branches",
  tills: "/organisation/tills",
  pairTill: "/organisation/tills/pair",
  unpairTill: (id: number) => `/organisation/tills/${id}/unpair`,
  tillContext: "/organisation/till-context",
} as const;

export const CATALOGUE_URLS = {
  taxRates: "/catalogue/tax-rates",
  brands: "/catalogue/brands",
  brand: (id: number) => `/catalogue/brands/${id}`,
  categories: "/catalogue/categories",
  category: (id: number) => `/catalogue/categories/${id}`,
  products: "/catalogue/products",
  product: (id: number) => `/catalogue/products/${id}`,
  productVariants: (productId: number) => `/catalogue/products/${productId}/variants`,
  variant: (id: number) => `/catalogue/variants/${id}`,
  variantBarcodes: (variantId: number) => `/catalogue/variants/${variantId}/barcodes`,
  variantBarcode: (variantId: number, barcodeId: number) => `/catalogue/variants/${variantId}/barcodes/${barcodeId}`,
  variantPacks: (variantId: number) => `/catalogue/variants/${variantId}/packs`,
  pack: (id: number) => `/catalogue/packs/${id}`,
  variantPrices: (variantId: number) => `/catalogue/variants/${variantId}/prices`,
  prices: "/catalogue/prices",
  approvePrice: (id: number) => `/catalogue/prices/${id}/approve`,
  rejectPrice: (id: number) => `/catalogue/prices/${id}/reject`,
  lookup: (code: string) => `/catalogue/lookup/${encodeURIComponent(code)}`,
} as const;

export const INVENTORY_URLS = {
  stock: "/inventory/stock",
  movements: "/inventory/movements",
  locations: "/inventory/locations",
  reorderLevels: "/inventory/reorder-levels",
  adjustments: "/inventory/adjustments",
  approveAdjustment: (id: number) => `/inventory/adjustments/${id}/approve`,
  rejectAdjustment: (id: number) => `/inventory/adjustments/${id}/reject`,
  transfers: "/inventory/transfers",
  transferAction: (id: number, action: "approve" | "dispatch" | "receive" | "cancel") => `/inventory/transfers/${id}/${action}`,
  counts: "/inventory/counts",
  count: (id: number) => `/inventory/counts/${id}`,
  countLines: (id: number) => `/inventory/counts/${id}/lines`,
  countAction: (id: number, action: "submit" | "approve" | "reject") => `/inventory/counts/${id}/${action}`,
} as const;

export const PURCHASING_URLS = {
  suppliers: "/purchasing/suppliers",
  supplier: (id: number) => `/purchasing/suppliers/${id}`,
  supplierItems: (id: number) => `/purchasing/suppliers/${id}/items`,
  orders: "/purchasing/orders",
  order: (id: number) => `/purchasing/orders/${id}`,
  orderAction: (id: number, action: "approve" | "send" | "cancel" | "receive") => `/purchasing/orders/${id}/${action}`,
  receipts: "/purchasing/receipts",
  invoices: "/purchasing/invoices",
  returns: "/purchasing/returns",
  returnAction: (id: number, action: "approve" | "reject" | "credit-note") => `/purchasing/returns/${id}/${action}`,
} as const;

export const DASHBOARD_URLS = {
  summary: "/dashboard/summary",
} as const;

export const AUDIT_TRAIL_URLS = {
  logs: "/audit-trail/logs",
} as const;

export const PAYMENTS_URLS = {
  stk: "/payments/mpesa/stk",
  stkStatus: (id: number) => `/payments/mpesa/stk/${id}`,
  unallocated: "/payments/mpesa/unallocated",
  demoTillPayment: "/payments/mpesa/demo/till-payment",
  confirmations: "/payments/mpesa/confirmations",
  unverified: "/payments/mpesa/unverified",
  match: (id: number) => `/payments/mpesa/confirmations/${id}/match`,
} as const;

export const COMPLIANCE_URLS = {
  submissions: "/compliance/etims/submissions",
  retry: (id: number) => `/compliance/etims/submissions/${id}/retry`,
  reconciliation: "/compliance/etims/reconciliation",
} as const;

export const REPORTS_URLS = {
  catalogue: "/reports",
  report: (key: string) => `/reports/${encodeURIComponent(key)}`,
  export: (key: string) => `/reports/${encodeURIComponent(key)}/export`,
} as const;

export const CUSTOMERS_URLS = {
  customers: "/customers",
  customer: (id: number) => `/customers/${id}`,
  sales: (id: number) => `/customers/${id}/sales`,
  export: (id: number) => `/customers/${id}/export`,
  anonymise: (id: number) => `/customers/${id}/anonymise`,
  tillSearch: "/customers/till/search",
} as const;

/** Connectivity check for the offline till (public, never cached). */
export const PING_URL = "/ping";

export const SETTINGS_URLS = {
  public: "/settings/public",
  app: "/settings/app",
  till: "/settings/till",
  schema: "/settings/schema",
  value: (key: string) => `/settings/values/${encodeURIComponent(key)}`,
  undo: (key: string) => `/settings/values/${encodeURIComponent(key)}/undo`,
  history: (key: string) => `/settings/values/${encodeURIComponent(key)}/history`,
  presetPreview: (preset: string) => `/settings/presets/${preset}/preview`,
  presetApply: (preset: string) => `/settings/presets/${preset}/apply`,
  uploads: "/settings/uploads",
} as const;
