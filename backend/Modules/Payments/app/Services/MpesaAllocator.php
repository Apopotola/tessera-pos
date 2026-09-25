<?php

namespace Modules\Payments\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AuditTrail\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Payments\Models\MpesaConfirmation;
use Modules\Sales\Models\SaleTender;

/**
 * Ties an M-PESA confirmation to a tender — the only way an M-PESA tender becomes
 * confirmed. At the till this happens inside the sale; afterwards (for tenders taken
 * while M-PESA was in manual mode) a manager matches them from the reconciliation screen.
 */
class MpesaAllocator
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Checks a confirmation before the sale is written. */
    public function assertUsable(int $confirmationId, int $amountCents, int $branchId, string $field): MpesaConfirmation
    {
        $confirmation = MpesaConfirmation::query()->find($confirmationId)
            ?? throw ValidationException::withMessages([$field => 'This M-PESA payment was not found.']);

        if ($confirmation->tender_id !== null) {
            throw ValidationException::withMessages([$field => "M-PESA payment {$confirmation->receipt} is already used on another sale."]);
        }
        if ($confirmation->branch_id !== null && $confirmation->branch_id !== $branchId) {
            throw ValidationException::withMessages([$field => "M-PESA payment {$confirmation->receipt} was taken at another branch."]);
        }
        if ($confirmation->amount_cents !== $amountCents) {
            throw ValidationException::withMessages([$field => 'The M-PESA amount ('.number_format($confirmation->amount_cents / 100, 2).') does not match this payment.']);
        }

        return $confirmation;
    }

    /** Inside the sale transaction, after the tender row exists. */
    public function allocate(int $confirmationId, SaleTender $tender, User $user): MpesaConfirmation
    {
        $confirmation = MpesaConfirmation::query()->lockForUpdate()->findOrFail($confirmationId);
        if ($confirmation->tender_id !== null) {
            throw ValidationException::withMessages(['tenders' => "M-PESA payment {$confirmation->receipt} is already used on another sale."]);
        }

        $confirmation->forceFill([
            'tender_id' => $tender->id,
            'branch_id' => $confirmation->branch_id ?? $tender->shift->branch_id,
            'allocated_by' => $user->id,
            'allocated_at' => CarbonImmutable::now(),
        ])->save();

        return $confirmation;
    }

    /** Back office: match a customer-initiated payment to an unverified M-PESA tender. */
    public function reconcile(MpesaConfirmation $confirmation, SaleTender $tender, User $user): MpesaConfirmation
    {
        if (! $user->can(Permissions::PAYMENTS_RECONCILE)) {
            throw new AuthorizationException;
        }
        if ($tender->method !== SaleTender::MPESA || $tender->status !== SaleTender::UNVERIFIED || $tender->amount_cents <= 0) {
            throw ValidationException::withMessages(['tenderId' => 'Only unverified M-PESA payments can be matched.']);
        }
        if (MpesaConfirmation::query()->where('tender_id', $tender->id)->exists()) {
            throw ValidationException::withMessages(['tenderId' => 'This payment is already matched.']);
        }

        return DB::transaction(function () use ($confirmation, $tender, $user) {
            $this->assertUsable($confirmation->id, $tender->amount_cents, $tender->shift->branch_id, 'confirmationId');
            $allocated = $this->allocate($confirmation->id, $tender, $user);

            $this->audit->log('payments.mpesa.reconciled', $allocated, after: [
                'receipt' => $allocated->receipt,
                'tender_id' => $tender->id,
                'sale_id' => $tender->sale_id,
                'typed_reference' => $tender->reference,
            ], userId: $user->id, branchId: $tender->shift->branch_id);

            return $allocated;
        });
    }
}
