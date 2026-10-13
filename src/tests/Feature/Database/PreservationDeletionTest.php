<?php

use App\Models\CmsPage;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\CommittedUsers;

/*
 * Release 0.9 (R0.9-1B, decisions H1 and H2): what the database now does when a parent is deleted. A user who owns
 * durable business records cannot be deleted, and the refused delete loses nothing; a company that projects still
 * reference cannot be deleted. The things H1 deliberately left alone still behave as before (credential and
 * membership children cascade, nullable pointers are cleared). These rows are created inside the RefreshDatabase
 * transaction, so the refused DELETEs are real statements against the real constraints.
 */

/**
 * One closure per H1 foreign key: given the user, create a record that references them through that key and
 * return [table, id].
 *
 * @return array<string, Closure(User): array{0: string, 1: int}>
 */
function recordsOwnedThroughEachKey(): array
{
    return [
        'tickets.user_id' => fn (User $u) => ['tickets', Ticket::factory()->create(['user_id' => $u->id])->id],
        'ticket_replies.user_id' => fn (User $u) => ['ticket_replies', TicketReply::factory()->create(['user_id' => $u->id])->id],
        'ticket_attachments.user_id' => fn (User $u) => ['ticket_attachments', TicketAttachment::create([
            'ticket_id' => Ticket::factory()->create()->id, 'reply_id' => null, 'user_id' => $u->id,
            'filename' => 'notes.txt', 'path' => 'tickets/1/notes.txt', 'mime_type' => 'text/plain', 'size' => 1,
        ])->id],
        'time_entries.user_id' => fn (User $u) => ['time_entries', TimeEntry::factory()->create(['user_id' => $u->id])->id],
        'task_comments.user_id' => fn (User $u) => ['task_comments', TaskComment::factory()->create(['user_id' => $u->id, 'task_id' => Task::factory()->standalone()->create()->id])->id],
        'tasks.created_by' => fn (User $u) => ['tasks', Task::factory()->standalone()->create(['created_by' => $u->id])->id],
        'projects.created_by' => fn (User $u) => ['projects', Project::factory()->create(['created_by' => $u->id])->id],
        'organizations.owner_id' => fn (User $u) => ['organizations', Organization::factory()->create(['owner_id' => $u->id])->id],
        'crm_companies.created_by' => fn (User $u) => ['crm_companies', CrmCompany::factory()->create(['created_by' => $u->id])->id],
        'crm_contacts.created_by' => fn (User $u) => ['crm_contacts', CrmContact::factory()->create(['created_by' => $u->id])->id],
        'cms_pages.created_by' => fn (User $u) => ['cms_pages', CmsPage::factory()->create(['created_by' => $u->id])->id],
        'invoices.client_id' => fn (User $u) => ['invoices', Invoice::factory()->create(['client_id' => $u->id])->id],
        'invoices.created_by' => fn (User $u) => ['invoices', Invoice::factory()->create(['created_by' => $u->id])->id],
    ];
}

it('refuses to delete a user referenced by a durable business record, and loses nothing', function (Closure $own) {
    $user = User::factory()->create();
    [$table, $id] = $own($user);
    $users = DB::table('users')->count();
    $records = DB::table($table)->count();

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())
        ->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue()
        ->and(DB::table($table)->where('id', $id)->exists())->toBeTrue()
        ->and(DB::table('users')->count())->toBe($users)
        ->and(DB::table($table)->count())->toBe($records);
})->with(recordsOwnedThroughEachKey());

it('still deletes a user who owns no durable record, and removes only their credential and membership children', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $owner->id]);
    $organization = Organization::factory()->create(['owner_id' => $owner->id]);
    $user = User::factory()->create();

    $now = now();
    DB::table('password_histories')->insert(['user_id' => $user->id, 'password' => 'hash', 'created_at' => $now]);
    DB::table('social_accounts')->insert(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-'.$user->id, 'token' => 't', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => $now, 'updated_at' => $now]);

    DB::table('users')->where('id', $user->id)->delete();

    expect(DB::table('users')->where('id', $user->id)->exists())->toBeFalse();

    foreach (['password_histories', 'social_accounts', 'project_members', 'organization_members'] as $child) {
        expect(DB::table($child)->where('user_id', $user->id)->exists())->toBeFalse("{$child} should have been removed with its user");
    }

    // The things they belonged to are untouched.
    expect(DB::table('projects')->where('id', $project->id)->exists())->toBeTrue()
        ->and(DB::table('organizations')->where('id', $organization->id)->exists())->toBeTrue();
});

