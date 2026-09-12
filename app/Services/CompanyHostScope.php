<?php

namespace App\Services;

use App\Models\company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CompanyHostScope
{
    public static function apply(Builder $query, Request $request): Builder
    {
        if ($request->boolean('all') && app(CyberneticAdminAuth::class)->checkRequest($request)) {
            return $query;
        }

        $seedId = null;

        if ($request->filled('company_id')) {
            $seedId = (int) $request->query('company_id');
        }

        if (!$seedId && $request->filled('slug') && Schema::hasColumn('companies', 'slug')) {
            $seedId = company::query()
                ->whereRaw('LOWER(slug) = ?', [strtolower((string) $request->query('slug'))])
                ->value('id');
        }

        $host = self::resolveHost($request);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1', ''], true);

        if (!$seedId && $host !== '' && Schema::hasColumn('companies', 'frontend_host')) {
            $seedId = company::query()
                ->get(['id', 'frontend_host'])
                ->first(fn ($row) => self::normalizeHost((string) $row->frontend_host) === $host)
                ?->id;
        }

        if (!$seedId && $host !== '' && !$local) {
            $seedId = self::seedIdFromKnownPortalHost($host);
        }

        if (!$seedId && Schema::hasColumn('companies', 'portal_active')) {
            $seedId = company::query()->where('portal_active', true)->orderByDesc('updated_at')->value('id');
        }

        if (!$seedId) {
            $seedId = self::firstSpmCompanyId();
        }

        if (!$seedId) {
            return $query;
        }

        return self::expandGroup($query, (int) $seedId);
    }

    public static function expandGroup(Builder $query, int $companyId): Builder
    {
        $cols = ['id', 'company_code', 'name'];
        if (Schema::hasColumn('companies', 'org_group')) {
            $cols[] = 'org_group';
        }
        $seed = company::query()->where('id', $companyId)->first($cols);
        if (!$seed) {
            return $query->where('id', $companyId);
        }

        if (Schema::hasColumn('companies', 'org_group')) {
            $group = strtolower(trim((string) ($seed->org_group ?? '')));
            if ($group !== '') {
                return $query->whereRaw('LOWER(TRIM(org_group)) = ?', [$group]);
            }
        }

        $code = strtoupper(trim((string) ($seed->company_code ?? '')));
        if (str_starts_with($code, 'SPM')) {
            return $query->whereRaw("UPPER(TRIM(company_code)) LIKE 'SPM%'");
        }

        return $query->where('id', $companyId);
    }

    private static function seedIdFromKnownPortalHost(string $host): ?int
    {
        if (str_contains($host, 'bsky')) {
            $id = company::query()
                ->whereRaw("UPPER(TRIM(company_code)) IN ('B/SKY','B-SKY','BSKY')")
                ->value('id');
            if ($id) {
                return (int) $id;
            }
            if (Schema::hasColumn('companies', 'org_group')) {
                $id = company::query()->whereRaw("LOWER(TRIM(org_group)) = 'bsky'")->value('id');
                if ($id) {
                    return (int) $id;
                }
            }
        }

        if (str_contains($host, 'spm')) {
            return self::firstSpmCompanyId();
        }

        return null;
    }

    private static function firstSpmCompanyId(): ?int
    {
        if (Schema::hasColumn('companies', 'org_group')) {
            $id = company::query()->whereRaw("LOWER(TRIM(org_group)) = 'spm'")->orderBy('id')->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $id = company::query()
            ->whereRaw("UPPER(TRIM(company_code)) LIKE 'SPM%'")
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    public static function resolveHost(Request $request): string
    {
        if ($request->filled('host')) {
            return self::normalizeHost((string) $request->query('host'));
        }

        foreach (['Origin', 'Referer'] as $header) {
            $value = (string) $request->headers->get($header, '');
            if ($value === '') {
                continue;
            }
            $fromUrl = parse_url($value, PHP_URL_HOST);
            if (is_string($fromUrl) && $fromUrl !== '') {
                return self::normalizeHost($fromUrl);
            }
        }

        return self::normalizeHost((string) $request->getHost());
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return $host ?: '';
    }
}
