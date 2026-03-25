<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('due_date');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'due_date']);
        });

        // Now that project_milestones exists, add the FK from project_tasks
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreign('milestone_id')->references('id')->on('project_milestones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropForeign(['milestone_id']);
        });
        Schema::dropIfExists('project_milestones');
    }
};
