import { api } from "@/api/client";
import { AUDIT_TRAIL_URLS } from "@/api/urls";
import type { Paginated } from "@/types/api";
import type { AuditLogEntry, AuditLogFilters } from "@/types/audit";

export const auditTrailApi = {
  logs: (filters: AuditLogFilters = {}) => api.get<Paginated<AuditLogEntry>>(AUDIT_TRAIL_URLS.logs, filters),
};
