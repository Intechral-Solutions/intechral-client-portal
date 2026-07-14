<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores 15-minute block allocations for time entries.
     *
     * Each day has 96 blocks (0–95). Block N starts at N×15 minutes past midnight.
     * When multiple timers run concurrently, their allocations for each block sum to 100%.
     * Manual overrides (is_overridden = true) are preserved when timers are re-computed.
     */
    public function up(): void
    {
        Schema::create('time_entry_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('block_date');
            $table->unsignedTinyInteger('block_number')->comment('0–95: block N starts at N×15 min past midnight');
            $table->decimal('allocation_pct', 5, 2)->default(100.00)->comment('Percentage of the 15-min block allocated to this entry');
            $table->boolean('is_overridden')->default(false)->comment('If true, automatic recalculation is skipped for this block');
            $table->timestamps();

            $table->unique(['time_entry_id', 'block_date', 'block_number'], 'teb_entry_date_block_unique');
            $table->index(['user_id', 'block_date'], 'teb_user_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_blocks');
    }
};
