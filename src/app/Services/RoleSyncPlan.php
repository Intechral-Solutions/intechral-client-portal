<?php

namespace App\Services;

/**
 * A reviewable plan for bringing the built-in roles in line with the code (Release 0.9, decision H4). Pure data: it is
 * produced by {@see BuiltInRoleSynchronizer::plan()} without writing anything, and applying it is a separate,
 * explicitly authorized step identified by {@see fingerprint()}.
 *
 * Actions are stable and sorted. Additions create what is missing; a revocation removes a grant the definition no
 * longer carries, and is the only destructive action. Custom roles never appear.
 */
final class RoleSyncPlan
{
    public const CREATE_PERMISSION = 'create_permission';

    public const CREATE_ROLE = 'create_role';

    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    /**
     * @param  list<array{action: string, role?: string, permission?: string}>  $actions
     * @param  list<string>  $stalePermissions  stored permissions no built-in role defines; reported, never changed
     * @param  list<int>  $administratorsBefore  active account administrators now
     * @param  list<int>  $administratorsAfter  active account administrators once the plan is applied
     */
    public function __construct(
        public readonly array $actions,
        public readonly array $stalePermissions,
        public readonly array $administratorsBefore,
        public readonly array $administratorsAfter,
    ) {}

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    /** @return list<array{action: string, role?: string, permission?: string}> */
    public function revocations(): array
    {
        return array_values(array_filter($this->actions, fn (array $action) => $action['action'] === self::REVOKE));
    }

    public function hasRevocations(): bool
    {
        return $this->revocations() !== [];
    }

    public function administratorsChange(): bool
    {
        return $this->administratorsBefore !== $this->administratorsAfter;
    }

    /**
     * Identifies exactly this plan: what will be created, granted and revoked, and who is an administrator before and
     * after. Applying requires it, so approval of one plan can never apply a different one after the stored state
     * has changed. The informational stale-permission list is not part of it, because nothing acts on that.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', json_encode([
            'actions' => $this->actions,
            'before' => $this->administratorsBefore,
            'after' => $this->administratorsAfter,
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** One human-readable line per action, in plan order. */
    public function describe(array $action): string
    {
        return match ($action['action']) {
            self::CREATE_PERMISSION => "create permission {$action['permission']}",
            self::CREATE_ROLE => "create role {$action['role']}",
            self::GRANT => "grant {$action['permission']} to {$action['role']}",
            self::REVOKE => "REVOKE {$action['permission']} from {$action['role']}",
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint(),
            'in_sync' => $this->isEmpty(),
            'revocations' => count($this->revocations()),
            'actions' => $this->actions,
            'stale_permissions' => $this->stalePermissions,
            'administrators_before' => $this->administratorsBefore,
            'administrators_after' => $this->administratorsAfter,
        ];
    }
}
