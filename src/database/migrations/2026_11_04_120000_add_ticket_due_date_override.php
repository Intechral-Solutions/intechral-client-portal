<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0: Help Desk due-date overrides.
 *
 * `tickets.sla_due_at` stays the original, immutable SLA deadline. This adds:
 *
 *  - `tickets.due_override_at`: the operator-set working due date. NULL means "no override"; the effective due date is
 *    `COALESCE(due_override_at, sla_due_at)`. A nullable timestamp with no default, no backfill and no index, so every
 *    existing ticket keeps NULL and no row is rewritten.
 *  - `ticket_due_date_changes`: one row per adjustment, the audit trail (previous and new override, actor, time). Its
 *    `reply_id` is NOT NULL and UNIQUE, so a change cannot exist without the reply or note that explains it. Its
 *    (`ticket_id`, `reply_id`) pair is a composite foreign key to `ticket_replies (ticket_id, id)`, so the database itself
 *    refuses a change whose reply belongs to a different ticket.
 *  - a UNIQUE (`ticket_id`, `id`) index on `ticket_replies`, which that composite key needs as its target. `id` is already
 *    the primary key, so the index adds no constraint on data; it is metadata for the engine.
 *
 * Additive: the Release 0.9 build ignores the column and the table, so a migrated database still runs 0.9 (an override is
 * then invisible to it, nothing more). The tables are created on InnoDB through the connection's pinned engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'due_override_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->timestamp('due_override_at')->nullable();
            });
        }

        if (! Schema::hasIndex('ticket_replies', 'ticket_replies_ticket_id_id_unique')) {
            Schema::table('ticket_replies', function (Blueprint $table) {
                $table->unique(['ticket_id', 'id'], 'ticket_replies_ticket_id_id_unique');
            });
        }

        if (Schema::hasTable('ticket_due_date_changes')) {
            return;
        }

        Schema::create('ticket_due_date_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reply_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('previous_override_at')->nullable();
            $table->timestamp('new_override_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('reply_id');
            $table->index(['ticket_id', 'created_at']);
            $table->foreign(['ticket_id', 'reply_id'], 'ticket_due_date_changes_reply_ticket_foreign')
                ->references(['ticket_id', 'id'])->on('ticket_replies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_due_date_changes');

        if (Schema::hasIndex('ticket_replies', 'ticket_replies_ticket_id_id_unique')) {
            Schema::table('ticket_replies', function (Blueprint $table) {
                $table->dropUnique('ticket_replies_ticket_id_id_unique');
            });
        }

        if (Schema::hasColumn('tickets', 'due_override_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('due_override_at');
            });
        }
    }
};
