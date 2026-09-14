<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class ContractEmployeeScope
{
    public static function sqlExclude(string $empAlias = 'e'): string
    {
        return " AND NOT EXISTS (
            SELECT 1 FROM employment_types cet
            WHERE cet.id = {$empAlias}.employment_type_id
              AND LOWER(cet.name) LIKE '%contract%'
        )";
    }

    public static function exclude(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('employment_type_id')
                ->orWhereDoesntHave('employmentType', function (Builder $t) {
                    $t->whereRaw("LOWER(name) LIKE '%contract%'");
                });
        });
    }

    public static function only(Builder $query): Builder
    {
        return $query->whereHas('employmentType', function (Builder $t) {
            $t->whereRaw("LOWER(name) LIKE '%contract%'");
        });
    }

    public static function excludeRelated($query, string $relation = 'employee'): mixed
    {
        return $query->whereHas($relation, function (Builder $emp) {
            self::exclude($emp);
        });
    }
}
