<?php

namespace Modules\Auth\Http\Requests;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Modules\Auth\Models\User;
use Modules\Authorization\Support\Permissions;
use Modules\Authorization\Support\Roles;

/** POST /auth/users (create) or PUT /auth/users/{user} (update). */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::USERS_MANAGE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $target = $this->route('user');
        $ignoreId = $target instanceof User ? $target->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($ignoreId)],
            'phone' => [
                'nullable', 'string', 'max:20',
                function (string $attribute, mixed $value, Closure $fail) use ($ignoreId) {
                    $normalized = PhoneNumber::normalize((string) $value);
                    if (! $normalized) {
                        $fail('Enter a Kenyan mobile number, e.g. 0712 345 678.');
                    } elseif (User::query()->where('phone', $normalized)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
                        $fail('Another user already has this phone number.');
                    }
                },
            ],
            'role' => ['required', Rule::in(array_keys(Roles::defaults()))],
            'branchIds' => ['sometimes', 'array'],
            'branchIds.*' => ['integer', Rule::exists('branches', 'id')],
            'password' => $ignoreId ? ['prohibited'] : ['nullable', 'string', Password::min(8)],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
