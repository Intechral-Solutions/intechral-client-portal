<?php

/*
 * Release 1.0 (ORG-2, CP7, finding G-3): what a person SEES when a bounded restart protocol runs out.
 *
 * ProjectService::syncOrganizations and CrmService::updateContact/deleteContact start over when what they planned their locks for moved while they waited, at most
 * five times, and then throw ConflictHttpException with NOTHING written. The services keep that exception (it is the service-level contract and the concurrency
 * tests assert it). The CONTROLLERS turn it into the form-error answer their interface already understands:
 *
 *   - Project synchronization (Inertia/React page): a redirect back carrying `errors.companies`, which the page renders under the field. Never a raw 409 page,
 *     never a success flash.
 *   - Person update / deletion (Blade pages): a redirect back with the typed input and `errors.person`, which the edit page renders as an alert.
 *
 * The services are replaced by stubs that exhaust, because exhaustion needs a second connection to provoke for real; THAT, and the "no partial write" half, is proved
 * against MariaDB by C19 (Projects) and G2 (People) in the two concurrency test files. This file proves the response mapping only.
 */

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Services\CrmService;
use App\Services\ProjectService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator');
});

it('answers an exhausted Project synchronization with the form error the page renders, not a raw 409 and not a success', function () {
    $organization = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $project = Project::factory()->create(['created_by' => $this->operator->id]);
    $project->organizations()->attach($organization);

    $this->mock(ProjectService::class)->shouldReceive('syncOrganizations')->once()
        ->andThrow(new ConflictHttpException('The linked organizations kept changing; try again.'));

    $response = $this->actingAs($this->operator)->from(route('projects.edit', $project))
        ->put(route('projects.companies.sync', $project), ['companies' => []]);

    $response->assertRedirect(route('projects.edit', $project))
        ->assertSessionHasErrors(['companies'])
        ->assertSessionMissing('success');

    expect(session('errors')->first('companies'))->toContain('Nothing was changed')->toContain('try again')
        // The service's internal wording is not what the user reads.
        ->not->toContain('kept changing');
});

it('answers an exhausted Project synchronization sent by Inertia with a redirect back (303 for a PUT; the client turns it into errors), never a 409', function () {
    $project = Project::factory()->create(['created_by' => $this->operator->id]);

    $this->mock(ProjectService::class)->shouldReceive('syncOrganizations')->once()->andThrow(new ConflictHttpException);

    $this->actingAs($this->operator)->from(route('projects.edit', $project))
        ->put(route('projects.companies.sync', $project), ['companies' => []], ['X-Inertia' => 'true'])
        ->assertStatus(303)
        ->assertRedirect(route('projects.edit', $project))
        ->assertSessionHasErrors(['companies']);
});

it('answers an exhausted Person update with the typed input, an alert on the edit page and no success', function () {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $contact = CrmContact::factory()->create(['organization_id' => $organization->id, 'first_name' => 'Original', 'created_by' => $this->operator->id]);

    $this->mock(CrmService::class)->shouldReceive('updateContact')->once()->andThrow(new ConflictHttpException);

    $this->actingAs($this->operator)->from(route('crm.contacts.edit', $contact))
        ->put(route('crm.contacts.update', $contact), ['first_name' => 'Changed', 'last_name' => 'Name', 'organization_id' => $organization->id])
        ->assertRedirect(route('crm.contacts.edit', $contact))
        ->assertSessionHasErrors(['person'])
        ->assertSessionHasInput('first_name', 'Changed')
        ->assertSessionMissing('success');

});

it('shows the alert on the page the person lands on after an exhausted Person update, with the value they typed', function () {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $contact = CrmContact::factory()->create(['organization_id' => $organization->id, 'first_name' => 'Original', 'created_by' => $this->operator->id]);

    $this->mock(CrmService::class)->shouldReceive('updateContact')->once()->andThrow(new ConflictHttpException);

    $this->followingRedirects()->actingAs($this->operator)->from(route('crm.contacts.edit', $contact))
        ->put(route('crm.contacts.update', $contact), ['first_name' => 'Changed', 'last_name' => 'Name', 'organization_id' => $organization->id])
        ->assertOk()
        ->assertSee('Nothing was changed; please review the details and try again.', false)
        ->assertSee('value="Changed"', false);

    expect($contact->fresh()->first_name)->toBe('Original');
});

it('answers an exhausted Person deletion with an alert on the edit page it came from, and the Person is still listed', function () {
    $contact = CrmContact::factory()->create(['organization_id' => null, 'created_by' => $this->operator->id]);

    $this->mock(CrmService::class)->shouldReceive('deleteContact')->once()->andThrow(new ConflictHttpException);

    $this->actingAs($this->operator)->from(route('crm.contacts.edit', $contact))
        ->delete(route('crm.contacts.destroy', $contact))
        ->assertRedirect(route('crm.contacts.edit', $contact))
        ->assertSessionHasErrors(['person'])
        ->assertSessionMissing('success');

    expect(CrmContact::find($contact->id))->not->toBeNull();
});

it('does not turn any other failure into the conflict answer: a missing organization is still a validation error on its own field', function () {
    $contact = CrmContact::factory()->create(['organization_id' => null, 'created_by' => $this->operator->id]);

    $this->actingAs($this->operator)->from(route('crm.contacts.edit', $contact))
        ->put(route('crm.contacts.update', $contact), ['first_name' => 'A', 'last_name' => 'B', 'organization_id' => 999999])
        ->assertSessionHasErrors(['organization_id'])
        ->assertSessionDoesntHaveErrors(['person']);
});
