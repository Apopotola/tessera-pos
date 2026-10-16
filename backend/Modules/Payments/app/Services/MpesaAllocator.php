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
use Modules\Settings\Services\SettingsService;

/**
 * Ties an M-PESA confirmation to a tender — the only way an M-PESA tender becomes
 * confirmed. At the till this happens inside the sale; afterwards (for tenders taken
 * while M-PESA was in manual mode) a manager matches them from the reconciliation screen.
 */
class MpesaAllocator
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
    ) {}

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

    /**
     * An M-PESA code typed at an offline till, checked when the sale reaches the server: the customer's
     * payment must be in the pool (C2B confirmation), unused, for the same amount, not taken at another
     * branch, and paid to this shop's till or paybill number when that is set.
     *
     * @return array{confirmation: MpesaConfirmation|null, problem: string|null} confirmation when usable;
     *                                                                           neither when it has not arrived yet
     */
    public function matchOfflineCode(string $receipt, int $amountCents, int $branchId): array
    {
        $confirmation = MpesaConfirmation::query()->where('receipt', $receipt)->first();
        if (! $confirmation) {
            return ['confirmation' => null, 'problem' => null];
        }

        $ours = array_filter([$this->settings->get('payments.mpesa_shortcode'), $this->settings->get('payments.mpesa_number')]);
        $problem = match (true) {
            $confirmation->tender_id !== null => "M-PESA code {$receipt} is already used on another sale.",
            $confirmation->branch_id !== null && $confirmation->branch_id !== $branchId => "M-PESA code {$receipt} was taken at another branch.",
            $confirmation->amount_cents !== $amountCents => "M-PESA code {$receipt} is for KES ".number_format($confirmation->amount_cents / 100, 2).', not KES '.number_format($amountCents / 100, 2).'.',
            $ours !== [] && $confirmation->shortcode !== null && ! in_array($confirmation->shortcode, $ours, true) => "M-PESA code {$receipt} was paid to another till or paybill ({$confirmation->shortcode}).",
            default => null,
        };

        return ['confirmation' => $problem ? null : $confirmation, 'problem' => $problem];
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
