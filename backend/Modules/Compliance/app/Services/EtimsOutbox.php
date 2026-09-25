<?php

namespace Modules\Compliance\Services;

use Illuminate\Support\Facades\DB;
use Modules\Compliance\Jobs\SubmitEtimsDocument;
use Modules\Compliance\Models\EtimsSubmission;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;

/**
 * Transactional outbox: called inside the sale / return transaction, so a document can
 * never exist without its eTIMS job. Sending happens after commit, never inline.
 */
class EtimsOutbox
{
    public function queueSale(Sale $sale): EtimsSubmission
    {
        return $this->queue(EtimsSubmission::SALE, $sale->id, $sale->number, $sale->branch_id, null);
    }

    public function queueCreditNote(SaleReturn $return, Sale $original): EtimsSubmission
    {
        return $this->queue(EtimsSubmission::CREDIT_NOTE, $return->id, $return->number, $return->branch_id, $original->number);
    }

    private function queue(string $type, int $id, string $number, int $branchId, ?string $original): EtimsSubmission
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('eTIMS documents must be queued inside the transaction that creates them.');
        }

        $submission = EtimsSubmission::query()->create([
            'document_type' => $type,
            'document_id' => $id,
            'document_number' => $number,
            'original_document_number' => $original,
            'branch_id' => $branchId,
            'status' => EtimsSubmission::PENDING,
            'next_attempt_at' => now(),
        ]);

        // Try straight away once the sale is committed; the scheduler retries anything left.
        DB::afterCommit(fn () => SubmitEtimsDocument::dispatchAfterResponse($submission->id));

        return $submission;
    }
}
