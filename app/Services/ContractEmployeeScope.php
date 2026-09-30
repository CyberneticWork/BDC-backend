<?php

namespace App\Services;

use App\Models\company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ContractEmployeeScope
{
    private static ?array $permanentProcessCompanyIds = null;

    /** Companies where Cybernetic Admin enabled `contract_as_permanent`; their contract employees are not excluded. */
    public static function permanentProcessCompanyIds(): array
    {
        if (self::$permanentProcessCompanyIds !== null) {
            return self::$permanentProcessCompanyIds;
        }
        if (!Schema::hasColumn('companies', 'process_config')) {
            return self::$permanentProcessCompanyIds = [];
        }

        return self::$permanentProcessCompanyIds = company::query()
            ->whereNotNull('process_config')
            ->get(['id', 'process_config'])
            ->filter(fn (company $c) => CompanyProcessSettings::usesContractAsPermanent($c))
            ->map(fn (company $c) => (int) $c->id)
            ->values()
            ->all();
    }

    public static function sqlExclude(string $empAlias = 'e'): string
    {
        $companyIds = self::permanentProcessCompanyIds();
        $allowed = $companyIds
            ? " OR EXISTS (
                SELECT 1 FROM organization_assignments coa
                WHERE coa.id = {$empAlias}.organization_assignment_id
                  AND coa.company_id IN (".implode(',', $companyIds).')
            )'
            : '';

        return " AND (NOT EXISTS (
            SELECT 1 FROM employment_types cet
            WHERE cet.id = {$empAlias}.employment_type_id
              AND LOWER(cet.name) LIKE '%contract%'
        ){$allowed})";
    }

    public static function exclude(Builder $query): Builder
    {
        $companyIds = self::permanentProcessCompanyIds();

        return $query->where(function (Builder $q) use ($companyIds) {
            $q->whereNull('employment_type_id')
                ->orWhereDoesntHave('employmentType', function (Builder $t) {
                    $t->whereRaw("LOWER(name) LIKE '%contract%'");
                });
            if ($companyIds) {
                $q->orWhereHas('organizationAssignment', function (Builder $oa) use ($companyIds) {
                    $oa->whereIn('company_id', $companyIds);
                });
            }
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
