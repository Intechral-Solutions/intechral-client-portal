<?php

/*
 * Release 1.0 (DIR-1, section G): the role editor marks the permissions that no application code checks as "Not used in this release".
 *
 * The mark is a CLAIM ABOUT THE CODE, so this file does not trust the list: it scans the application for every listed name and fails the
 * moment something starts checking one, and it proves the scanner itself can see a check (a scanner that finds nothing proves nothing). The
 * permissions stay assignable and every role is untouched: this package changes presentation only.
 */

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Permissions\UnusedPermissions;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** The element id the role editor gives a permission's description (the view slugs the name). */
function dir1UnusedId(string $permission): string
{
    return 'permission-unused-'.Str::slug(str_replace('.', '-', $permission));
}

/** The DIR-0 inventory, spelled out so an accidental addition or removal in UnusedPermissions fails here. */
const DIR1_UNUSED = [
    'org.admin', 'org.invite', 'org.manage_roles',
    'crm.view', 'crm.create', 'crm.admin',
    'tickets.view_org', 'projects.view_org', 'tasks.view_org', 'billing.view_org', 'time.view_org',
];

// ── The scanner (and proof that it can see a check) ───────────────────────────

/** Whether PHP source checks the permission: a string that names it (alone, in `can:x`, in `a|b`), or its catalogue constant. */
function dir1PhpChecks(string $source, string $permission, string $constant): bool
{
    $pattern = '/(?<![\w.])'.preg_quote($permission, '/').'(?![\w])/';

    foreach (token_get_all($source) as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && preg_match($pattern, $token[1]) === 1) {
            return true;
        }

        // `PermissionCatalogue::ORG_ADMIN` (and `static::` / `self::` inside the catalogue, which is excluded from the scan).
        if ($token[0] === T_STRING && $token[1] === $constant) {
            return true;
        }
    }

    return false;
}

/** Blade: the name in quotes, or its catalogue constant, anywhere outside a `{{-- --}}` comment (so `@can`, `@php` and `{{ }}` are all covered). */
function dir1BladeChecks(string $source, string $permission, ?string $constant = null): bool
{
    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    return preg_match('/[\'"`]'.preg_quote($permission, '/').'[\'"`]/', $source) === 1
        || ($constant !== null && preg_match('/::'.preg_quote($constant, '/').'\b/', $source) === 1);
}

/** @return list<string> repo-relative paths of the files that check the permission */
function dir1CheckingFiles(string $permission, string $constant): array
{
    $base = base_path();
    $excluded = [
        // The definitions themselves, and the resolver's list of capabilities that are "neutral" (it names them in order to say they gate nothing).
        'app/Shared/Permissions/PermissionCatalogue.php',
        'app/Shared/Permissions/UnusedPermissions.php',
        'app/Shared/Shell/ShellPresentationResolver.php',
    ];
    $found = [];

    foreach (['app', 'routes', 'config', 'bootstrap', 'database/seeders', 'resources/views', 'resources/js'] as $directory) {
        if (! is_dir("$base/$directory")) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$directory", FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = ltrim(str_replace($base, '', $file->getPathname()), '/');
            $name = $file->getFilename();

            if (in_array($relative, $excluded, true) || str_contains($relative, 'resources/js/actions/') || str_contains($relative, 'resources/js/routes/') || preg_match('/\.test\.tsx?$/', $name)) {
                continue;
            }

            if (str_ends_with($name, '.blade.php')) {
                $hit = dir1BladeChecks(file_get_contents($file->getPathname()), $permission, $constant);
            } elseif (str_ends_with($name, '.php')) {
                $hit = dir1PhpChecks(file_get_contents($file->getPathname()), $permission, $constant);
            } elseif (preg_match('/\.tsx?$/', $name) === 1) {
                $hit = preg_match('/[\'"`]'.preg_quote($permission, '/').'[\'"`]/', file_get_contents($file->getPathname())) === 1;
            } else {
                continue;
            }

            $hit && $found[] = $relative;
        }
    }

    return $found;
}

