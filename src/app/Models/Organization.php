<?php

namespace App\Models;

use App\Services\OrganizationAuthority;
use App\Shared\Scopes\OrganizationMembershipScope;
use App\Support\Organizations\Classification;
use App\Support\Organizations\PortalState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    /**
     * The restrictive ORG-1 contract, unchanged by ORG-2 (CP6, F-7): ONLY `name` and `slug` are mass-assignable. The Directory profile (`website`, `phone`,
     * `address`, `notes`) and the internal columns (`classification`, `created_by`) are written by App\Services\OrganizationDirectory alone, by explicit column,
     * after its authority checks, so no request array can reach them through `create()`, `fill()` or `update()`. Fixtures set them explicitly (a factory is
     * unguarded). An architecture test (OrganizationMassAssignmentTest) pins this list.
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * INTERNAL columns (owner ruling 10). They are never part of a generic serialization (`toArray`, `toJson`, an Inertia prop built from a model, a
     * notification payload): `notes` is the operators' private commentary, `created_by` is provenance, and `classification` is a descriptive label that is
     * never an authority input. A page that is allowed to show one selects and names it explicitly (see the Directory views); `$hidden` is the backstop, never the
     * only control, and the leakage tests read real responses.
     */
    protected $hidden = [
        'notes',
        'created_by',
        'classification',
    ];

    /**
     * `managed_by_organization_id` and `can_manage_customers` (Release 1.0, MO-1A) are deliberately NOT fillable: they change
     * only through App\Services\OrganizationRelationships, which enforces the relationship rules under lock and audits the
     * change. There is deliberately no Eloquent relation for them either: a relation would run through the tenant scope and
     * silently undercount for an account the scope filters, and no query that decides an integrity rule may do that.
     *
     * The same holds for the PORTAL STATE (Release 1.0, ORG-1): `portal_enabled_at`, `portal_enabled_by` and `disabled_at` are not
     * fillable either. They change only through OrganizationDirectory::enablePortalAccess (initial enablement) and OrganizationRelationships
     * (disable, re-enable), each authorized, locked and audited. Fixtures set them explicitly (a factory is unguarded).
     */
    protected $casts = [
        'can_manage_customers' => 'boolean',
        'classification' => Classification::class,
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

    /** The operator who created the record. PROVENANCE ONLY, never an authority input (ADR-011 section 2). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The People affiliated with this Organization (Directory records, owner ruling D7: visible to authorized operators only, through CrmContact's own scope).
     * An affiliation is descriptive: it is never read as authority, membership or portal access.
     */
    public function people(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'organization_id');
    }

    /** The Projects this Organization is linked to (`project_organization`). A link is metadata: it widens no one's visibility and prevents deleting the Organization. */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_organization')->withTimestamps();
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
