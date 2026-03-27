<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('ticket_id')
                  ->nullable()
                  ->after('task_id')
                  ->constrained('tickets')
                  ->nullOnDelete();

            $table->timestamp('stopped_at')
                  ->nullable()
                  ->after('timer_started_at')
                  ->comment('Set when timer is stopped; used for block overlap queries');

            $table->index(['user_id', 'timer_started_at'], 'time_entries_user_timer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_user_timer_idx');
            $table->dropForeign(['ticket_id']);
            $table->dropColumn(['ticket_id', 'stopped_at']);
        });
    }
};
