<?php

namespace Modules\Auth\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Authorization\Support\Permissions;

class SetPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::USERS_MANAGE);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'pin' => [
                'required', 'string', 'regex:/^\d{4}$/', 'confirmed',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (self::isGuessable((string) $value)) {
                        $fail('Choose a PIN that is not all the same digit or a straight sequence (e.g. 1111, 1234).');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['pin.regex' => 'The PIN must be exactly 4 digits.'];
    }

    /** 0000–9999 all-same digits and ascending/descending runs such as 1234 or 9876. */
    public static function isGuessable(string $pin): bool
    {
        $digits = array_map('intval', str_split($pin));
        $steps = array_unique(array_map(fn ($i) => $digits[$i + 1] - $digits[$i], range(0, count($digits) - 2)));

        return count($steps) === 1 && in_array(reset($steps), [0, 1, -1], true);
    }
}
