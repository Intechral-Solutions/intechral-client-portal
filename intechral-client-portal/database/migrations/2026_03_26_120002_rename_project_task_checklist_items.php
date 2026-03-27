<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_checklist_items', function (Blueprint $table) {
            $table->dropForeign('project_task_checklist_items_task_id_foreign');
        });

        Schema::rename('project_task_checklist_items', 'task_checklist_items');

        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_checklist_items', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
        });

        Schema::rename('task_checklist_items', 'project_task_checklist_items');

        Schema::table('project_task_checklist_items', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
        });
    }
};