it('has a scanner that sees every shape of permission check, and ignores comments and longer names', function () {
    $see = fn (string $php) => dir1PhpChecks($php, 'crm.view', 'CRM_VIEW');

    expect($see("<?php \$user->can('crm.view');"))->toBeTrue()
        ->and($see('<?php $user->can("crm.view");'))->toBeTrue()
        ->and($see("<?php Route::middleware(['auth', 'can:crm.view'])->get('/x');"))->toBeTrue()
        ->and($see("<?php Route::middleware('permission:crm.view|crm.manage')->get('/x');"))->toBeTrue()
        ->and($see('<?php Gate::allows(PermissionCatalogue::CRM_VIEW);'))->toBeTrue()
        ->and($see("<?php \$user->hasPermissionTo('crm.view');"))->toBeTrue()
        // Not checks: a comment, a docblock, a different permission whose name merely begins with this one, and a different constant.
        ->and($see("<?php // crm.view is unused\n/** crm.view */"))->toBeFalse()
        ->and($see("<?php \$user->can('crm.view_org');"))->toBeFalse()
        ->and($see('<?php Gate::allows(PermissionCatalogue::CRM_MANAGE);'))->toBeFalse()
        ->and(dir1BladeChecks("@can('crm.view') x @endcan", 'crm.view'))->toBeTrue()
        ->and(dir1BladeChecks("{{-- @can('crm.view') --}}", 'crm.view'))->toBeFalse()
        ->and(dir1BladeChecks('@if (Gate::allows(PermissionCatalogue::CRM_VIEW)) x @endif', 'crm.view', 'CRM_VIEW'))->toBeTrue()
        ->and(dir1BladeChecks("@can('crm.view_org') x @endcan", 'crm.view'))->toBeFalse();
});

it('finds the checks of a permission the product really uses, so an empty result below means something', function () {
    expect(dir1CheckingFiles('crm.manage', 'CRM_MANAGE'))->not->toBeEmpty()
        ->and(dir1CheckingFiles('users.view', 'USERS_VIEW'))->not->toBeEmpty()
        ->and(dir1CheckingFiles('portal.operator', 'PORTAL_OPERATOR'))->not->toBeEmpty();
});

// ── The claim ──────────────────────────────────────────────────────────────────

it('lists exactly the DIR-0 inventory of unused permissions, each one in the catalogue', function () {
    $listed = array_keys(UnusedPermissions::all());

    expect($listed)->toEqualCanonicalizing(DIR1_UNUSED)
        ->and(array_diff($listed, PermissionCatalogue::all()))->toBe([]);

    foreach (UnusedPermissions::all() as $permission => $reason) {
        expect($reason)->not->toBe('')->and(UnusedPermissions::reason($permission))->toBe($reason);
    }

    expect(UnusedPermissions::reason('crm.manage'))->toBeNull()->and(UnusedPermissions::reason('does.not_exist'))->toBeNull();
});

it('verifies against the source that no code checks any permission marked as not used', function (string $permission, string $constant) {
    expect(dir1CheckingFiles($permission, $constant))->toBe([], "$permission is marked \"Not used in this release\" but is checked; remove it from UnusedPermissions.");
})->with(collect(DIR1_UNUSED)->mapWithKeys(fn (string $permission) => [
    $permission => [$permission, strtoupper(str_replace('.', '_', $permission))],
])->all());

it('finds no dynamic construction of a view_org permission name either', function () {
    // `"{$module}.view_org"` and `$module.'.view_org'` would defeat the literal scan above.
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

        if (! str_ends_with($relative, '.php') || in_array($relative, ['app/Shared/Permissions/PermissionCatalogue.php', 'app/Shared/Permissions/UnusedPermissions.php'], true)) {
            continue;
        }

        foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true) && str_contains($token[1], 'view_org')) {
                $files[] = $relative;
            }
        }
    }

    expect($files)->toBe([]);
});

// ── The role editor ────────────────────────────────────────────────────────────

