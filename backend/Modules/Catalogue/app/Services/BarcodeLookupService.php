<?php

namespace Modules\Catalogue\Services;

use Modules\Catalogue\Models\Barcode;

/** Resolves a scanned code to exactly one variant, and the pack when it is a case/crate code. */
class BarcodeLookupService
{
    public function find(string $code): ?Barcode
    {
        return Barcode::query()
            ->where('code', trim($code))
            ->with(['variant.product.brand', 'variant.taxRate', 'pack'])
            ->first();
    }
}
