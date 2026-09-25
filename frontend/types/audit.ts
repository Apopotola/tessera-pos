/** Mirrors Modules\AuditTrail\Http\Resources\AuditLogResource. */
export interface AuditUserRef {
  id: number;
  name: string;
}

export interface AuditLogEntry {
  id: number;
  occurredAt: string;
  action: string;
  entityType: string | null;
  entityId: string | null;
  user: AuditUserRef | null;
  approver: AuditUserRef | null;
  branchId: number | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  reason: string | null;
  reference: string | null;
  ipAddress: string | null;
}

export interface AuditLogFilters {
  action?: string;
  user_id?: number;
  page?: number;
  per_page?: number;
}
