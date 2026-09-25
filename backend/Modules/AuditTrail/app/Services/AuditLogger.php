<?php

namespace Modules\AuditTrail\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Modules\AuditTrail\Models\AuditLog;

/**
 * Writes audit entries. Call it inside the same DB transaction as the action
 * being audited so an action can never be committed without its log.
 */
class AuditLogger
{
    /** Keys never written to before/after snapshots. */
    private const REDACTED = ['password', 'remember_token', 'pin', 'pin_hash', 'token', 'secret'];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $entity = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $reference = null,
        ?int $userId = null,
        ?int $approverId = null,
        ?int $branchId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $userId ?? $this->request->user()?->getKey(),
            'approver_id' => $approverId,
            'branch_id' => $branchId,
            'action' => $action,
            'entity_type' => $entity?->getMorphClass(),
            'entity_id' => $entity?->getKey() !== null ? (string) $entity->getKey() : null,
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'reason' => $reason,
            'reference' => $reference,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        return $values === null ? null : Arr::except($values, self::REDACTED);
    }
}
