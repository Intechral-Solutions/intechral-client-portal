<?php

use App\Exceptions\CompanyHasProjectsException;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CrmService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Release 0.9 (R0.9-1D, R0.9-1B follow-up L2): deleting a CRM company that projects still reference used to end in a
 * database error (HTTP 500). The RESTRICT foreign key stays; the application now refuses first, with a business-level
 * answer and no side effect. This is only the refusal: no company archival, erasure or project policy (H6 is not ratified).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** A company linked to $projects projects, with a contact and a ticket that refer to it. */
function linkedCompany(int $projects = 1): array
{
    $company = CrmCompany::factory()->create(['name' => 'Linked Co']);
    $linked = Project::factory()->count($projects)->create();
    $company->projects()->attach($linked->pluck('id')->all());
    $contact = CrmContact::factory()->create(['crm_company_id' => $company->id]);
    $ticket = Ticket::factory()->create(['company_id' => $company->id]);

    return [$company, $linked, $contact, $ticket];
}

/** Everything a refused deletion must leave exactly as it was. */
function companyWorld(CrmCompany $company): array
{
    return [
        (array) DB::table('crm_companies')->where('id', $company->id)->first(),
        DB::table('project_company')->where('crm_company_id', $company->id)->orderBy('project_id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('crm_contacts')->where('crm_company_id', $company->id)->pluck('id')->all(),
        DB::table('tickets')->where('company_id', $company->id)->pluck('id')->all(),
        DB::table('projects')->count(),
    ];
}

it('refuses a browser deletion of a company projects still reference, and keeps every record', function () {
    [$company] = linkedCompany(2);
    $world = companyWorld($company);

    $this->actingAs($this->operator)
        ->from(route('crm.companies.edit', $company))
        ->delete(route('crm.companies.destroy', $company))
        ->assertRedirect(route('crm.companies.edit', $company))
        ->assertSessionHasErrors(['company' => 'Cannot delete "Linked Co": 2 project(s) still refer to it. Remove the company from those projects first.']);

    expect(companyWorld($company))->toBe($world)
        ->and(CrmCompany::find($company->id))->not->toBeNull();
});

it('shows the refusal on the edit page the operator is sent back to', function () {
    [$company] = linkedCompany();

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company));

    $this->actingAs($this->operator)
        ->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['company' => [(new CompanyHasProjectsException('Linked Co', 1))->getMessage()]]]], '_flash' => ['old' => [], 'new' => ['errors']]])
        ->get(route('crm.companies.edit', $company))
        ->assertOk()
        ->assertSee('Cannot delete "Linked Co"');
});

it('answers a JSON caller with 409 and the same message', function () {
    [$company] = linkedCompany();
    $world = companyWorld($company);

    $this->actingAs($this->operator)
        ->deleteJson(route('crm.companies.destroy', $company))
        ->assertStatus(409)
        ->assertJson(['message' => 'Cannot delete "Linked Co": 1 project(s) still refer to it. Remove the company from those projects first.']);

    expect(companyWorld($company))->toBe($world);
});

it('has no side effect before it refuses: contacts and tickets keep their company, nothing is logged', function () {
    [$company, , $contact, $ticket] = linkedCompany();
    $activities = DB::table('activity_log')->count();

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company));

    expect($contact->fresh()->crm_company_id)->toBe($company->id)
        ->and($ticket->fresh()->company_id)->toBe($company->id)
        ->and(DB::table('activity_log')->count())->toBe($activities);
});

it('lets the deletion through once the last project is unlinked', function () {
    [$company, $projects] = linkedCompany(2);
    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))->assertSessionHasErrors('company');

    $company->projects()->detach($projects->first()->id);
    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))->assertSessionHasErrors('company');

    $company->projects()->detach();
    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))->assertRedirect(route('crm.companies.index'));

    expect(CrmCompany::find($company->id))->toBeNull()
        ->and(Project::count())->toBe(2, 'the projects themselves are never touched');
});

it('keeps the existing contract for an unlinked company: it is deleted, its contacts and tickets keep their rows with the company cleared', function () {
    $company = CrmCompany::factory()->create();
    $contact = CrmContact::factory()->create(['crm_company_id' => $company->id]);
    $ticket = Ticket::factory()->create(['company_id' => $company->id]);

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))
        ->assertRedirect(route('crm.companies.index'))
        ->assertSessionHas('success', 'Company deleted.');

    expect(CrmCompany::find($company->id))->toBeNull()
        ->and($contact->fresh()->crm_company_id)->toBeNull()
        ->and($ticket->fresh()->company_id)->toBeNull();
});

it('keeps the authorization: guests and users without crm.manage are refused before anything is checked', function () {
    [$company] = linkedCompany();
    $world = companyWorld($company);

    $this->delete(route('crm.companies.destroy', $company))->assertRedirect('/login');
    // A user without CRM access does not even resolve the company (the organization scope hides it), as before.
    $customer = $this->actingAs(User::factory()->create()->assignRole('user'))->deleteJson(route('crm.companies.destroy', $company));
    $viewer = $this->actingAs(User::factory()->create()->givePermissionTo('crm.view'))->deleteJson(route('crm.companies.destroy', $company));

    expect($customer->status())->toBeIn([403, 404])
        ->and($viewer->status())->toBeIn([403, 404])
        ->and(companyWorld($company))->toBe($world);
});

it('turns the database refusal of a project linked after the check into the same refusal, not a 500', function () {
    // A stale check: the project is linked after the pre-check read zero and before the delete reached the engine.
    // Committed data and a second connection, because the RESTRICT key arbitrates between two sessions.
    DB::commit();
    $company = CrmCompany::factory()->create(['name' => 'Race Co']);
    $project = Project::factory()->create();
    config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
    $second = DB::connectUsing('second', config('database.connections.second'), force: true);
    $raced = false;

    DB::listen(function ($query) use (&$raced, $second, $company, $project) {
        if (! $raced && str_contains($query->sql, '`project_company`') && str_contains($query->sql, 'count(*)')) {
            $raced = true;
            $second->table('project_company')->insert(['project_id' => $project->id, 'crm_company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    try {
        expect(fn () => app(CrmService::class)->deleteCompany($company))->toThrow(CompanyHasProjectsException::class, '1 project(s)');
        expect($raced)->toBeTrue('the association was inserted between the check and the delete')
            ->and(DB::table('crm_companies')->where('id', $company->id)->exists())->toBeTrue()
            ->and(DB::table('project_company')->where('crm_company_id', $company->id)->exists())->toBeTrue();
    } finally {
        DB::table('project_company')->where('crm_company_id', $company->id)->delete();
        DB::table('crm_companies')->where('id', $company->id)->delete();
        $project->delete();
        DB::table('users')->where('id', $company->created_by)->delete();
        DB::disconnect('second');
    }
});

it('does not hide an unrelated database failure behind the refusal', function () {
    // A QueryException that is not the RESTRICT refusal (error 1451) propagates unchanged.
    $company = CrmCompany::factory()->create();
    CrmCompany::deleting(function () {
        throw new QueryException(config('database.default'), 'delete from `crm_companies`', [], new PDOException('boom', 1205));
    });

    try {
        expect(fn () => app(CrmService::class)->deleteCompany($company))->toThrow(QueryException::class);
    } finally {
        CrmCompany::flushEventListeners(); // the listener above must not outlive this test
    }
});
