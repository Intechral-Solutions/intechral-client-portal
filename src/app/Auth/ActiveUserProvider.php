<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

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
}
