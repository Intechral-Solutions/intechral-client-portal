<?php

/*
 * Release 1.0 (ORG-2, CP3 and CP5): App\Services\OrganizationDirectory, the one service that creates, edits, classifies, deletes and initially enables an
 * Organization. Behaviour first (create, profile update, classification, slug, enablement), then the direct-service security matrix: every operation, called
 * directly (a job, tinker), refuses an ordinary customer, a suspended operator, an operator without `crm.manage` and an actor whose permission cache is stale,
 * and a refusal leaves NO write, NO membership change and NO audit entry. These are the successors of the CP0 characterization of the Company service (its
 * baseline, including A4, is recorded in docs/product/release-1.0-org-2-physical-consolidation.md).
 */

use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationRelationships;
use App\Support\Organizations\Classification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
    $this->directory = app(OrganizationDirectory::class);
});

/** Everything a refused or no-op call must leave exactly as it was. @return array<string, mixed> */
function directoryWorld(): array
{
    return [
        'organizations' => DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'members' => DB::table('organization_members')->orderBy('organization_id')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all(),
        'people' => DB::table('crm_contacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'audit' => DB::table('activity_log')->count(),
    ];
}

function directoryAudit(string $event): array
{
    return DB::table('activity_log')->where('description', $event)->orderBy('id')->get()->map(fn ($row) => ['row' => $row] + json_decode($row->properties, true))->all();
}

// ── Create ───────────────────────────────────────────────────────────────────────────────────

describe('create', function () {
    it('creates a Directory-only Organization: never enabled, no members, the actor as creator, general by default, one audit entry', function () {
        $organization = $this->directory->create($this->operator, ['name' => '  Acme Holdings  ', 'website' => 'https://acme.example', 'phone' => '555', 'address' => '1 Road', 'notes' => 'secret note text']);
        $row = DB::table('organizations')->where('id', $organization->id)->first();

        expect($row->name)->toBe('Acme Holdings')
            ->and($row->slug)->toBe('acme-holdings')
            ->and($row->portal_enabled_at)->toBeNull()->and($row->portal_enabled_by)->toBeNull()->and($row->disabled_at)->toBeNull()
            ->and((int) $row->created_by)->toBe($this->operator->id)
            ->and($row->classification)->toBe('general')
            ->and([$row->website, $row->phone, $row->address, $row->notes])->toBe(['https://acme.example', '555', '1 Road', 'secret note text'])
            ->and($organization->portalState()->isNotEnabled())->toBeTrue()
            ->and(DB::table('organization_members')->where('organization_id', $organization->id)->count())->toBe(0);

        $entries = directoryAudit(OrganizationDirectory::CREATED);
        expect($entries)->toHaveCount(1)
            ->and((int) $entries[0]['row']->causer_id)->toBe($this->operator->id)
            ->and($entries[0]['new']['name'])->toBe('Acme Holdings')
            ->and($entries[0]['new'])->not->toHaveKey('notes', 'the free-text notes are never copied into the log')
            ->and($entries[0]['internal_fields_set'])->toBe(['notes'], 'an internal field was supplied: named, never valued')
            ->and(json_encode($entries))->not->toContain('secret note text');
    });

    it('accepts a classification, defaults the rest, and refuses a value outside the catalogue', function () {
        $vendor = $this->directory->create($this->operator, ['name' => 'Vend', 'classification' => 'vendor']);
        expect($vendor->fresh()->classification)->toBe(Classification::Vendor);

        $before = directoryWorld();
        expect(fn () => $this->directory->create($this->operator, ['name' => 'Bad', 'classification' => 'partner']))->toThrow(ValidationException::class)
            ->and(fn () => $this->directory->create($this->operator, ['name' => '   ']))->toThrow(ValidationException::class)
            ->and(fn () => $this->directory->create($this->operator, []))->toThrow(ValidationException::class)
            ->and(directoryWorld())->toBe($before);
    });

    it('gives a non-empty, unique slug to a name in another script, to punctuation only, and to a name that repeats', function () {
        $names = ['北京公司', 'Ünïcode Straße', '!!!', '???', 'Same', 'Same', 'Same', str_repeat('x', 250)];
        $slugs = array_map(fn ($name) => $this->directory->create($this->operator, ['name' => $name])->slug, $names);

        expect($slugs)->each->not->toBeEmpty()
            ->and(array_unique($slugs))->toHaveCount(count($slugs))
            ->and($slugs[2])->toStartWith('organization')->and($slugs[3])->toStartWith('organization')
            ->and(array_slice($slugs, 4, 3))->toBe(['same', 'same-2', 'same-3'])
            ->and(strlen($slugs[7]))->toBeLessThanOrEqual(255);
    });

    it('refuses a value wider than its column as a validation message, writing nothing', function () {
        $before = directoryWorld();

        foreach ([['name' => str_repeat('x', 256)], ['name' => 'Ok', 'phone' => str_repeat('1', 31)], ['name' => 'Ok', 'website' => 'https://'.str_repeat('a', 250)]] as $data) {
            expect(fn () => $this->directory->create($this->operator, $data))->toThrow(ValidationException::class);
        }

        expect(directoryWorld())->toBe($before);
    });

    it('retries a slug that lost a race to another request, on the slug key only, a bounded number of times', function () {
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if (! $raced && str_starts_with($query, 'insert into `organizations`')) {
                $raced = true;
                // The competing request commits the same slug between this request's probe and its insert.
                DB::table('organizations')->insert(['name' => 'Competitor', 'slug' => 'race-co', 'created_by' => $this->operator->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        expect($this->directory->create($this->operator, ['name' => 'Race Co'])->slug)->toBe('race-co-2');
    });

    it('gives up after a bounded number of lost races, and rethrows a duplicate on any OTHER key at once', function () {
        $attempts = 0;
        DB::beforeExecuting(function (string $query) use (&$attempts) {
            if (str_starts_with($query, 'insert into `organizations`')) {
                $attempts++;
                $pdo = new PDOException("Duplicate entry 'x' for key 'organizations_slug_unique'");
                $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'organizations_slug_unique'"];

                throw new QueryException(config('database.default'), $query, [], $pdo);
            }
        });

        expect(fn () => $this->directory->create($this->operator, ['name' => 'Never']))->toThrow(RuntimeException::class, 'unique slug')
            ->and($attempts)->toBe(5);
    });

    it('does not mistake a duplicate on another key for a slug collision', function () {
        $attempts = 0;
        DB::beforeExecuting(function (string $query) use (&$attempts) {
            if (str_starts_with($query, 'insert into `organizations`')) {
                $attempts++;
                $pdo = new PDOException("Duplicate entry 'x' for key 'organizations_something_else_unique'");
                $pdo->errorInfo = ['23000', 1062, "Duplicate entry 'x' for key 'organizations_something_else_unique'"];

                throw new QueryException(config('database.default'), $query, [], $pdo);
            }
        });

        expect(fn () => $this->directory->create($this->operator, ['name' => 'Other']))->toThrow(QueryException::class)
            ->and($attempts)->toBe(1);
    });
});

// ── Update (A4: a blank clears, an omitted key is kept) ─────────────────────────────────────

describe('update', function () {
    beforeEach(function () {
        $this->organization = Organization::factory()->directoryOnly()->create([
            'created_by' => $this->operator->id, 'name' => 'Keeps Co', 'website' => 'https://keeps.example', 'phone' => '555-0100', 'address' => '1 Road', 'notes' => 'internal note',
        ]);
    });

    it('keeps what a missing key does not mention, clears a field set to null or blank, and requires a name', function () {
        expect($this->directory->update($this->organization, $this->operator, ['name' => 'Keeps Co']))->toBeFalse();
        expect(DB::table('organizations')->where('id', $this->organization->id)->first(['website', 'phone', 'address', 'notes']))->toEqual((object) ['website' => 'https://keeps.example', 'phone' => '555-0100', 'address' => '1 Road', 'notes' => 'internal note']);

        expect($this->directory->update($this->organization, $this->operator, ['website' => null, 'phone' => '', 'notes' => '   ']))->toBeTrue();
        $row = DB::table('organizations')->where('id', $this->organization->id)->first();

        expect([$row->website, $row->phone, $row->address, $row->notes])->toBe([null, null, '1 Road', null])
            ->and($row->name)->toBe('Keeps Co');

        $before = directoryWorld();
        expect(fn () => $this->directory->update($this->organization, $this->operator, ['name' => null]))->toThrow(ValidationException::class)
            ->and(fn () => $this->directory->update($this->organization, $this->operator, ['name' => '  ']))->toThrow(ValidationException::class)
            ->and(directoryWorld())->toBe($before);
    });

    it('through the browser: a blank optional field is cleared and an omitted one is left alone', function () {
        $this->actingAs($this->operator)->put(route('crm.companies.update', $this->organization), ['name' => 'Keeps Co', 'website' => '', 'phone' => '', 'address' => '', 'notes' => ''])
            ->assertRedirect(route('crm.companies.show', $this->organization));

        expect(DB::table('organizations')->where('id', $this->organization->id)->first(['website', 'phone', 'address', 'notes']))->toEqual((object) ['website' => null, 'phone' => null, 'address' => null, 'notes' => null]);

        $this->actingAs($this->operator)->put(route('crm.companies.update', $this->organization), ['name' => 'Renamed'])->assertRedirect();
        expect($this->organization->fresh()->name)->toBe('Renamed');
    });

    it('writes only the columns that changed, and a no-op writes no row, no updated_at and no audit entry', function () {
        $updatedAt = DB::table('organizations')->where('id', $this->organization->id)->value('updated_at');
        $audit = DB::table('activity_log')->count();

        DB::flushQueryLog();
        DB::enableQueryLog();
        expect($this->directory->update($this->organization, $this->operator, ['name' => 'Keeps Co', 'phone' => '555-0100', 'website' => 'https://keeps.example']))->toBeFalse();
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => preg_match('/^(update|insert|delete)/i', $sql))->all();
        DB::disableQueryLog();

        expect($writes)->toBe([])
            ->and(DB::table('organizations')->where('id', $this->organization->id)->value('updated_at'))->toBe($updatedAt)
            ->and(DB::table('activity_log')->count())->toBe($audit);

        $this->travel(5)->seconds();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->directory->update($this->organization, $this->operator, ['phone' => '555-0199']);
        $update = collect(DB::getQueryLog())->pluck('query')->first(fn ($sql) => str_starts_with($sql, 'update `organizations`'));
        DB::disableQueryLog();

        expect($update)->toContain('`phone` = ?')->toContain('`updated_at` = ?')
            ->and($update)->not->toContain('`name`')->not->toContain('`website`')->not->toContain('`portal_enabled_at`')->not->toContain('`disabled_at`')->not->toContain('`classification`')->not->toContain('`created_by`');
    });

    it('records old and new values of a real change, and never the notes text', function () {
        $this->directory->update($this->organization, $this->operator, ['name' => 'New Name', 'notes' => 'a different note']);

        $entries = directoryAudit(OrganizationDirectory::PROFILE_UPDATED);
        expect($entries)->toHaveCount(1)
            ->and($entries[0]['old'])->toBe(['name' => 'Keeps Co'])
            ->and($entries[0]['new'])->toBe(['name' => 'New Name'])
            ->and($entries[0]['internal_fields_changed'])->toBe(['notes'], 'an internal field changed: named, never valued')
            ->and(json_encode($entries))->not->toContain('internal note')->not->toContain('a different note');
    });

    it('reports a note being written, edited and cleared the same unambiguous way, and a change to another field not at all', function () {
        $this->directory->update($this->organization, $this->operator, ['notes' => null]);          // cleared
        $this->directory->update($this->organization, $this->operator, ['notes' => 'first value']); // written
        $this->directory->update($this->organization, $this->operator, ['phone' => '555-0199']);    // not a note

        $changed = collect(directoryAudit(OrganizationDirectory::PROFILE_UPDATED))->map(fn ($entry) => $entry['internal_fields_changed'] ?? null)->all();

        expect($changed)->toBe([['notes'], ['notes'], null])
            ->and(DB::table('activity_log')->pluck('properties')->implode(' '))->not->toContain('first value')->not->toContain('internal note');
    });

    it('re-reads the Organization under its lock: a stale request model overwrites no portal-state column and decides nothing', function () {
        $enabled = Organization::factory()->create(['name' => 'Stale Co', 'portal_enabled_by' => $this->operator->id]);
        $stale = Organization::withoutGlobalScopes()->find($enabled->id);   // a request's copy, loaded while it was Enabled
        app(OrganizationRelationships::class)->disable($enabled, $this->operator);
        $disabledAt = DB::table('organizations')->where('id', $enabled->id)->value('disabled_at');

        $stale->name = 'Mass-assigned name';
        $stale->disabled_at = null;                       // what a save() of the stale model would have written back
        $stale->portal_enabled_by = null;
        $this->directory->update($stale, $this->operator, ['phone' => '123']);

        $row = DB::table('organizations')->where('id', $enabled->id)->first();
        expect($row->disabled_at)->toBe($disabledAt)
            ->and((int) $row->portal_enabled_by)->toBe($this->operator->id)
            ->and($row->name)->toBe('Stale Co')
            ->and($row->phone)->toBe('123');
    });

    it('edits an Organization in every portal state: Directory-only, Enabled and Disabled', function (string $state) {
        $organization = match ($state) {
            'directory-only' => $this->organization,
            'enabled' => Organization::factory()->create(['portal_enabled_by' => $this->operator->id]),
            'disabled' => Organization::factory()->disabled()->create(['portal_enabled_by' => $this->operator->id]),
        };
        $portal = DB::table('organizations')->where('id', $organization->id)->first(['portal_enabled_at', 'portal_enabled_by', 'disabled_at']);

        expect($this->directory->update($organization, $this->operator, ['phone' => '777']))->toBeTrue()
            ->and(DB::table('organizations')->where('id', $organization->id)->first(['portal_enabled_at', 'portal_enabled_by', 'disabled_at']))->toEqual($portal);
    })->with(['directory-only', 'enabled', 'disabled']);

    it('answers an unknown Organization with a 404 and writes nothing', function () {
        $ghost = new Organization;
        $ghost->id = 987654;
        $before = directoryWorld();

        expect(fn () => $this->directory->update($ghost, $this->operator, ['phone' => '1']))->toThrow(NotFoundHttpException::class)
            ->and(directoryWorld())->toBe($before);
    });
});

// ── Classification ───────────────────────────────────────────────────────────────────────────

describe('classification', function () {
    it('changes between the three values, recording old and new, and a no-op writes nothing', function () {
        $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);

        expect($this->directory->changeClassification($organization, $this->operator, Classification::General))->toBeFalse()
            ->and(directoryAudit(OrganizationDirectory::CLASSIFICATION_CHANGED))->toBe([]);

        expect($this->directory->changeClassification($organization, $this->operator, 'client'))->toBeTrue()
            ->and($this->directory->changeClassification($organization, $this->operator, Classification::Vendor))->toBeTrue()
            ->and($this->directory->changeClassification($organization, $this->operator, 'vendor'))->toBeFalse();

        $entries = directoryAudit(OrganizationDirectory::CLASSIFICATION_CHANGED);
        expect($entries)->toHaveCount(2)
            ->and([$entries[0]['old'], $entries[0]['new']])->toBe([['classification' => 'general'], ['classification' => 'client']])
            ->and([$entries[1]['old'], $entries[1]['new']])->toBe([['classification' => 'client'], ['classification' => 'vendor']])
            ->and($organization->fresh()->classification)->toBe(Classification::Vendor);
    });

    it('refuses a value outside the catalogue and leaves the Organization unchanged', function (mixed $value) {
        $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'classification' => 'client']);
        $before = directoryWorld();

        expect(fn () => $this->directory->update($organization, $this->operator, ['classification' => $value]))->toThrow(ValidationException::class)
            ->and(directoryWorld())->toBe($before);
    })->with(['an unknown value' => 'enterprise', 'an empty one' => '', 'null' => null, 'a number' => 3, 'a different case' => 'Client']);

    it('changes nothing else: not the portal state, the partner designation, the relationship, the members or the roles', function () {
        $organization = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
        $member = User::factory()->create()->assignRole('user');
        $organization->members()->attach($member, ['role' => 'admin']);
        $before = fn () => [
            (array) DB::table('organizations')->where('id', $organization->id)->first(['portal_enabled_at', 'portal_enabled_by', 'disabled_at', 'can_manage_customers', 'managed_by_organization_id']),
            DB::table('organization_members')->where('organization_id', $organization->id)->get()->map(fn ($r) => (array) $r)->all(),
            $member->fresh()->getRoleNames()->all(),
        ];
        $state = $before();

        foreach (Classification::cases() as $classification) {
            $this->directory->changeClassification($organization, $this->operator, $classification);
            expect($before())->toBe($state);
        }
    });
});

