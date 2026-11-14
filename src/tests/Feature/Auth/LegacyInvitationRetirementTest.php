<?php

/*
 * Release 1.0 (R1-3B): the organization-less invitation is RETIRED, issuance and acceptance, including the single-sign-on branch
 * (design record 7.3). The legacy `invitations` table, its model and `users.invitation_id` stay as inert history. These tests pin the
 * retirement on every entry point and, at source level, that nothing in production code can read a legacy token again.
 */

require_once __DIR__.'/InvitationTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationInvitations;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use App\Shared\Pilot\CustomerSurfaceMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Notification::fake();
    $this->seedRolesAndPermissions();
});

/** A plaintext token shaped like the ones the retired table held (64 random characters). The table is gone, so it can resolve nowhere. */
function retiredToken(): string
{
    return Str::random(64);
}

describe('a link from the retired invitation is dead on every path', function () {
    it('GET: the one invalid page', function () {
        $this->get(route('invitation.show', retiredToken()))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid')->missing('token')->missing('invitation'));
    });

    it('POST: refused like any unknown link; no account, no membership, no sign-in, nothing written', function () {
        $token = retiredToken();
        $footprint = regInvFootprint();

        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($footprint);
    });

    it('SSO initiation: no context is stored and nothing is started', function () {
        Socialite::fake('google', SocialiteUser::fake(['email_verified' => true, 'id' => 'x', 'email' => 'legacy@example.test']));

        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => retiredToken()]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.'])
            ->assertSessionMissing('oauth_context');
    });

    it('SSO callback: neither a legacy-shaped context (plaintext token) nor a hash of a retired token resolves anything', function () {
        Socialite::fake('google', SocialiteUser::fake(['email_verified' => true, 'id' => 'x', 'email' => 'legacy@example.test']));
        $token = retiredToken();

        foreach ([
            ['intent' => 'invitation', 'provider' => 'google', 'initiatingUserId' => null, 'invitationToken' => $token, 'startedAt' => now()->toIso8601String()],
            ['intent' => 'invitation', 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => hash('sha256', $token), 'startedAt' => now()->toIso8601String()],
        ] as $context) {
            $this->withSession(['oauth_context' => $context])->get(route('sso.callback', 'google'))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        expect(User::where('email', 'legacy@example.test')->exists())->toBeFalse()->and(DB::table('social_accounts')->count())->toBe(0);
    });

    it('ISSUANCE: POST /invitations no longer exists, for a guest, an operator or a customer', function () {
        $operator = User::factory()->create()->assignRole('operator');

        expect(Route::has('invitations.store'))->toBeFalse();

        foreach ([null, $operator, User::factory()->create()->assignRole('user')] as $actor) {
            $request = $actor ? $this->actingAs($actor) : $this;
            $request->post('/invitations', ['email' => 'new@example.test'])->assertNotFound();
        }

        Notification::assertNothingSent();
        expect(DB::table('organization_invitations')->count())->toBe(0);
    });
});

describe('the retired persistence is gone from the final schema', function () {
    it('has no invitations table and no users.invitation_id, and keeps users.invited_by and organization_invitations', function () {
        expect(Schema::hasTable('invitations'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'invitation_id'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'invited_by'))->toBeTrue()
            ->and(Schema::hasTable('organization_invitations'))->toBeTrue();

        $foreign = collect(Schema::getForeignKeys('users'))->map(fn ($key) => implode(',', $key['columns']).'->'.$key['foreign_table'])->sort()->values()->all();
        expect($foreign)->toContain('invited_by->users')->not->toContain('invitation_id->invitations');
    });
});

describe('the retired permission is gone', function () {
    it('is not in the catalogue, the built-in role definitions or a freshly seeded installation', function () {
        expect(PermissionCatalogue::all())->not->toContain('users.invite')
            ->and(defined(PermissionCatalogue::class.'::USERS_INVITE'))->toBeFalse();

        foreach (BuiltInRoles::definitions() as $role => $permissions) {
            expect($permissions)->not->toContain('users.invite', "built-in role {$role}");
        }

        // beforeEach() seeded a fresh installation through the real seeders.
        expect(Permission::where('name', 'users.invite')->exists())->toBeFalse()
            ->and(DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'users.invite')->exists())->toBeFalse();
    });

    it('is referenced by no production path (organization invitation authority is the membership policy, nothing global)', function () {
        $hits = [];
        foreach (legacyScanSources() as $path => $contents) {
            if (preg_match('/users\.invite|USERS_INVITE/', $contents)) {
                $hits[] = $path;
            }
        }

        expect($hits)->toBe([]);
    });

    it('lets no account issue an invitation through any global permission: only the membership policy decides', function () {
        // org.invite is the only invitation-named permission left and it has never gated anything.
        $user = User::factory()->create();
        $user->givePermissionTo('org.invite');
        $organization = Organization::factory()->create();

        expect(fn () => app(OrganizationInvitations::class)->issue($organization, 'x@example.test', $user))
            ->toThrow(AuthorizationException::class);
    });
});

