<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_task_comments', function (Blueprint $table) {
            $table->dropForeign('project_task_comments_task_id_foreign');
        });

        Schema::rename('project_task_comments', 'task_comments');

        Schema::table('task_comments', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
        });

        Schema::rename('task_comments', 'project_task_comments');

        Schema::table('project_task_comments', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('project_tasks')->cascadeOnDelete();
        });
    }
};