// ── Initial enablement ───────────────────────────────────────────────────────────────────────

describe('initial enablement', function () {
    it('enables a Not enabled Organization once: matching instants, the enabling operator as automatic admin, the creator untouched', function () {
        $creator = User::factory()->create();
        $organization = Organization::factory()->directoryOnly()->create(['created_by' => $creator->id]);

        $enabled = $this->directory->enablePortalAccess($organization, $this->operator);
        $row = DB::table('organizations')->where('id', $organization->id)->first();
        $entry = directoryAudit(OrganizationDirectory::PORTAL_ENABLED)[0];

        expect($enabled->portalState()->isEnabled())->toBeTrue()
            ->and($row->portal_enabled_at)->toBe($row->updated_at)->toBe($entry['row']->created_at)
            ->and((int) $row->portal_enabled_by)->toBe($this->operator->id)
            ->and((int) $row->created_by)->toBe($creator->id)
            ->and(DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $this->operator->id)->value('role'))->toBe('admin')
            ->and($entry['old']['portal_state'])->toBe('not_enabled')->and($entry['new']['portal_state'])->toBe('enabled');
    });

    it('refuses an already Enabled Organization as already_enabled and a Disabled one without clearing it, each with no side effect', function () {
        $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
        $this->directory->enablePortalAccess($directoryOnly, $this->operator);
        $disabled = Organization::factory()->disabled()->create(['portal_enabled_by' => $this->operator->id]);

        foreach ([[$directoryOnly, OrganizationRelationshipException::ALREADY_ENABLED], [$disabled, OrganizationRelationshipException::ENABLEMENT_REFUSED]] as [$organization, $reason]) {
            $before = directoryWorld();

            try {
                $this->directory->enablePortalAccess($organization, $this->operator);
                $this->fail('the enablement must be refused');
            } catch (OrganizationRelationshipException $e) {
                expect($e->reason)->toBe($reason);
            }

            expect(directoryWorld())->toBe($before);
        }

        expect(DB::table('organizations')->where('id', $disabled->id)->value('disabled_at'))->not->toBeNull();
    });
});

