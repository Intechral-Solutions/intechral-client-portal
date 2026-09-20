<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicate = DB::table('social_accounts')
            ->select('user_id', 'provider')
            ->groupBy('user_id', 'provider')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException('Duplicate social accounts exist for a user/provider pair; resolve them before migrating.');
        }

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(['user_id', 'provider']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->index(['user_id', 'provider']);
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'provider']);
        });
    }
};
