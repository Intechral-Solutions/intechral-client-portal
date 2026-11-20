<?php

namespace App\Models;

use App\Services\OrganizationAuthority;
use App\Shared\Scopes\OrganizationMembershipScope;
use App\Support\Organizations\PortalState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * `managed_by_organization_id` and `can_manage_customers` (Release 1.0, MO-1A) are deliberately NOT fillable: they change
     * only through App\Services\OrganizationRelationships, which enforces the relationship rules under lock and audits the
     * change. There is deliberately no Eloquent relation for them either: a relation would run through the tenant scope and
     * silently undercount for an account the scope filters, and no query that decides an integrity rule may do that.
     *
     * The same holds for the PORTAL STATE (Release 1.0, ORG-1): `portal_enabled_at`, `portal_enabled_by` and `disabled_at` are not
     * fillable either. They change only through CrmService::promoteToOrganization (initial enablement) and OrganizationRelationships
     * (disable, re-enable), each authorized, locked and audited. Fixtures set them explicitly (a factory is unguarded).
     */
    protected $casts = [
        'can_manage_customers' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationMembershipScope);
    }

    // ── Relationships ──────────────────────────────────────

    /** The operator who enabled portal access. PROVENANCE ONLY: it is not an authority input anywhere (ADR-011 section 2). Null for a never-enabled Organization. */
    public function portalEnabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'portal_enabled_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function company(): HasOne
    {
        return $this->hasOne(CrmCompany::class, 'organization_id');
    }

    // ── Helpers ────────────────────────────────────────────

    /** Not enabled, Enabled or Disabled, from this row's own columns. Needs both to be loaded (an explicit column list that omits them throws). */
    public function portalState(): PortalState
    {
        return PortalState::of($this);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Whether the user's membership has the ADMINISTRATOR ROLE. Role only: it says nothing about approval, and it grants nothing.
     * (Called isAdmin() before MO-2A1, a name that read like authority; it had no callers.) What counts as an approved
     * administrator is defined once, in {@see OrganizationAuthority}.
     */
    public function hasAdminRole(User $user): bool
    {
        return $this->members()
            ->where('user_id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }
}
