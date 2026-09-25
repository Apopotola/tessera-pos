<?php

namespace Modules\Customers\Http\Requests;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Customers\Models\Customer;

/** POST /customers (create) and PUT /customers/{customer} (update). */
class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::CUSTOMERS_MANAGE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $customer = $this->route('customer');
        $ignore = $customer instanceof Customer ? $customer->id : null;

        return [
            'name' => ['required', 'string', 'max:150'],
            'kraPin' => [
                'nullable', 'string', 'regex:/^[A-Za-z]\d{9}[A-Za-z]$/',
                Rule::unique('customers', 'kra_pin')->ignore($ignore),
            ],
            'isWholesale' => ['sometimes', 'boolean'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($value && ! PhoneNumber::normalize((string) $value)) {
                    $fail('Enter a Kenyan mobile number like 0712 345 678.');
                }
            }],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'kraPin.regex' => 'A KRA PIN looks like P051234567X.',
            'kraPin.unique' => 'Another customer already has this KRA PIN.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('kraPin')) {
            $this->merge(['kraPin' => strtoupper(trim((string) $this->input('kraPin')))]);
        }
    }
}
