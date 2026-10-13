<?php

namespace App\Models;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'invitation_id',
        'invited_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_confirmed_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    /**
     * Release 0.9 (H3): a suspended account keeps its row, id, records and grants, but cannot sign in and is not
     * offered for new assignments or sent notifications. See docs/architecture/account-lifecycle.md.
     */
    public function isSuspended(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Active accounts only. A local scope for candidate lists and recipient choices, deliberately never a global
     * one: historical relations (authors, assignees, owners) must still resolve a suspended user.
     *
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('disabled_at'));
    }

    /**
     * R0.9-1B finding L1. The permission library detaches roles and direct permissions in `deleting` hooks, before
     * the DELETE that the RESTRICT keys on business records can refuse. One transaction makes a refused delete lose
     * nothing. Deletion is still not an offboarding path: accounts are suspended.
     */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(User::class, 'invited_by');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class)->latest();
    }

    public function hasSocialAccount(string $provider): bool
    {
        return $this->socialAccounts()->where('provider', $provider)->exists();
    }

    public function sendPasswordResetNotification($token): void
    {
        if ($this->password === null || $this->isSuspended()) {
            return;
        }

        $this->notify(new ResetPassword($token));
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * IDs of all CRM companies reachable via this user's organizations.
     * Used for view_org permission scoping.
     *
     * @return int[]
     */
    public function orgCompanyIds(): array
    {
        return $this->organizations()
            ->with('company')
            ->get()
            ->pluck('company.id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }
}
