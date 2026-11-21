<?php

namespace App\Models;

use App\Shared\Scopes\DirectoryOperatorScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContact extends Model
{
    use HasFactory;

    protected $table = 'crm_contacts';

    protected $fillable = [
        'organization_id',
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
        static::addGlobalScope(new DirectoryOperatorScope);
    }

    // ── Relationships ──────────────────────────────────────

    /**
     * The Person's one optional Organization (the canonical affiliation, owner ruling D7). Read through Organization's own membership scope, so a Person is
     * never shown an Organization the viewer cannot see; the Person itself is operator-only ({@see DirectoryOperatorScope}).
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
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