// ── The direct-service security matrix (CP5) ─────────────────────────────────────────────────

/** @return array<string, Closure(User, OrganizationDirectory, Organization): mixed> every operation, called directly */
function directoryOperations(): array
{
    return [
        'create' => fn (User $actor, OrganizationDirectory $d) => $d->create($actor, ['name' => 'Denied']),
        'update' => fn (User $actor, OrganizationDirectory $d, Organization $o) => $d->update($o, $actor, ['phone' => '999']),
        'classify' => fn (User $actor, OrganizationDirectory $d, Organization $o) => $d->changeClassification($o, $actor, Classification::Vendor),
        'delete' => fn (User $actor, OrganizationDirectory $d, Organization $o) => $d->delete($o, $actor),
        'enable' => fn (User $actor, OrganizationDirectory $d, Organization $o) => $d->enablePortalAccess($o, $actor),
    ];
}

/** @return array<string, Closure(): User> actors who must be refused every operation */
function directoryDeniedActors(): array
{
    return [
        'an ordinary customer' => fn () => User::factory()->create()->assignRole('user'),
        'a suspended operator' => function () {
            $actor = User::factory()->create()->assignRole('operator');
            DB::table('users')->where('id', $actor->id)->update(['disabled_at' => now()]);

            return User::findOrFail($actor->id);
        },
        'an operator without crm.manage' => fn () => User::factory()->create()->givePermissionTo('portal.operator'),
        'crm.manage without operator eligibility' => fn () => User::factory()->create()->givePermissionTo('crm.manage'),
        'a custom role that was never given both' => function () {
            $role = Role::create(['name' => 'half-operator', 'guard_name' => 'web'])->givePermissionTo('crm.manage');

            return User::factory()->create()->assignRole($role);
        },
        // The Gate (answered from the permission cache, before the transaction) still says yes; the TABLES, read under the lock, say no.
        'an actor whose permission cache is stale' => function () {
            $actor = User::factory()->create()->givePermissionTo(['portal.operator', 'crm.manage']);
            expect($actor->can('crm.manage') && $actor->can('portal.operator'))->toBeTrue('the stale state was not primed');
            DB::table('model_has_permissions')->where('model_id', $actor->id)->where('model_type', User::class)->delete();

            return $actor;   // the same instance: its loaded permissions are the stale ones
        },
    ];
}