it('still clears a nullable pointer to a deleted user and keeps the record', function () {
    $assignee = User::factory()->create();
    $ticket = Ticket::factory()->create(['assignee_id' => $assignee->id]);
    $task = Task::factory()->standalone()->create(['assignee_id' => $assignee->id]);

    DB::table('users')->where('id', $assignee->id)->delete();

    expect(DB::table('tickets')->where('id', $ticket->id)->value('assignee_id'))->toBeNull()
        ->and(DB::table('tasks')->where('id', $task->id)->value('assignee_id'))->toBeNull()
        ->and(DB::table('tickets')->where('id', $ticket->id)->exists())->toBeTrue();
});

it('still lets the browser suite delete the throwaway members it creates (role, direct permission, project membership)', function () {
    // tests/Browser/projects-migration.spec.ts creates two users, makes them members of a project, lets one rename it,
    // and deletes both by email afterwards. They own no durable record, so the new keys do not touch that teardown.
    // Since R0.9-1C it deletes them through Eloquent, one model at a time, so their role and permission rows go too
    // (the earlier query-builder delete fired no model events and left those pivot rows orphaned).
    $project = Project::factory()->create();
    $role = Role::findOrCreate('user', 'web');
    $manager = User::factory()->create(['email' => 'e2e-wp3-manager@intechral.test']);
    $hostile = User::factory()->create(['email' => 'e2e-wp3-hostile@intechral.test']);
    $manager->assignRole($role);
    $manager->givePermissionTo(Permission::findOrCreate('projects.manage', 'web'));

    $now = now();
    foreach ([$manager, $hostile] as $member) {
        DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::table('projects')->where('id', $project->id)->update(['name' => 'E2E WP3 renamed by manager']);

    User::whereIn('email', ['e2e-wp3-manager@intechral.test', 'e2e-wp3-hostile@intechral.test'])->get()->each->delete();

    $ids = [$manager->id, $hostile->id];
    expect(DB::table('users')->whereIn('id', $ids)->exists())->toBeFalse()
        ->and(DB::table('project_members')->where('project_id', $project->id)->whereIn('user_id', $ids)->exists())->toBeFalse()
        ->and(DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $ids)->exists())->toBeFalse()
        ->and(DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $ids)->exists())->toBeFalse()
        ->and(DB::table('projects')->where('id', $project->id)->exists())->toBeTrue();
});

/*
 * R0.9-1B review finding L1, fixed in R0.9-1C. This test was a CHARACTERIZATION asserting the grants were GONE: the
 * permission library's `deleting` hooks detach roles and direct permissions BEFORE the DELETE statement, so a delete
 * the database then refused had already stripped the user's access. Its intent is deliberately inverted: User::delete()
 * now runs in a transaction, so the refused delete rolls the detach back and the grants SURVIVE. (Offboarding is
 * suspension, H3, never deletion.) Inside RefreshDatabase that transaction is a savepoint; the committed variant below
 * proves the top-level case.
 */
it('L1 regression (R0.9-1C): a refused Eloquent delete of a protected user keeps its roles and direct permissions', function () {
    $user = User::factory()->create();
    Ticket::factory()->create(['user_id' => $user->id]);
    $user->assignRole(Role::findOrCreate('user', 'web'));
    $user->givePermissionTo(Permission::findOrCreate('projects.manage', 'web'));

    expect(fn () => $user->delete())->toThrow(QueryException::class, 'Cannot delete or update a parent row');

    $reloaded = User::findOrFail($user->id);
    expect(DB::table('tickets')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->count())->toBe(1)
        ->and(DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->count())->toBe(1)
        ->and($reloaded->hasRole('user'))->toBeTrue()
        ->and($reloaded->hasDirectPermission('projects.manage'))->toBeTrue();
});

