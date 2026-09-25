<?php

namespace Modules\Catalogue\Http\Requests;

use Modules\Authorization\Support\Permissions;

/** Approve (note optional) or reject (note required) a pending price request. */
class ReviewPriceRequest extends PermissionRequest
{
    protected function permission(): string
    {
        return Permissions::PRICES_APPROVE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rejecting = str_ends_with((string) $this->route()?->getName(), '.reject');

        return [
            'note' => [$rejecting ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }
}