it('marks each unused permission on the edit page and leaves every other permission unmarked', function () {
    $role = Role::create(['name' => 'dir1-custom', 'guard_name' => 'web']);
    $page = $this->actingAs($this->operator)->get(route('roles.edit', $role))->assertOk();
    $html = $page->getContent();

    expect(substr_count($html, 'id="permission-unused-'))->toBe(count(DIR1_UNUSED));

    foreach (DIR1_UNUSED as $permission) {
        $id = dir1UnusedId($permission);
        expect($html)->toContain('id="'.$id.'"');
    }

    $page->assertSee('Not used in this release')
        ->assertSee('Customer-side organization delegation is a later release.')
        ->assertSee('Directory routes check crm.manage only.')
        ->assertSee('Visibility is decided by organization and project membership; no code reads this permission.');

    // Used permissions carry no mark.
    foreach (['crm.manage', 'users.view', 'portal.operator', 'tickets.view'] as $used) {
        expect($html)->not->toContain('id="'.dir1UnusedId($used).'"');
    }
});

it('marks the same permissions on the create page', function () {
    $html = $this->actingAs($this->operator)->get(route('roles.create'))->assertOk()->getContent();

    expect(substr_count($html, 'id="permission-unused-'))->toBe(count(DIR1_UNUSED));
});

it('keeps a marked permission an ordinary, enabled, submittable checkbox bound to its description', function () {
    $role = Role::create(['name' => 'dir1-assignable', 'guard_name' => 'web']);
    $html = $this->actingAs($this->operator)->get(route('roles.edit', $role))->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    foreach (DIR1_UNUSED as $permission) {
        $box = $xpath->query('//input[@type="checkbox"][@name="permissions[]"][@value="'.$permission.'"]');

        expect($box->length)->toBe(1, $permission);
        $input = $box->item(0);
        expect($input->hasAttribute('disabled'))->toBeFalse($permission)
            ->and($input->getAttribute('aria-describedby'))->toBe(dir1UnusedId($permission));
    }

    // And an unmarked one has no description to point at.
    $used = $xpath->query('//input[@name="permissions[]"][@value="crm.manage"]')->item(0);
    expect($used->hasAttribute('aria-describedby'))->toBeFalse();
});

it('still saves a marked permission onto a custom role, exactly as before', function () {
    $role = Role::create(['name' => 'dir1-save', 'guard_name' => 'web']);

    $this->actingAs($this->operator)->put(route('roles.update', $role), ['permissions' => [...DIR1_UNUSED, 'crm.manage']])
        ->assertRedirect(route('roles.index'));

    expect($role->fresh()->permissions->pluck('name')->all())->toEqualCanonicalizing([...DIR1_UNUSED, 'crm.manage']);

    $created = $this->actingAs($this->operator)->post(route('roles.store'), ['name' => 'dir1-created', 'permissions' => ['org.admin', 'crm.view']]);
    $created->assertRedirect(route('roles.index'));

    expect(Role::findByName('dir1-created')->permissions->pluck('name')->all())->toEqualCanonicalizing(['org.admin', 'crm.view']);
});

it('leaves the catalogue and the built-in roles exactly as they were', function () {
    // Visiting the editor changes nothing.
    $this->actingAs($this->operator)->get(route('roles.edit', Role::findByName('operator', 'web')))->assertOk();
    $this->actingAs($this->operator)->get(route('roles.edit', Role::findByName('user', 'web')))->assertOk();

    expect(Role::findByName('operator', 'web')->permissions->pluck('name')->all())->toEqualCanonicalizing(PermissionCatalogue::all())
        ->and(Role::findByName('user', 'web')->permissions->pluck('name')->all())->toEqualCanonicalizing(PermissionCatalogue::userDefaults())
        ->and(BuiltInRoles::problems())->toBe([])
        ->and(Role::count())->toBe(2);

    // The unused permissions are still in the operator role: nothing was removed to make the page tidier.
    foreach (DIR1_UNUSED as $permission) {
        expect(Role::findByName('operator', 'web')->hasPermissionTo($permission))->toBeTrue($permission);
    }
});

it('annotates a built-in role\'s permissions too, while keeping them read-only as before', function () {
    $html = $this->actingAs($this->operator)->get(route('roles.edit', Role::findByName('operator', 'web')))->assertOk()->getContent();

    expect(substr_count($html, 'id="permission-unused-'))->toBe(count(DIR1_UNUSED))
        ->and($html)->toContain('<fieldset disabled');
});