it('L1 regression (R0.9-1C): outside any test transaction, User::destroy() and forceDelete() of a protected user keep its grants', function () {
    DB::commit(); // end the RefreshDatabase transaction: User::delete() must open the top-level transaction itself
    expect(DB::transactionLevel())->toBe(0);

    // Committed rows, so every name is unique to this run and removed in the finally block.
    $suffix = uniqid();
    $role = Role::create(['name' => "l1-role-{$suffix}", 'guard_name' => 'web']);
    $permission = Permission::create(['name' => "l1.permission-{$suffix}", 'guard_name' => 'web']);
    $user = User::factory()->create();
    $ticket = Ticket::factory()->create(['user_id' => $user->id]);

    try {
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        foreach (['destroy' => fn () => User::destroy($user->id), 'forceDelete' => fn () => $user->forceDelete()] as $path => $delete) {
            expect($delete)->toThrow(QueryException::class, 'Cannot delete or update a parent row');

            expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue("{$path}: the user row")
                ->and(DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->where('role_id', $role->id)->exists())->toBeTrue("{$path}: the role")
                ->and(DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->where('permission_id', $permission->id)->exists())->toBeTrue("{$path}: the direct permission");
        }
    } finally {
        DB::table('tickets')->where('id', $ticket->id)->delete();
        $user->delete(); // Eloquent, so its pivot rows go with it
        $role->delete();
        $permission->delete();
    }

    expect(DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $user->id)->exists())->toBeFalse();
});

describe('a company that projects reference (H2)', function () {
    it('cannot be deleted, and the association survives the refused delete', function () {
        $company = CrmCompany::factory()->create();
        $project = Project::factory()->create();
        DB::table('project_company')->insert(['project_id' => $project->id, 'crm_company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => DB::table('crm_companies')->where('id', $company->id)->delete())
            ->toThrow(QueryException::class, 'Cannot delete or update a parent row');

        expect(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeTrue()
            ->and(DB::table('project_company')->where('crm_company_id', $company->id)->where('project_id', $project->id)->exists())->toBeTrue();
    });

    it('can be deleted again once no project references it, and its contacts are detached, not deleted', function () {
        $company = CrmCompany::factory()->create();
        $contact = CrmContact::factory()->create(['crm_company_id' => $company->id]);
        $project = Project::factory()->create();
        DB::table('project_company')->insert(['project_id' => $project->id, 'crm_company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);

        // Removing the PROJECT removes its association rows (project_company.project_id still cascades)...
        DB::table('projects')->where('id', $project->id)->delete();
        expect(DB::table('project_company')->where('crm_company_id', $company->id)->exists())->toBeFalse();

        // ...and then the company can go, leaving its contact with no company.
        DB::table('crm_companies')->where('id', $company->id)->delete();

        expect(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeFalse()
            ->and(DB::table('crm_contacts')->where('id', $contact->id)->exists())->toBeTrue()
            ->and(DB::table('crm_contacts')->where('id', $contact->id)->value('crm_company_id'))->toBeNull();
    });

    it('can still be deleted when no project references it', function () {
        $company = CrmCompany::factory()->create();

        DB::table('crm_companies')->where('id', $company->id)->delete();

        expect(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeFalse();
    });
});

describe('the committed-test cleanup helper', function () {
    it('removes a user and every kind of record they own, in dependency order, and nothing else', function () {
        $user = User::factory()->create();
        $bystander = User::factory()->create();
        $bystanderTicket = Ticket::factory()->create(['user_id' => $bystander->id]);
        $bystanderProject = Project::factory()->create(['created_by' => $bystander->id]);

        $owned = [];
        foreach (recordsOwnedThroughEachKey() as $key => $own) {
            $owned[$key] = $own($user);
        }

        CommittedUsers::delete($user->id);

        expect(DB::table('users')->where('id', $user->id)->exists())->toBeFalse();
        foreach ($owned as $key => [$table, $id]) {
            expect(DB::table($table)->where('id', $id)->exists())->toBeFalse("{$key}: the record should have been removed");
        }

        expect(DB::table('users')->where('id', $bystander->id)->exists())->toBeTrue()
            ->and(DB::table('tickets')->where('id', $bystanderTicket->id)->exists())->toBeTrue()
            ->and(DB::table('projects')->where('id', $bystanderProject->id)->exists())->toBeTrue();
    });

    it('does nothing for an empty list of users', function () {
        $user = User::factory()->create();

        CommittedUsers::delete([]);

        expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
    });
});
