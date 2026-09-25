<?php

namespace Modules\Catalogue\Http\Requests;

use Modules\Authorization\Support\Permissions;

class UpdateProductRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ProductRules::product(),
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
