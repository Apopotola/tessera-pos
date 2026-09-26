"use client";

import dynamic from "next/dynamic";
import type { ComponentType } from "react";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import ViewLoadingFallback from "@/components/workspace/ViewLoadingFallback";

function lazyView(loader: () => Promise<{ default: ComponentType<WorkspaceViewProps> }>): ComponentType<WorkspaceViewProps> {
  return dynamic(loader, { loading: ViewLoadingFallback });
}

const PlaceholderView = lazyView(() => import("@/components/workspace/PlaceholderView"));

/**
 * viewType → screen. Keys MUST match `view_type` values seeded by
 * backend/Modules/Authorization/database/seeders/AuthorizationDatabaseSeeder.php.
 * Unbuilt modules point at PlaceholderView until their screens exist.
 */
const ViewRegistry: Record<string, ComponentType<WorkspaceViewProps>> = {
  // Dashboard
  dashboard: lazyView(() => import("@/modules/dashboard/views/DashboardView")),

  // POS / Sales (sales list and shifts screens come with the Sales module)
  posTill: lazyView(() => import("@/modules/sales/views/PosTillView")),
  salesList: lazyView(() => import("@/modules/sales/views/SalesListView")),
  shiftsList: lazyView(() => import("@/modules/sales/views/ShiftsListView")),

  // Catalogue
  productsList: lazyView(() => import("@/modules/catalogue/views/ProductsView")),
  productDetail: lazyView(() => import("@/modules/catalogue/views/ProductDetailView")), // record tab, not a menu item
  priceChanges: lazyView(() => import("@/modules/catalogue/views/PriceChangesView")),
  catalogueSetup: lazyView(() => import("@/modules/catalogue/views/CatalogueSetupView")),

  // Inventory
  stockOnHand: lazyView(() => import("@/modules/inventory/views/StockOnHandView")),
  stockAdjustments: lazyView(() => import("@/modules/inventory/views/AdjustmentsView")),
  stockTransfers: lazyView(() => import("@/modules/inventory/views/TransfersView")),
  stockCounts: lazyView(() => import("@/modules/inventory/views/StockCountsView")),
  stockCountSheet: lazyView(() => import("@/modules/inventory/views/StockCountSheetView")), // record tab, not a menu item
  stockLedger: lazyView(() => import("@/modules/inventory/views/StockLedgerView")),
  openBottles: lazyView(() => import("@/modules/sales/views/OpenBottlesView")),

  // Purchasing
  purchaseOrders: lazyView(() => import("@/modules/purchasing/views/PurchaseOrdersView")),
  purchaseOrderDetail: lazyView(() => import("@/modules/purchasing/views/PurchaseOrderDetailView")), // record tab, not a menu item
  supplierInvoices: lazyView(() => import("@/modules/purchasing/views/SupplierInvoicesView")),
  supplierReturns: lazyView(() => import("@/modules/purchasing/views/SupplierReturnsView")),
  suppliersList: lazyView(() => import("@/modules/purchasing/views/SuppliersView")),

  // Customers (not built yet)
  mpesaReconciliation: lazyView(() => import("@/modules/payments/views/MpesaReconciliationView")),
  customersList: lazyView(() => import("@/modules/customers/views/CustomersView")),
  customerDetail: lazyView(() => import("@/modules/customers/views/CustomerDetailView")), // record tab, not a menu item

  // Compliance / eTIMS (not built yet)
  etimsMonitor: lazyView(() => import("@/modules/compliance/views/EtimsMonitorView")),

  // Reports (not built yet)
  reportsHome: lazyView(() => import("@/modules/reports/views/ReportsHomeView")),
  reportView: lazyView(() => import("@/modules/reports/views/ReportView")), // record tab, not a menu item

  // Administration
  branchesList: lazyView(() => import("@/modules/organisation/views/BranchesView")),
  usersList: lazyView(() => import("@/modules/users/views/UsersView")),
  auditLog: lazyView(() => import("@/modules/audit-trail/views/AuditLogView")),
  settings: lazyView(() => import("@/modules/settings/views/SettingsView")),
};

export const REGISTERED_VIEW_TYPES = Object.keys(ViewRegistry);

export function getViewComponent(viewType: string): ComponentType<WorkspaceViewProps> {
  return ViewRegistry[viewType] ?? PlaceholderView;
}
