<?php

use App\Services\PersonalOutService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        PersonalOutService::ensureTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_out_entries');
    }
};
