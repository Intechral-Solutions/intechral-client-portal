<?php

namespace App\Models;

use App\Services\OrganizationInvitations;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation of one NEW person into one organization (Release 1.0, R1-3A). Read model only: every write goes through
 * {@see OrganizationInvitations}, the single writer (a source-level test pins that), so nothing here is fillable and the plaintext token is
 * not a column. The state is DERIVED, never stored: {@see state()}.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property string $token_hash
 * @property int $invited_by
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_user_id
 * @property CarbonInterface|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoked_reason
 * @property string|null $live_email
 */
class OrganizationInvitation extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /** accepted, then revoked, then expired, otherwise pending: the order in which a terminal fact wins. */
    public function state(?CarbonInterface $now = null): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->lte($now ?? now()) => self::EXPIRED,
            default => self::PENDING,
        };
    }

    public function isOpen(?CarbonInterface $now = null): bool
    {
        return $this->state($now) === self::PENDING;
    }
}
