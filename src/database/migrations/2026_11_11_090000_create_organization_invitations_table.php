<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Release 1.0 (R1-3A): organization-bound invitations. Design record: docs/product/release-1.0-r1-3-organization-invitations.md, section 4.1.
 *
 * One additive CREATE TABLE; no existing table or row is touched (the legacy `invitations` table stays as inert history). An invitation
 * names exactly ONE organization and carries NO role and NO status column: its state is derived (accepted if accepted_at, revoked if
 * revoked_at, expired if expires_at has passed, otherwise pending), so time needs no write and no job, and no request, bug or future
 * caller can ask this table for administrator rights.
 *
 *   token_hash      lower-case hex SHA-256 of the token, UNIQUE. The plaintext token is never stored.
 *   revoked_reason  VARCHAR(32), a closed set of stable codes defined on App\Services\OrganizationInvitations; descriptive only.
 *   live_email      the UNIQUENESS SLOT: the address while this row holds the (organization, address) slot, otherwise NULL. NULLs are
 *                   distinct in a unique key, so any number of released rows coexist. It is NOT an "unexpired" marker and is not cleared by
 *                   time; issuance releases it under the organization lock. A system revocation (suspension, a role or permission change) leaves it set,
 *                   so a revoked row can keep the slot until the next issuance for the address releases it. UNIQUE (organization_id,
 *                   live_email) is only the backstop.
 *
 * Every reference to a user or an organization is ON DELETE/UPDATE RESTRICT: an invitation is provenance and is never cascaded away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete()->restrictOnUpdate();
            $table->string('email');
            // Lower-case hex, looked up by equality. The database profile pins every text column to utf8mb4_unicode_ci, which is case-insensitive;
            // that is harmless here because only lower-case hex is ever stored or looked up, and acceptance compares the locked row's hash exactly
            // (hash_equals) before it trusts it.
            $table->char('token_hash', 64)->unique('organization_invitations_token_hash_unique');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->unique('organization_invitations_accepted_user_id_unique')
                ->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->string('revoked_reason', 32)->nullable();
            $table->string('live_email')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'live_email'], 'organization_invitations_org_live_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
    }
};
