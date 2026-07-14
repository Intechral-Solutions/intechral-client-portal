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
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();

            // Who logged this
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What it's against (both optional)
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();

            // Billing link (set when entry is included on an invoice)
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();

            // Entry data
            $table->date('date');
            $table->unsignedSmallInteger('duration_minutes'); // 0 means timer running
            $table->string('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->boolean('billed')->default(false);   // true once attached to an invoice

            // Timer support: non-null while clock is running, null once stopped
            $table->timestamp('timer_started_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
            $table->index(['project_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
