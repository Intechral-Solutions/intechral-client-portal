<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Release 0.9 (H3): the `users` provider resolves ACTIVE accounts only. Every way the guard and the password broker
 * find a user goes through `newModelQuery` (credentials at login and at password reset, the session's user id on
 * every request, the remember-me token), so a suspended account cannot sign in, keep using a session that was not
 * purged, come back through a remembered login, or receive a reset link. To an unauthenticated caller it is
 * indistinguishable from an account that does not exist.
 *
 * Paths that load a user without the provider check suspension themselves (the two-factor challenge and the SSO
 * callback). See docs/architecture/account-lifecycle.md.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        $query = parent::newModelQuery($model);

        return $query->whereNull($query->getModel()->qualifyColumn('disabled_at'));
    }

    /**
     * Release 1.0 (session hardening, review B2): a user object that is STALE cannot mint a remember-me token.
     *
     * `SessionGuard::login(..., remember: true)` writes a fresh token (when the account has none) with `save()` on the object the sign-in holds, and
     * queues a cookie carrying it. If the account confirmed a second factor after that object was loaded, the write would land after the confirmation
     * and replace the token it rotated, and the cookie would then log the holder back in through the remember-me path with the CURRENT epoch stamped,
     * privileged without a second factor. So the write is conditional on the account's epoch still being the one the object loaded: when it has moved,
     * nothing is written and the cookie the sign-in queued matches no stored token.
     *
     * The guard also calls this on logout (to cycle the token); an object loaded for that request carries the current epoch, so it is unaffected.
     */
    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token)
    {
        if (! $user instanceof User) {
            parent::updateRememberToken($user, $token);

            return;
        }

        $user->setRememberToken($token);

        DB::table($user->getTable())
            ->where($user->getKeyName(), $user->getKey())
            ->whereRaw('`session_epoch` <=> ?', [$user->getOriginal('session_epoch')])
            ->update([$user->getRememberTokenName() => $token]);

        $user->syncOriginalAttribute($user->getRememberTokenName());
    }
}
