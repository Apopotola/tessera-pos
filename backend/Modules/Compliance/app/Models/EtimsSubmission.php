<?php

namespace Modules\Compliance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organisation\Models\Branch;

/** One sales invoice or credit note on its way to KRA eTIMS (transactional outbox). */
class EtimsSubmission extends Model
{
    public const SALE = 'sale';

    public const CREDIT_NOTE = 'credit_note';

    public const PENDING = 'pending';

    public const SIGNED = 'signed';

    /** Temporary: KRA unreachable or timed out. Retried with back-off. */
    public const FAILED = 'failed';

    /** KRA (or our pre-check) refused the data. Needs fixing, then a manual retry. */
    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'kra_signed_at' => 'immutable_datetime',
            'request' => 'array',
            'response' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** What the receipt prints. */
    public function receiptArray(): array
    {
        return [
            'status' => $this->status,
            'invoiceNumber' => $this->kra_invoice_number,
            'signature' => $this->kra_signature,
            'internalData' => $this->kra_internal_data,
            'qrPayload' => $this->qr_payload,
            'scuId' => $this->scu_id,
            'signedAt' => $this->kra_signed_at?->toIso8601String(),
        ];
    }
}
