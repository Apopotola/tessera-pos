/**
 * Mirrors Modules\Authorization\Support\Permissions (backend is the source of truth).
 * Use with usePermissions().can(PERMISSIONS.X) to show or hide UI; the API re-checks every request.
 */
export const PERMISSIONS = {
  DASHBOARD_VIEW: "dashboard.view",
  SALES_SELL: "sales.sell",
  SALES_VIEW: "sales.view",
  CATALOGUE_VIEW: "catalogue.view",
  CATALOGUE_MANAGE: "catalogue.manage",
  PRICES_MANAGE: "catalogue.prices.manage",
  PRICES_APPROVE: "catalogue.prices.approve",
  INVENTORY_VIEW: "inventory.view",
  PURCHASING_VIEW: "purchasing.view",
  CUSTOMERS_VIEW: "customers.view",
  REPORTS_VIEW: "reports.view",
  COMPLIANCE_VIEW: "compliance.view",
  ORGANISATION_MANAGE: "organisation.manage",
  USERS_MANAGE: "users.manage",
  AUDIT_VIEW: "audit.view",
} as const;

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
