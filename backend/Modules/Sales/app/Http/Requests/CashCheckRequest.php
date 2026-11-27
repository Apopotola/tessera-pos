<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A manager's count of the drawer on a shared till that stays open. The service checks the permission. */
class CashCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'countedCashCents' => ['required_without:denominations', 'integer', 'min:0', 'max:100000000'],
            // Count by denomination: {"100000": 3, "50000": 1, …} (cents => pieces).
            'denominations' => ['nullable', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
