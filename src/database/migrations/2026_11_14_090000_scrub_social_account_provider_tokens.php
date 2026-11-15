<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (pre-RC1 security hardening): provider access and refresh tokens are no longer stored.
 *
 * The portal uses Google or Microsoft only to learn who a person is at sign-in; it never calls a provider API afterwards, so `social_accounts.token`,
 * `refresh_token` and `token_expires_at` were plaintext credentials that nothing read. The application stopped writing them; this removes what Release 0.9
 * and the early Release 1.0 builds already wrote.
 *
 * DATA ONLY, NO SCHEMA CHANGE. The columns stay (nullable) so the pinned Release 0.9 baseline fixtures, the rehearsal harness and `database:verify` are
 * untouched; a later migration may drop them. Not reversible by design: the values are gone, which is the point. Re-runnable: it only ever sets NULL.
 * See docs/product/release-1.0-pre-rc1-security-hardening.md, section 2.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_accounts')) {
            return;
        }

        $columns = array_filter(
            ['token', 'refresh_token', 'token_expires_at'],
            fn (string $column) => Schema::hasColumn('social_accounts', $column),
        );

        if ($columns === []) {
            return;
        }

        DB::table('social_accounts')->update(array_fill_keys($columns, null));
    }

    public function down(): void
    {
        // Irreversible on purpose: a scrubbed credential is not restored.
    }
};
