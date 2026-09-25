<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EPIC-011E D4: a project or task that recorded time points at can never be deleted, so a
     * time entry's context is never silently nulled. The application refuses such deletes with
     * a friendly error; this is the database backstop for the race between the check and the
     * DELETE and for any cascade (tasks.project_id, projects.created_by) that bypasses it.
     * No data changes: both columns stay nullable for entries that never had that context.
     */
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['task_id']);
            $table->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['task_id']);
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }
};
