<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedInteger('duration_minutes')->change();
        });
    }

    public function down(): void
    {
        if (DB::table('time_entries')->where('duration_minutes', '>', 65535)->exists()) {
            throw new RuntimeException(
                'Cannot narrow time_entries.duration_minutes while values exceed 65,535.',
            );
        }

        Schema::table('time_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->change();
        });
    }
};