it('refuses every operation to every denied actor, directly, leaving no write, no membership change and no audit entry', function (string $actorKind, string $operation) {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $actor = directoryDeniedActors()[$actorKind]();
    $before = directoryWorld();

    expect(fn () => directoryOperations()[$operation]($actor, $this->directory, $organization))->toThrow(AuthorizationException::class)
        ->and(directoryWorld())->toBe($before);
})->with(array_keys(directoryDeniedActors()))->with(array_keys(directoryOperations()));

it('refuses at the Gate before it takes a single lock or opens a transaction when the actor plainly may not (the first of the two checks)', function (string $operation) {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $customer = User::factory()->create()->assignRole('user');
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = ['sql' => preg_replace('/\s+/', ' ', $query->sql), 'level' => DB::transactionLevel()];
    });

    expect(fn () => directoryOperations()[$operation]($customer, $this->directory, $organization))->toThrow(AuthorizationException::class);

    $locks = array_filter($statements, fn ($s) => str_contains($s['sql'], 'lock in share mode') || str_contains($s['sql'], 'for update'));
    $inside = array_filter($statements, fn ($s) => $s['level'] > 1);
    expect($locks)->toBe([])->and($inside)->toBe([]);
})->with(array_keys(directoryOperations()));

it('asks the tables again inside the transaction: a Gate that still answers yes does not carry a revoked actor through', function (string $operation) {
    // The stale-cache actor reaches the transaction (the Gate allows it) and is refused THERE, which is what this isolates: the refusal does not come from the Gate.
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $actor = directoryDeniedActors()['an actor whose permission cache is stale']();
    $gate = $operation === 'enable' ? 'manageMembership' : 'manageDirectory';
    $reachedTheTransaction = false;
    DB::listen(function ($query) use (&$reachedTheTransaction) {
        if (DB::transactionLevel() > 1 && str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'lock in share mode')) {
            $reachedTheTransaction = true;
        }
    });

    expect(Gate::forUser($actor)->allows($gate, Organization::class))->toBeTrue('the Gate is expected to be stale and allow')
        ->and(fn () => directoryOperations()[$operation]($actor, $this->directory, $organization))->toThrow(AuthorizationException::class)
        ->and($reachedTheTransaction)->toBeTrue('the refusal must come from inside the locked transaction, not from the Gate');
})->with(array_keys(directoryOperations()));

