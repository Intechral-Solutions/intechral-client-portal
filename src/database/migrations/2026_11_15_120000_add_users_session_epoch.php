<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (session and authentication hardening): a per-account session epoch.
 *
 * `users.session_epoch` is an opaque random token the application rotates in the SAME UPDATE that changes `two_factor_confirmed_at` (User::booted). Every
 * sign-in stamps the session with the epoch it saw, and the operator boundary refuses an operator session whose stamp no longer matches. That is what
 * makes a second-factor confirmation end every earlier session ATOMICALLY with the confirmation, instead of a few statements later when the
 * revocation listener deletes rows (docs/architecture/operator-mfa.md, section 4).
 *
 * Nullable with no default: an account that has never changed its second factor keeps NULL, and a session with no stamp compares equal to NULL, so
 * existing sessions and accounts are untouched by this migration. Additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'session_epoch')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('session_epoch', 40)->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'session_epoch')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_epoch');
        });
    }
};
