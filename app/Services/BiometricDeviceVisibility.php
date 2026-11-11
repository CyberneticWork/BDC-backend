<?php

namespace App\Services;

use App\Models\company;
use App\Models\HikvisionDevice;
use App\Models\ZktecoDevice;
use Illuminate\Support\Collection;

/**
 * Which fingerprint clocks a company may see on Time Card.
 * Cybernetic Admin stores the map in process_config.biometric_map.
 */
class BiometricDeviceVisibility
{
    public function mapFor(company $company): array
    {
        $cfg = is_array($company->process_config) ? $company->process_config : [];
        $map = is_array($cfg['biometric_map'] ?? null) ? $cfg['biometric_map'] : [];

        return [
            'enabled' => !empty($map['enabled']),
            'zkteco_device_ids' => array_values(array_unique(array_map('intval', $map['zkteco_device_ids'] ?? []))),
            'hikvision_device_ids' => array_values(array_unique(array_map('intval', $map['hikvision_device_ids'] ?? []))),
        ];
    }

    public function holidayCalendarEnabled(company $company): bool
    {
        $cfg = is_array($company->process_config) ? $company->process_config : [];
        $pack = $cfg['holiday_calendar'] ?? null;
        if (is_array($pack)) {
            return !empty($pack['enabled']);
        }

        return (bool) $pack;
    }

    /** @param  Collection<int, company>|array<int, company>  $companies */
    public function filterZktecoDevices(Collection $devices, Collection|array $companies): Collection
    {
        $companies = collect($companies);
        if ($companies->isEmpty()) {
            return $devices;
        }

        $anyMap = false;
        $allowedIds = [];
        $companyIds = [];
        foreach ($companies as $company) {
            if (!$company instanceof company) {
                continue;
            }
            $companyIds[] = (int) $company->id;
            $map = $this->mapFor($company);
            if ($map['enabled']) {
                $anyMap = true;
                $allowedIds = array_merge($allowedIds, $map['zkteco_device_ids']);
            }
        }
        $allowedIds = array_values(array_unique($allowedIds));
        $companyIds = array_values(array_unique($companyIds));

        return $devices->filter(function (ZktecoDevice $d) use ($anyMap, $allowedIds, $companyIds) {
            if ($anyMap) {
                return in_array((int) $d->id, $allowedIds, true)
                    || ($d->company_id && in_array((int) $d->company_id, $companyIds, true));
            }

            return $d->company_id === null || in_array((int) $d->company_id, $companyIds, true);
        })->values();
    }

    /** @param  Collection<int, company>|array<int, company>  $companies */
    public function filterHikvisionDevices(Collection $devices, Collection|array $companies): Collection
    {
        $companies = collect($companies);
        if ($companies->isEmpty()) {
            return $devices;
        }

        $anyMap = false;
        $allowedIds = [];
        $companyIds = [];
        foreach ($companies as $company) {
            if (!$company instanceof company) {
                continue;
            }
            $companyIds[] = (int) $company->id;
            $map = $this->mapFor($company);
            if ($map['enabled']) {
                $anyMap = true;
                $allowedIds = array_merge($allowedIds, $map['hikvision_device_ids']);
            }
        }
        $allowedIds = array_values(array_unique($allowedIds));
        $companyIds = array_values(array_unique($companyIds));

        return $devices->filter(function (HikvisionDevice $d) use ($anyMap, $allowedIds, $companyIds) {
            if ($anyMap) {
                return in_array((int) $d->id, $allowedIds, true)
                    || ($d->company_id && in_array((int) $d->company_id, $companyIds, true));
            }

            return $d->company_id === null || in_array((int) $d->company_id, $companyIds, true);
        })->values();
    }
}
