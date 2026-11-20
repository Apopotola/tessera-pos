<?php

namespace Modules\Catalogue\Http\Requests;

use Modules\Authorization\Support\Permissions;

class ProductImageRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::CATALOGUE_MANAGE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=100,min_height=100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.max' => 'The photo must be 8 MB or smaller.',
            'file.mimes' => 'Use a JPEG, PNG or WebP photo.',
            'file.dimensions' => 'The photo must be at least 100 × 100 pixels.',
        ];
    }
}
