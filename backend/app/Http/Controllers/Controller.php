<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller
{
    use ApiResponse, AuthorizesRequests;

    /**
     * The shared paginated shape (frontend Paginated<T>): {items, meta}.
     *
     * @param  class-string<JsonResource>|null  $resource  omit when items are already arrays
     * @return array{items: mixed, meta: array{currentPage: int, perPage: int, total: int, lastPage: int}}
     */
    protected function paginated(LengthAwarePaginator $page, ?string $resource = null): array
    {
        return [
            'items' => $resource ? $resource::collection($page->items()) : $page->items(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
        ];
    }
}
