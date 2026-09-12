<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'document_path')) {
            DB::statement('ALTER TABLE documents MODIFY document_path TEXT NULL');
        }
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'logo_url')) {
            DB::statement('ALTER TABLE companies MODIFY logo_url VARCHAR(2048) NULL');
        }
    }

    public function down(): void
    {
    }
};
