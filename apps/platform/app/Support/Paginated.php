<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Pagination metadata with the same shape as API resource collections.
 */
class Paginated
{
    /**
     * @param  LengthAwarePaginator<array-key, mixed>  $paginator
     * @return array{current_page: int, last_page: int, from: int|null, to: int|null, total: int, per_page: int, links: array<int, mixed>}
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'links' => $paginator->linkCollection()->all(),
        ];
    }
}
