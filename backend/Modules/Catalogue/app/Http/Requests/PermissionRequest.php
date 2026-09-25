<?php

namespace Modules\Catalogue\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Form request authorised by a single permission from Modules\Authorization\Support\Permissions. */
abstract class PermissionRequest extends FormRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permission());
    }
}
