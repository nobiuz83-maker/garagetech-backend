<?php

namespace App\Services;

use App\Http\Requests\ListClientVehiclesRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ClientVehicleSearch
{
    /** @param array<int, string> $columns */
    public function applySearch(Builder $query, string $search, array $columns): void
    {
        $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms ?: [] as $term) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $matches) use ($columns, $pattern): void {
                foreach ($columns as $column) {
                    $matches->orWhereRaw($matches->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }
    }

    /** @param array<string, string> $sortColumns */
    public function paginate(Builder $query, ListClientVehiclesRequest $request, array $sortColumns): LengthAwarePaginator
    {
        $sort = $request->validated('sort', 'createdAt');
        if (! array_key_exists($sort, $sortColumns)) {
            throw ValidationException::withMessages(['sort' => 'The requested sort is not supported for this collection.']);
        }

        return $query->orderBy($sortColumns[$sort], $request->validated('direction', 'asc'))
            ->orderBy('id')->paginate((int) $request->validated('perPage', 100))->withQueryString();
    }
}
