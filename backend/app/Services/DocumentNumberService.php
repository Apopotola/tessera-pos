<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Modules\Organisation\Models\Branch;

/**
 * Gapless, per-branch document numbers. Must be called inside the transaction that
 * creates the document, so a rolled-back document does not consume a number.
 */
class DocumentNumberService
{
    /**
     * "MAIN-S-000001", or with an invoice prefix (Settings → Receipts) "INV-MAIN-000001".
     * The prefixed format keeps the same per-branch sequence.
     */
    public function next(Branch $branch, string $type, string $prefix = ''): string
    {
        DB::table('document_sequences')->insertOrIgnore([
            'branch_id' => $branch->id,
            'document_type' => $type,
            'last_number' => 0,
        ]);

        $row = DB::table('document_sequences')
            ->where(['branch_id' => $branch->id, 'document_type' => $type])
            ->lockForUpdate()
            ->first();

        $number = $row->last_number + 1;
        DB::table('document_sequences')
            ->where(['branch_id' => $branch->id, 'document_type' => $type])
            ->update(['last_number' => $number]);

        return $prefix !== ''
            ? sprintf('%s%s-%06d', $prefix, $branch->code, $number)
            : sprintf('%s-%s-%06d', $branch->code, $type, $number);
    }
}
