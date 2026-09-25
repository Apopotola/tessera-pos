<?php

namespace Modules\AuditTrail\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AuditTrail\Models\AuditLog;

/** @mixin AuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurredAt' => $this->occurred_at?->toIso8601String(),
            'action' => $this->action,
            'entityType' => $this->entity_type,
            'entityId' => $this->entity_id,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'approver' => $this->approver ? ['id' => $this->approver->id, 'name' => $this->approver->name] : null,
            'branchId' => $this->branch_id,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'ipAddress' => $this->ip_address,
        ];
    }
}