it('accepts the operators who hold both capabilities, however they got them', function (string $kind) {
    $actor = match ($kind) {
        'the built-in operator' => $this->operator,
        'a custom role holding both' => User::factory()->create()->assignRole(Role::create(['name' => 'dir-custom', 'guard_name' => 'web'])->givePermissionTo(['portal.operator', 'crm.manage'])),
        'both permissions granted directly' => User::factory()->create()->givePermissionTo(['portal.operator', 'crm.manage']),
    };
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $actor = User::findOrFail($actor->id);

    expect($this->directory->create($actor, ['name' => 'Allowed '.$kind])->name)->toBe('Allowed '.$kind);
})->with(['the built-in operator', 'a custom role holding both', 'both permissions granted directly']);

it('takes the actor row, then the Organization row, in every operation, and asks neither the Gate nor the permission cache after the first lock', function (string $operation) {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $gateCalls = 0;
    Gate::after(function () use (&$gateCalls) {
        if (DB::transactionLevel() > 1) {
            $gateCalls++;
        }
    });
    $inside = [];
    DB::listen(function ($query) use (&$inside) {
        if (DB::transactionLevel() > 1) {
            $inside[] = preg_replace('/\s+/', ' ', $query->sql);
        }
    });

    directoryOperations()[$operation]($this->operator, $this->directory, $organization);

    $locks = array_values(array_filter($inside, fn ($sql) => str_contains($sql, 'lock in share mode') || str_contains($sql, 'for update')));
    $cache = array_filter($inside, fn ($sql) => str_contains($sql, '`cache`') || str_contains($sql, 'from `roles`') || preg_match('/^select \* from `permissions`/', $sql) === 1);

    expect($gateCalls)->toBe(0)->and($cache)->toBe([])
        ->and($locks[0])->toContain('from `users`')->toContain('lock in share mode');

    if ($operation !== 'create') {
        expect($locks[1])->toContain('from `organizations`')->toContain('for update');
    }
})->with(array_keys(directoryOperations()));

