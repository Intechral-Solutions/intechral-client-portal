<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Invitation tracking
            $table->foreignId('invitation_id')->nullable()->constrained('invitations')->nullOnDelete()->after('remember_token');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete()->after('invitation_id');

            // Fortify 2FA columns
            $table->text('two_factor_secret')->nullable()->after('invited_by');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invitation_id');
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
