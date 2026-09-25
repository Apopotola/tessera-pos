<?php

namespace Modules\Compliance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Compliance\Contracts\EtimsGateway;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Compliance\Support\EtimsResult;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;
use Throwable;

/**
 * Sends one outbox row to KRA and records the answer on the submission and on the sale
 * or return (etims_status). Temporary failures back off 1, 2, 4 … 30 minutes; refusals
 * wait for someone to fix the data. A timed-out invoice is looked up before resending.
 */
class EtimsProcessor
{
    public function __construct(
        private readonly EtimsGateway $gateway,
        private readonly EtimsPayloadBuilder $payloads,
    ) {}

    public function enabled(): bool
    {
        return config('compliance.etims.driver') !== 'disabled';
    }

    public function process(int $submissionId): ?EtimsSubmission
    {
        if (! $this->enabled()) {
            return null;
        }

        return DB::transaction(function () use ($submissionId) {
            $submission = EtimsSubmission::query()->lockForUpdate()->find($submissionId);
            if (! $submission || in_array($submission->status, [EtimsSubmission::SIGNED, EtimsSubmission::REJECTED], true)) {
                return $submission;
            }

            // A credit note needs its original invoice signed first.
            $originalKra = null;
            if ($submission->document_type === EtimsSubmission::CREDIT_NOTE) {
                $original = EtimsSubmission::query()->where('document_type', EtimsSubmission::SALE)->where('document_number', $submission->original_document_number)->first();
                if ($original?->status !== EtimsSubmission::SIGNED) {
                    return $this->record($submission, EtimsResult::retry('Waiting for the original invoice to be signed.'), null);
                }
                $originalKra = $original->kra_invoice_number;
            }

            $payload = null;
            try {
                $payload = $submission->document_type === EtimsSubmission::SALE
                    ? $this->payloads->forSale(Sale::query()->findOrFail($submission->document_id))
                    : $this->payloads->forCreditNote(SaleReturn::query()->findOrFail($submission->document_id), $originalKra);

                // After a failed attempt KRA may have accepted it anyway; never sign twice.
                $result = ($submission->attempts > 0 ? $this->gateway->find($submission->document_number) : null)
                    ?? $this->gateway->submit($payload);
            } catch (Throwable $e) {
                Log::warning('eTIMS submission error', ['submission' => $submission->id, 'error' => $e->getMessage()]);
                $result = EtimsResult::retry($e->getMessage());
            }

            return $this->record($submission, $result, $payload);
        });
    }

    /** Due rows, oldest first. */
    public function processDue(int $limit = 100): int
    {
        $ids = EtimsSubmission::query()
            ->whereIn('status', [EtimsSubmission::PENDING, EtimsSubmission::FAILED])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $this->process($id);
        }

        return $ids->count();
    }

    /** Manual retry after fixing data (or to skip the back-off). */
    public function retry(EtimsSubmission $submission): ?EtimsSubmission
    {
        if ($submission->status === EtimsSubmission::SIGNED) {
            return $submission;
        }
        $submission->forceFill(['status' => EtimsSubmission::PENDING, 'next_attempt_at' => now()])->save();

        return $this->process($submission->id);
    }

    private function record(EtimsSubmission $submission, EtimsResult $result, ?array $payload): EtimsSubmission
    {
        $attempts = $submission->attempts + 1;
        $fields = ['attempts' => $attempts, 'request' => $payload ?? $submission->request, 'response' => $result->response ?: $submission->response];

        $fields += match ($result->outcome) {
            EtimsResult::SIGNED => [
                'status' => EtimsSubmission::SIGNED,
                'kra_invoice_number' => $result->invoiceNumber,
                'kra_signature' => $result->signature,
                'kra_internal_data' => $result->internalData,
                'qr_payload' => $result->qrPayload,
                'scu_id' => $result->scuId,
                'kra_signed_at' => now(),
                'last_error' => null,
                'next_attempt_at' => null,
            ],
            EtimsResult::REJECTED => ['status' => EtimsSubmission::REJECTED, 'last_error' => $result->error, 'next_attempt_at' => null],
            default => [
                'status' => EtimsSubmission::FAILED,
                'last_error' => $result->error,
                'next_attempt_at' => now()->addMinutes(min(2 ** ($attempts - 1), (int) config('compliance.etims.max_backoff_minutes', 30))),
            ],
        };

        $submission->forceFill($fields)->save();

        // Mirror onto the document (the sales triggers allow only this field to change).
        $document = $submission->document_type === EtimsSubmission::SALE ? Sale::class : SaleReturn::class;
        $document::query()->whereKey($submission->document_id)->update(['etims_status' => $submission->status]);

        return $submission;
    }
}
