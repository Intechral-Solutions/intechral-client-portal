<?php

namespace App\Shared\Permissions;

use App\Services\BuiltInRoleSynchronizer;

/**
 * The authoritative catalogue of the built-in roles (Release 0.9, decision H4).
 *
 * Built-in roles are owned by the application: their names and permissions are defined here, in code, from
 * {@see PermissionCatalogue}. Ordinary administration (the Roles screens, AccessGrants) can neither change nor delete
 * them. Their stored grants change only through the reviewed synchronization (`php artisan roles:sync`,
 * {@see BuiltInRoleSynchronizer}). Every other role is a custom role: operator-managed, and never
 * touched by the synchronization. A user's assignment to a built-in role is ordinary user administration.
 */
final class BuiltInRoles
{
    public const OPERATOR = 'operator';

    public const USER = 'user';

    public const GUARD = 'web';

    /** @return list<string> */
    public static function names(): array
    {
        return [self::OPERATOR, self::USER];
    }

    /** Case-insensitive, because the roles table compares names that way: "Operator" must not pass as a custom role. */
    public static function isBuiltIn(string $name): bool
    {
        return in_array(strtolower($name), self::names(), true);
    }

    /**
     * Each built-in role's permissions, sorted. `operator` carries the whole catalogue; `user` the platform-user
     * defaults.
     *
     * @return array<string, list<string>>
     */
    public static function definitions(): array
    {
        $definitions = [
            self::OPERATOR => PermissionCatalogue::all(),
            self::USER => PermissionCatalogue::userDefaults(),
        ];

        return array_map(function (array $permissions) {
            sort($permissions);

            return $permissions;
        }, $definitions);
    }

    /**
     * Why $definitions cannot be trusted, empty when they can. Run before every synchronization so a malformed or
     * partial definition fails visibly rather than granting or removing the wrong thing: a duplicate or malformed
     * permission name, a built-in role granted a permission the catalogue does not define, an `operator` that is
     * not the whole catalogue, or one that cannot administer accounts (`users.view` and `users.manage`).
     *
     * @param  array<string, list<string>>|null  $definitions  defaults to {@see definitions()}
     * @return list<string>
     */
    public static function problems(?array $definitions = null): array
    {
        $definitions ??= self::definitions();
        $problems = [];
        $catalogue = PermissionCatalogue::all();

        if (count($catalogue) !== count(array_unique($catalogue))) {
            $problems[] = 'The permission catalogue lists a permission more than once.';
        }

        foreach ($definitions as $role => $permissions) {
            if (! is_string($role) || $role === '' || array_values($permissions) !== $permissions) {
                $problems[] = 'A built-in role definition is malformed.';

                continue;
            }

            if (count($permissions) !== count(array_unique($permissions))) {
                $problems[] = "The \"{$role}\" role lists a permission more than once.";
            }

            foreach ($permissions as $permission) {
                if (! is_string($permission) || preg_match('/^[a-z_]+\.[a-z_]+$/', $permission) !== 1) {
                    $problems[] = "The \"{$role}\" role lists a malformed permission name.";
                } elseif (! in_array($permission, $catalogue, true)) {
                    $problems[] = "The \"{$role}\" role lists \"{$permission}\", which the permission catalogue does not define.";
                }
            }
        }

        if (array_diff(self::names(), array_keys($definitions)) !== [] || array_diff(array_keys($definitions), self::names()) !== []) {
            $problems[] = 'The built-in role definitions must cover exactly the built-in roles.';
        }

        $operator = $definitions[self::OPERATOR] ?? [];
        if (array_diff($catalogue, $operator) !== []) {
            $problems[] = 'The "operator" role must carry every permission in the catalogue.';
        }
        if (array_diff([PermissionCatalogue::USERS_VIEW, PermissionCatalogue::USERS_MANAGE], $operator) !== []) {
            $problems[] = 'The "operator" role must carry users.view and users.manage, or no account could administer accounts.';
        }

        return array_values(array_unique($problems));
    }
}
