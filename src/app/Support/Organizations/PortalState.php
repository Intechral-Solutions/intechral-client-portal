<?php

namespace App\Support\Organizations;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * The portal state of an Organization (Release 1.0, ORG-1; ADR-011 section 2): the ONE definition, read from the Organization's own row and
 * from nothing else.
 *
 *   Not enabled   `portal_enabled_at` IS NULL     the Organization exists in the Directory and has never had portal access
 *   Enabled       `portal_enabled_at` set, `disabled_at` NULL
 *   Disabled      both set                        portal participation is suspended
 *
 * WHAT IT IS NOT. It is not a Gate, a policy, a membership, a role or an Eloquent global scope. Every site that decides a portal act reads
 * the row it has ALREADY locked (or is about to lock) and asks this type about it, so the answer is never older than the lock and never
 * waits on a permission-cache load (a first load inside an organization-locked transaction can close a lock cycle, R1-3 6.2.3). The
 * decision sites bypass the model on purpose, so a global scope could not be the fence either.
 *
 * TWO FORMS OF ONE RULE, kept equal by a unit test over all three states: {@see of()} answers for a row already in hand (the locked
 * decision sites), {@see whereEnabled()} states the same rule for a query (the unlocked, listing and join sites). A change to the rule is
 * made here and nowhere else; a source-scanning test fails on any reader of `organizations` that is not accounted for.
 *
 * FAIL CLOSED. A row in a shape the database CHECKs forbid (`disabled_at` set without `portal_enabled_at`) is Not enabled, never Enabled.
 * Only Enabled admits a new portal act; Not enabled and Disabled both refuse it, and the acts that take authority away (a removal, a
 * revocation, an unlink) never ask.
 */
enum PortalState: string
{
    case NotEnabled = 'not_enabled';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    /**
     * The state of a row (a query-builder object, an array, or a model) that carries `portal_enabled_at` and `disabled_at`. A row that does
     * not select both columns is a bug and throws rather than being read as a state.
     *
     * @param  object|array<string, mixed>  $row
     */
    public static function of(object|array $row): self
    {
        $enabledAt = self::column($row, 'portal_enabled_at');
        $disabledAt = self::column($row, 'disabled_at');

        return match (true) {
            $enabledAt === null => self::NotEnabled,
            $disabledAt === null => self::Enabled,
            default => self::Disabled,
        };
    }

    /** The two columns a locking or listing read must select for {@see of()}. @return list<string> */
    public static function columns(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias.'.';

        return [$prefix.'portal_enabled_at', $prefix.'disabled_at'];
    }

    /** Enabled, and only Enabled: the rule {@see of()} answers, as a constraint on a query over `organizations` (or its alias). */
    public static function whereEnabled(Builder|EloquentBuilder $query, string $alias = 'organizations'): Builder|EloquentBuilder
    {
        return $query->whereNotNull($alias.'.portal_enabled_at')->whereNull($alias.'.disabled_at');
    }

    /**
     * Enabled or Disabled, that is, portal access has EVER been enabled: `portal_enabled_at` is set. The complement of {@see isNotEnabled()} for a query, as
     * {@see whereEnabled()} is for Enabled. The System index of Organization access lists exactly these (ORG-3a): an Organization that has never had portal
     * access has nothing to administer there. Presentation only; it decides no portal act. A row the CHECKs forbid (`disabled_at` without `portal_enabled_at`)
     * is Not enabled and is therefore excluded, as {@see of()} fails closed.
     */
    public static function whereEverEnabled(Builder|EloquentBuilder $query, string $alias = 'organizations'): Builder|EloquentBuilder
    {
        return $query->whereNotNull($alias.'.portal_enabled_at');
    }

    public function isEnabled(): bool
    {
        return $this === self::Enabled;
    }

    public function isDisabled(): bool
    {
        return $this === self::Disabled;
    }

    public function isNotEnabled(): bool
    {
        return $this === self::NotEnabled;
    }

    /** The label an operator sees. Disabled keeps the word the interface already uses; Not enabled is the Directory-only state. */
    public function label(): string
    {
        return match ($this) {
            self::NotEnabled => 'Not enabled',
            self::Enabled => 'Enabled',
            self::Disabled => 'Disabled',
        };
    }

    /** @param  object|array<string, mixed>  $row */
    private static function column(object|array $row, string $name): mixed
    {
        if (is_array($row)) {
            if (! array_key_exists($name, $row)) {
                throw new LogicException("PortalState needs the \"{$name}\" column; the read did not select it.");
            }

            return $row[$name];
        }

        // A model keeps its columns in `attributes`; a plain object has properties. Neither may be silently absent.
        if ($row instanceof Model) {
            if (! array_key_exists($name, $row->getAttributes())) {
                throw new LogicException("PortalState needs the \"{$name}\" column; the read did not select it.");
            }

            return $row->getAttribute($name);
        }

        if (! property_exists($row, $name)) {
            throw new LogicException("PortalState needs the \"{$name}\" column; the read did not select it.");
        }

        return $row->{$name};
    }
}
