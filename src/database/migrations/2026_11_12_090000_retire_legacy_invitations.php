<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-3B cleanup): the organization-less invitation is retired, and its persistence goes with it.
 *
 * Release 1.0 has no authoritative data to preserve (owner ruling, 2026-11-09, release-1.0-notes.md) and has not launched, so retired
 * functionality is not carried for backward compatibility. R1-3 replaced the legacy flow with `organization_invitations`; nothing reads
 * or writes the legacy rows any more, and the table held a PLAINTEXT bearer token per row, which is a reason to remove it rather than keep it.
 *
 *   users.invitation_id   the foreign key to the legacy table, and the column: dropped.
 *   invitations           the legacy table: dropped (a populated table is dropped as it is; its rows were retired credentials).
 *
 * `users.invited_by` (who invited this account) is PRESERVED: the R1-3 acceptance writes it. So is `organization_invitations`.
 *
 * The historical migrations that created these objects are NOT edited: they stay as shipped, and the pinned rehearsal baseline
 * (database/rehearsal/baseline.php), which models a Release 0.9 database, still builds them. This migration is deliberately in neither
 * rehearsal manifest (R1-0: a migration need not be listed to ship), so the rehearsal never runs it. A fresh Release 1.0 install runs all
 * the migrations in order and ends without the table and the column.
 *
 * It touches no permission: the retired `users.invite` permission is removed from the catalogue, and an existing database is brought in
 * line by `php artisan roles:sync` (which reports the stored row as stale and revokes the built-in grant on approval); the synchronizer
 * and the role-governance rules say a migration never edits roles or permissions.
 *
 * Guarded so that it is safe to run on a schema that already lacks either object.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'invitation_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('invitation_id');
            });
        }

        Schema::dropIfExists('invitations');
    }

    /** Recreates the retired structure exactly as the historical migrations shipped it (empty), so a rollback leaves a coherent schema. */
    public function down(): void
    {
        if (! Schema::hasTable('invitations')) {
            Schema::create('invitations', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('token', 64)->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('status', ['pending', 'accepted', 'expired'])->default('pending');
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'invitation_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('invitation_id')->nullable()->after('remember_token')->constrained('invitations')->nullOnDelete();
            });
        }
    }
};
