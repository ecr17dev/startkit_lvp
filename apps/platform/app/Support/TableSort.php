<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

/**
 * Sorting of server-side tables (?sort=column&direction=asc|desc), the
 * counterpart of useTableQuery and the kit DataTable. Only the listed
 * columns can be sorted, so the query string never reaches the SQL as is.
 *
 *     $filters = $request->validate([...TableSort::rules(['name', 'created_at'])]);
 *     TableSort::apply($query, $filters, ['name', 'created_at'], default: 'created_at');
 */
final class TableSort
{
    /**
     * Validation rules of the sort parameters.
     *
     * @param  list<string>  $columns
     * @return array<string, list<mixed>>
     */
    public static function rules(array $columns): array
    {
        return [
            'sort' => ['nullable', 'string', Rule::in($columns)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * Order the query by the requested column, or by the default one. The
     * primary key breaks ties so the pages stay stable.
     *
     * @template TBuilder of QueryBuilder
     *
     * @param  TBuilder  $query
     * @param  array<string, mixed>  $filters  validated input
     * @param  list<string>  $columns
     * @return TBuilder
     */
    public static function apply(
        QueryBuilder $query,
        array $filters,
        array $columns,
        string $default = 'id',
        string $defaultDirection = 'desc',
        string $tieBreaker = 'id',
    ): QueryBuilder {
        $column = $filters['sort'] ?? null;
        $sorted = is_string($column) && in_array($column, $columns, true);
        $direction = $sorted ? ($filters['direction'] ?? 'asc') : $defaultDirection;
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sorted ? $column : $default, $direction);

        if (($sorted ? $column : $default) !== $tieBreaker) {
            $query->orderBy($tieBreaker, $direction);
        }

        return $query;
    }
}
