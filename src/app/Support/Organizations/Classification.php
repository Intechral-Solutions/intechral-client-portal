<?php

namespace App\Support\Organizations;

/**
 * The built-in Classification of an Organization (Release 1.0, ORG-2; owner ruling B and ADR-011 section 6): exactly one of three values, `general` by default.
 *
 * It is DESCRIPTIVE ONLY. It is never read by a policy, a scope, a gate, a middleware or an authority service (invariant S12), is never selected on a customer
 * or partner path, and says nothing about portal state, partner designation, membership or roles. The column is `varchar(32) NOT NULL DEFAULT 'general'` with no
 * database CHECK: this enum is the one catalogue, and PHP validation, `organizations:preflight` and the integrity snapshot detect a value outside it.
 *
 * The catalogue is read from this one place by the migration's verification, the integrity checks, the Directory service's validation and the Directory forms. The
 * unified interface is ORG-3; until then the transitional Directory pages carry a plain selector.
 */
enum Classification: string
{
    case General = 'general';
    case Client = 'client';
    case Vendor = 'vendor';

    public const DEFAULT = self::General;

    /** The operator-facing label. */
    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Client => 'Client',
            self::Vendor => 'Vendor',
        };
    }

    /** @return list<string> every value the column may hold */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
