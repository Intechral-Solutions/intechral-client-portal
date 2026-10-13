<?php

namespace App\Exceptions;

use RuntimeException;

/** Release 0.9 (H4): the first-operator bootstrap declined to proceed. Nothing was created or granted. */
class OperatorBootstrapRefused extends RuntimeException
{
    public static function alreadyEstablished(): self
    {
        return new self('Account administration is already established (an account holds both users.view and users.manage, '
            .'active or suspended), so no operator was created. The bootstrap is for a new installation only; it is not '
            .'a recovery path.');
    }

    public static function rolesNotSynchronized(): self
    {
        return new self('The built-in "operator" role does not exist or lacks users.view or users.manage, so the new account '
            .'could not administer accounts. Run `php artisan roles:sync`, review the plan and apply it first.');
    }

    public static function emailInUse(): self
    {
        return new self('An account with that email address already exists. The bootstrap only creates a new account; it '
            .'never elevates an existing one.');
    }

    public static function notAnAdministrator(): self
    {
        return new self('The new account would not be an active account administrator, so it was rolled back.');
    }
}
