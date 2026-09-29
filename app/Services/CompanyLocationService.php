<?php

namespace App\Services;

use App\Models\CompanyLocation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyLocationService
{
    private ?bool $ready = null;

    /**
     * Creates company_locations and organization_assignments.location_id when missing.
     * Call before opening a DB transaction: DDL commits implicitly in MySQL.
     */
    public function ensureSchema(): void
    {
        if (!Schema::hasTable('company_locations')) {
            Schema::create('company_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('name', 191);
                $table->string('address', 255)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasColumn('organization_assignments', 'location_id')) {
            Schema::table('organization_assignments', function (Blueprint $table) {
                $table->unsignedBigInteger('location_id')->nullable()->index();
            });
        }

        $this->ready = true;
    }

    /** True when locations can be read and assigned without altering the schema. */
    public function ready(): bool
    {
        return $this->ready ??= Schema::hasTable('company_locations')
            && Schema::hasColumn('organization_assignments', 'location_id');
    }

    /** Location is mandatory in Employee Master only for companies that have locations set up. */
    public function companyHasLocations($companyId): bool
    {
        return $this->ready()
            && is_numeric($companyId)
            && CompanyLocation::where('company_id', (int) $companyId)->exists();
    }

    /**
     * Resolves the location id sent from Employee Master. Blank clears the location;
     * a location from another company is rejected.
     */
    public function resolveForCompany($locationId, $companyId): ?int
    {
        if ($locationId === null || $locationId === '' || !is_numeric($locationId)) {
            return null;
        }

        $location = CompanyLocation::find((int) $locationId);
        if (!$location || (int) $location->company_id !== (int) $companyId) {
            throw new HttpException(422, 'Choose a location that belongs to the selected company.');
        }

        return $location->id;
    }
}
