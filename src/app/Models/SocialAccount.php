<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
    ];

    // Release 1.0 pre-RC1: the portal stores no provider access or refresh token (it never calls a provider after sign-in), so none is fillable.
    // The three columns remain, always NULL (migration 2026_11_14_090000_scrub_social_account_provider_tokens), and stay hidden defensively.
    protected $hidden = [
        'token',
        'refresh_token',
        'token_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
