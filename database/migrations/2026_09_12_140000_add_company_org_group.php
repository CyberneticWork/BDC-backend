<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('companies') && !Schema::hasColumn('companies', 'org_group')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('org_group', 80)->nullable()->after('frontend_host');
            });
        }

        if (!Schema::hasColumn('companies', 'org_group')) {
            return;
        }

        $rows = DB::table('companies')->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row->company_code ?? '')));
            $host = strtolower(trim((string) ($row->frontend_host ?? '')));
            $name = strtolower(trim((string) ($row->name ?? '')));
            $group = null;

            if (in_array($code, ['SPM-C', 'SPM-S', 'SPM-C.', 'SPMS', 'SPMC'], true)
                || str_starts_with($host, 'spm-c.')
                || str_starts_with($host, 'spm-s.')
                || str_contains($name, 'spm tax')
            ) {
                $group = 'spm';
            } elseif (in_array($code, ['B/SKY', 'B-SKY', 'BSKY', 'B SKY'], true)
                || str_starts_with($host, 'bsky.')
                || str_contains($name, 'blue sky')
            ) {
                $group = 'bsky';
            }

            if ($group) {
                DB::table('companies')->where('id', $row->id)->update(['org_group' => $group]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'org_group')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('org_group');
            });
        }
    }
};
