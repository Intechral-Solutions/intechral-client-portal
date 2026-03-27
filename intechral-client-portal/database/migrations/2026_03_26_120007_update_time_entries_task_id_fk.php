<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-point time_entries.task_id from the old project_tasks table (now renamed
     * to tasks) so the constraint name is clean and unambiguous.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign('time_entries_task_id_foreign');
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->foreign('task_id')->references('id')->on('project_tasks')->nullOnDelete();
        });
    }
};
