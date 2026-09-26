<?php

namespace Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Authorization\Support\Permissions;
use Modules\Settings\Services\SettingsService;

/** Scope for reading or changing settings: business, or a branch / till by id. The value itself is validated by SettingsService. */
class SettingScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canAny([Permissions::SETTINGS_BRANCH, Permissions::SETTINGS_BUSINESS, Permissions::SETTINGS_PLATFORM]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', Rule::in(SettingsService::SCOPES)],
            'scopeId' => ['sometimes', 'integer', 'min:0'],
            'value' => ['nullable'],
            'overwriteYourChanges' => ['sometimes', 'boolean'],
        ];
    }

    public function scope(): string
    {
        return (string) $this->input('scope', 'business');
    }

    public function scopeId(): int
    {
        return $this->scope() === 'business' ? 0 : (int) $this->input('scopeId', 0);
    }
}
