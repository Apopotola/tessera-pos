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
  INVENTORY_RECEIVE: "inventory.receive",
  INVENTORY_TRANSFER: "inventory.transfer",
  INVENTORY_TRANSFER_APPROVE: "inventory.transfer.approve",
  INVENTORY_ADJUST: "inventory.adjust",
  INVENTORY_BREAKAGE_REPORT: "inventory.breakage.report",
  INVENTORY_ADJUST_APPROVE: "inventory.adjust.approve",
  INVENTORY_COUNT: "inventory.count",
  INVENTORY_COUNT_APPROVE: "inventory.count.approve",
  REPORTS_PROFIT_VIEW: "reports.profit.view",
  PURCHASING_VIEW: "purchasing.view",
  PURCHASING_MANAGE: "purchasing.manage",
  PURCHASING_APPROVE: "purchasing.approve",
  PAYMENTS_VIEW: "payments.view",
  PAYMENTS_RECONCILE: "payments.reconcile",
  CUSTOMERS_VIEW: "customers.view",
  CUSTOMERS_MANAGE: "customers.manage",
  CUSTOMERS_PRIVACY: "customers.privacy",
  REPORTS_VIEW: "reports.view",
  REPORTS_EXPORT: "reports.export",
  COMPLIANCE_VIEW: "compliance.view",
  ORGANISATION_MANAGE: "organisation.manage",
  USERS_MANAGE: "users.manage",
  COMPLIANCE_MANAGE: "compliance.manage",
  AUDIT_VIEW: "audit.view",
} as const;

export type Permission = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];
