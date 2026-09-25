/** Mirrors Modules\Reports. Money columns are integer cents. */
export type ReportGroup = "sales" | "inventory" | "financial" | "compliance";
export type ReportFilterKind = "dateRange" | "asAt" | "branch" | "category" | "brand" | "user" | "status";
export type ColumnType = "text" | "number" | "money" | "percent" | "date" | "datetime";

export interface ReportCatalogueItem {
  key: string;
  group: ReportGroup;
  title: string;
  description: string;
  /** Existing screen that already is this report. */
  link: { view: string; path: string; title: string } | null;
}

export interface ReportCatalogue {
  reports: ReportCatalogueItem[];
  canExport: boolean;
  branches: { id: number; name: string }[];
  staff: { id: number; name: string }[];
}

export interface ReportColumn {
  key: string;
  label: string;
  type: ColumnType;
  total: boolean;
}

export type ReportCell = string | number | null;

export interface ReportRun {
  report: {
    key: string;
    title: string;
    group: ReportGroup;
    description: string;
    filters: ReportFilterKind[];
    groupings: Record<string, string>;
    statuses: Record<string, string>;
  };
  filters: { from: string; to: string; asAt: string; groupBy: string | null; status: string | null };
  columns: ReportColumn[];
  rows: Record<string, ReportCell>[];
  rowCount: number;
  truncated: boolean;
  totals: Record<string, number> | null;
  notes: string[];
}

export interface ReportQuery {
  from?: string;
  to?: string;
  asAt?: string;
  branchId?: number;
  categoryId?: number;
  brandId?: number;
  userId?: number;
  groupBy?: string;
  status?: string;
}
