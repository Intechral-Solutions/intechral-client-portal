<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop FKs that live on project_tasks before renaming
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropForeign('project_tasks_project_id_foreign');
            $table->dropForeign('project_tasks_column_id_foreign');
            $table->dropForeign('project_tasks_milestone_id_foreign');
        });

        Schema::rename('project_tasks', 'tasks');

        Schema::table('tasks', function (Blueprint $table) {
            // Make project_id nullable (tasks can now be standalone or ticket-scoped)
            $table->unsignedBigInteger('project_id')->nullable()->change();

            // Make column_id nullable (standalone / ticket tasks have no kanban column)
            // Switch cascade → null so deleting a column orphans tasks instead of deleting them
            $table->unsignedBigInteger('column_id')->nullable()->change();

            // Ticket parent (mutually exclusive with project_id + column_id)
            $table->foreignId('ticket_id')
                ->nullable()
                ->after('project_id')
                ->constrained('tickets')
                ->nullOnDelete();

            // Explicit status for non-board tasks; board tasks derive state from their column
            $table->enum('status', ['todo', 'in_progress', 'done'])
                ->default('todo')
                ->after('position');

            // Re-add FKs with updated delete behaviour
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('column_id')->references('id')->on('project_columns')->nullOnDelete();
            $table->foreign('milestone_id')->references('id')->on('project_milestones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropForeign(['column_id']);
            $table->dropForeign(['milestone_id']);
            $table->dropForeign(['ticket_id']);
            $table->dropColumn(['ticket_id', 'status']);
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
            $table->unsignedBigInteger('column_id')->nullable(false)->change();
        });

        Schema::rename('tasks', 'project_tasks');

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('column_id')->references('id')->on('project_columns')->cascadeOnDelete();
            $table->foreign('milestone_id')->references('id')->on('project_milestones')->nullOnDelete();
        });
    }
};
