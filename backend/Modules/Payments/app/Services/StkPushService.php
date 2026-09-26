<?php

namespace Modules\Payments\Services;

use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Models\User;
use Modules\Organisation\Models\Till;
use Modules\Payments\Contracts\MpesaGateway;
use Modules\Payments\Models\MpesaConfirmation;
use Modules\Payments\Models\MpesaStkRequest;
use Modules\Payments\Support\StkStatus;
use Modules\Settings\Services\SettingsService;
use Throwable;

/**
 * M-PESA Express from the till: send the prompt, then learn the result from Daraja's
 * callback — or, if it is late, by asking Daraja — so a cashier never retries (and
 * double-charges) a request that may still succeed.
 */
class StkPushService
{
    /** Daraja rate-limits status queries; do not ask more often than this. */
    private const QUERY_INTERVAL_SECONDS = 10;

    public function __construct(
        private readonly MpesaGateway $gateway,
        private readonly SettingsService $settings,
    ) {}

    public function initiate(Till $till, User $cashier, string $phoneInput, int $amountCents): MpesaStkRequest
    {
        if (! $this->settings->get('payments.stk_push', $till->branch_id, $till->id)) {
            throw ValidationException::withMessages(['phone' => 'Payment requests to the phone are switched off. Pick the customer\'s payment instead.']);
        }

        $phone = PhoneNumber::normalize($phoneInput)
            ?? throw ValidationException::withMessages(['phone' => 'Enter a Safaricom number like 0712 345 678.']);
        if ($amountCents < 100 || $amountCents % 100 !== 0) {
            throw ValidationException::withMessages(['amountCents' => 'M-PESA takes whole shillings. Take any cents in cash.']);
        }

        // One prompt at a time per till: the last one may still be paid.
        $pending = MpesaStkRequest::query()->where('till_id', $till->id)->where('status', StkStatus::PENDING)
            ->where('created_at', '>', now()->subSeconds($this->timeout() * 2))->exists();
        if ($pending) {
            throw ValidationException::withMessages(['phone' => 'Wait for the last M-PESA request to finish before sending another.']);
        }

        $msisdn = ltrim($phone, '+');
        $request = MpesaStkRequest::query()->create([
            'branch_id' => $till->branch_id,
            'till_id' => $till->id,
            'user_id' => $cashier->id,
            'phone' => $msisdn,
            'phone_masked' => MpesaConfirmation::mask($msisdn),
            'amount_cents' => $amountCents,
            'status' => StkStatus::PENDING,
        ]);

        try {
            $ids = $this->gateway->stkPush($msisdn, $amountCents, $till->branch->code, 'Tessera sale');
        } catch (Throwable $e) {
            Log::warning('M-PESA STK push failed', ['request' => $request->id, 'error' => $e->getMessage()]);
            $request->forceFill(['status' => StkStatus::FAILED, 'result_description' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw ValidationException::withMessages(['phone' => 'M-PESA could not send the prompt: '.$e->getMessage()]);
        }

        $request->forceFill(['merchant_request_id' => $ids['merchantRequestId'], 'checkout_request_id' => $ids['checkoutRequestId']])->save();

        return $request;
    }

    /** Current state for the till; asks Daraja when the callback is late. */
    public function refresh(MpesaStkRequest $request): MpesaStkRequest
    {
        if ($request->status !== StkStatus::PENDING || ! $request->checkout_request_id) {
            return $request;
        }

        $age = $request->created_at->diffInSeconds(now());
        $lastAsked = $request->last_checked_at?->diffInSeconds(now()) ?? PHP_INT_MAX;
        $fakeDriver = config('payments.mpesa.driver') === 'fake';
        if (! $fakeDriver && ($age < min(20, $this->timeout()) || $lastAsked < self::QUERY_INTERVAL_SECONDS)) {
            return $request;
        }

        try {
            $status = $this->gateway->stkQuery($request->checkout_request_id);
        } catch (Throwable $e) {
            Log::warning('M-PESA STK query failed', ['request' => $request->id, 'error' => $e->getMessage()]);

            return $request;
        }

        $request->forceFill(['last_checked_at' => now()])->save();

        // A paid query without a receipt still waits for the callback, which carries it.
        if ($status->state === StkStatus::FAILED || ($status->state === StkStatus::PAID && $status->receipt)) {
            return $this->complete($request, $status);
        }

        return $request;
    }

    /** Daraja callback: Body.stkCallback. */
    public function handleCallback(array $callback): void
    {
        $checkout = (string) ($callback['CheckoutRequestID'] ?? '');
        $request = MpesaStkRequest::query()->where('checkout_request_id', $checkout)->first();
        if (! $request) {
            Log::warning('M-PESA STK callback for an unknown request', ['checkout' => $checkout]);

            return;
        }

        $this->complete($request, StkStatus::fromCallback($callback));
    }

    private function complete(MpesaStkRequest $request, StkStatus $status): MpesaStkRequest
    {
        return DB::transaction(function () use ($request, $status) {
            $request = MpesaStkRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== StkStatus::PENDING) {
                return $request; // callback and query raced; the first one wins
            }

            $confirmationId = null;
            if ($status->state === StkStatus::PAID && $status->receipt) {
                $confirmation = MpesaConfirmation::query()->firstOrCreate(['receipt' => $status->receipt], [
                    'source' => MpesaConfirmation::SOURCE_STK,
                    'amount_cents' => $status->amountCents ?? $request->amount_cents,
                    'phone_masked' => $request->phone_masked,
                    'shortcode' => config('payments.mpesa.shortcode'),
                    'transacted_at' => $this->transactedAt($status->transactedAt),
                    'branch_id' => $request->branch_id,
                    'payload' => $status->payload,
                ]);
                $confirmationId = $confirmation->id;
            }

            $request->forceFill([
                'status' => $confirmationId ? StkStatus::PAID : StkStatus::FAILED,
                'result_code' => $status->resultCode,
                'result_description' => mb_substr((string) $status->resultDescription, 0, 255) ?: null,
                'confirmation_id' => $confirmationId,
            ])->save();

            return $request;
        });
    }

    private function transactedAt(?string $value): CarbonImmutable
    {
        return $value ? CarbonImmutable::createFromFormat('YmdHis', $value, 'Africa/Nairobi') ?: CarbonImmutable::now() : CarbonImmutable::now();
    }

    private function timeout(): int
    {
        return (int) config('payments.mpesa.stk_timeout_seconds', 60);
    }
}