it('lets an actor who is only a customer of the Organization do none of it, whatever role they hold in it', function () {
    $organization = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $admin = User::factory()->create()->assignRole('user');
    $organization->members()->attach($admin, ['role' => 'admin']);
    DB::table('organization_members')->where('user_id', $admin->id)->update(['admin_approved_at' => now(), 'admin_approved_by' => $this->operator->id]);
    $before = directoryWorld();

    foreach (directoryOperations() as $name => $operation) {
        expect(fn () => $operation($admin, $this->directory, $organization))->toThrow(AuthorizationException::class, '', 0);
    }

    expect(directoryWorld())->toBe($before);
});

// ── F-8 (CP6): the slug probe is bounded, and ends in a unique key ───────────────────────────────────────────────────────────────────────────────────────────────────

it('bounds the slug probe: a crowded base walks at most the probe limit, then takes a random suffix, and still ends unique', function () {
    foreach (range(1, 40) as $n) {
        Organization::factory()->directoryOnly()->create(['name' => 'Crowd Co', 'slug' => $n === 1 ? 'crowd-co' : "crowd-co-{$n}", 'created_by' => $this->operator->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $organization = $this->directory->create($this->operator, ['name' => 'Crowd Co']);
    $probes = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from `organizations` where `slug` = ?'))->count();
    DB::disableQueryLog();

    expect($probes)->toBeLessThanOrEqual(20, 'the probe is bounded')
        ->and($organization->slug)->toMatch('/^crowd-co-[a-z0-9]{8}$/')
        ->and(DB::table('organizations')->where('name', 'Crowd Co')->pluck('slug')->unique())->toHaveCount(41);
});
