<?php

namespace App\Models;

use App\Shared\Scopes\OrganizationThroughCompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContact extends Model
{
    use HasFactory;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'crm_company_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'job_title',
        'notes',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new OrganizationThroughCompanyScope);
    }

    // ── Relationships ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ────────────────────────────────────────────

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
