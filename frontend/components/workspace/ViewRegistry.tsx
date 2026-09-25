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

  // POS / Sales (Sales module — not built yet)
  posTill: PlaceholderView,
  salesList: PlaceholderView,
  shiftsList: PlaceholderView,

  // Catalogue (not built yet)
  productsList: PlaceholderView,

  // Inventory (not built yet)
  stockOnHand: PlaceholderView,
  stockLedger: PlaceholderView,

  // Purchasing (not built yet)
  suppliersList: PlaceholderView,

  // Customers (not built yet)
  customersList: PlaceholderView,

  // Compliance / eTIMS (not built yet)
  etimsMonitor: PlaceholderView,

  // Reports (not built yet)
  reportsHome: PlaceholderView,

  // Administration
  branchesList: lazyView(() => import("@/modules/organisation/views/BranchesView")),
  usersList: PlaceholderView,
  auditLog: lazyView(() => import("@/modules/audit-trail/views/AuditLogView")),
};

export const REGISTERED_VIEW_TYPES = Object.keys(ViewRegistry);

export function getViewComponent(viewType: string): ComponentType<WorkspaceViewProps> {
  return ViewRegistry[viewType] ?? PlaceholderView;
}
