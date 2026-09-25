<?php

namespace Modules\Organisation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;

class PairTillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::ORGANISATION_MANAGE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:80'],
            'defaultFloatCents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ];
    }
}