describe('nothing in production code can read a legacy token or send a legacy invitation again', function () {
    /** @return array<string, string> relative path => contents, for every PHP, Blade and TypeScript source that ships */
    function legacyScanSources(): array
    {
        $base = dirname(__DIR__, 3);
        $files = [];

        foreach (['app', 'routes', 'resources', 'bootstrap', 'config', 'database/seeders', 'database/factories'] as $directory) {
            if (! is_dir("$base/$directory")) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$base/$directory", FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('/\.(php|ts|tsx)$/', $file->getFilename())) {
                    $files[substr($file->getPathname(), strlen($base) + 1)] = file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }

    it('has deleted the legacy service, notification and exceptions', function () {
        foreach ([
            'App\Services\InvitationService', 'App\Notifications\InvitationNotification',
            'App\Exceptions\InvitationEmailMismatchException', 'App\Exceptions\InvitationUnavailableException',
        ] as $class) {
            // File existence, not class_exists(): an optimized autoloader's stale class map would otherwise try to include the deleted file.
            expect(file_exists(dirname(__DIR__, 3).'/'.str_replace(['App\\', '\\'], ['app/', '/'], $class).'.php'))->toBeFalse("$class must stay deleted");
        }
    });

    it('never references the legacy service, notification, exceptions or token column', function () {
        $patterns = [
            '/\bInvitationService\b/',
            '/(?<!Organization)InvitationNotification\b/',
            '/\bInvitation(EmailMismatch|Unavailable)Exception\b/',
            '/\binvitations\.token\b/',
            '/\bacceptWith(Password|SocialAccount)\b/',
            '/\binvitationToken\b/',
            '/DB::table\(\s*[\'"]invitations[\'"]/',
            '/Schema::\w+\(\s*[\'"]invitations[\'"]/',
        ];

        $hits = [];
        foreach (legacyScanSources() as $path => $contents) {
            // The one place that explains the retirement and rejects the old session shape by name.
            if ($path === 'app/Http/Controllers/Auth/SocialiteController.php') {
                $contents = preg_replace('/^.*invitationToken.*$/m', '', $contents);
            }

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $contents)) {
                    $hits[] = "$path matches $pattern";
                }
            }
        }

        expect($hits)->toBe([]);
    });

    it('has no legacy Invitation model or factory, and nothing refers to them or to users.invitation_id', function () {
        foreach (['app/Models/Invitation.php', 'database/factories/InvitationFactory.php'] as $file) {
            expect(file_exists(dirname(__DIR__, 3).'/'.$file))->toBeFalse("$file must stay deleted");
        }

        $hits = [];
        foreach (legacyScanSources() as $path => $contents) {
            // The migration rehearsal's baseline fixtures model a Release 0.9 database on purpose (the pinned historical migrations still build it).
            if ($path === 'app/Support/Rehearsal/BaselineFixtures.php') {
                continue;
            }

            if (preg_match('/(?<![A-Za-z])Invitation::|Models\\\\Invitation\b(?!s)|[\'"]invitation_id[\'"]\s*=>\s*\$?(?:null|\d)|->invitation\(\)|\$user->invitation\b/', $contents)) {
                $hits[] = $path;
            }
        }

        expect($hits)->toBe([]);
    });

    it('has no route that names the retired controller actions', function () {
        foreach (Route::getRoutes() as $route) {
            // The retired action was `Auth\InvitationController@store`. R1-3C's operator action is a different controller in the
            // Organization namespace (`organizations.invitations.store`), so the check names the namespace, not just the suffix.
            expect($route->getActionName())->not->toBe('App\Http\Controllers\Auth\InvitationController@store')
                ->and($route->getName())->not->toBe('invitations.store');
        }

        expect(array_map(fn ($r) => $r->uri().' '.implode('|', $r->methods()), array_filter(
            iterator_to_array(Route::getRoutes()),
            // The PUBLIC surface only: nothing at /invitations (the retired operator route) and the one token route. The operator routes of
            // R1-3C live under organizations/{organization}/invitations and are pinned in OrganizationInvitationOperatorTest.
            fn ($r) => str_starts_with($r->uri(), 'invitation'),
        )))->toHaveCount(2); // invitation/{token}: GET|HEAD and POST
    });
});

describe('the pilot boundary is unchanged for the routes that remain', function () {
    it('keeps the two registration routes allow-listed by name and adds no customer route', function () {
        foreach (['closed', 'pilot'] as $mode) {
            pilotMode($mode);
            $names = array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::from($mode)));

            expect($names)->toContain('invitation.show')->toContain('invitation.register')->not->toContain('invitations.store');
        }
    });
});
