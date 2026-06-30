<?php

namespace App\Models;

use App\Shared\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmCompany extends Model
{
    use HasFactory;

    protected $table = 'crm_companies';

    protected $fillable = [
        'name',
        'website',
        'phone',
        'address',
        'notes',
        'organization_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationScope);
    }

    // ── Relationships ──────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CrmContact::class, 'crm_company_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_company')
            ->withTimestamps();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'company_id');
    }

    // ── Helpers ────────────────────────────────────────────

    public function isPromoted(): bool
    {
        return $this->organization_id !== null;
    }
}
