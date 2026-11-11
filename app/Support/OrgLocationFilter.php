<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Apply company_id / location_id filters via employee.organizationAssignment. */
class OrgLocationFilter
{
    public static function applyEmployeeOrg(Builder $query, Request $request, string $employeeRelation = 'employee'): Builder
    {
        $companyId = $request->query('company_id');
        $locationId = $request->query('location_id');
        if (($companyId === null || $companyId === '') && ($locationId === null || $locationId === '')) {
            return $query;
        }

        return $query->whereHas($employeeRelation.'.organizationAssignment', function ($q) use ($companyId, $locationId) {
            if ($companyId !== null && $companyId !== '') {
                $q->where('company_id', (int) $companyId);
            }
            if ($locationId !== null && $locationId !== '') {
                $q->where('location_id', (int) $locationId);
            }
        });
    }
}
