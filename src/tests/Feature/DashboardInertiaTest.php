<?php

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('requires authentication for the dashboard', function () {
    $this->get(route('dashboard'))->assertRedirect('/login');
});

it('renders scoped user dashboard data without model leakage', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();

    Ticket::factory()->open()->for($user, 'user')->create(['title' => 'Visible ticket']);
    Ticket::factory()->open()->for($other, 'user')->create(['title' => 'Hidden ticket']);
    $project = Project::factory()->active()->create();
    $project->members()->attach($user, ['role' => 'member']);
    Project::factory()->active()->create();
    TimeEntry::factory()->create(['user_id' => $user->id, 'date' => now(), 'duration_minutes' => 90]);
    Invoice::factory()->sent()->create(['client_id' => $user->id]);
    Invoice::factory()->sent()->create(['client_id' => $other->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->has('metrics', 4)
            ->where('metrics.0.value', 1)
            ->where('metrics.1.value', 1)
            ->where('metrics.1.visit', 'inertia')
            ->where('metrics.2.value', '1.5h')
            ->where('metrics.2.visit', 'inertia')
            ->where('metrics.3.value', 1)
            ->where('quickActions.1.visit', 'inertia')
            ->where('quickActions.2.key', 'projects')
            ->where('quickActions.2.visit', 'inertia')
            ->has('recentTickets', 1)
            ->where('recentTickets.0.subject', 'Visible ticket')
            ->missing('recentTickets.0.description')
            ->where('crmSummary', null));
});

it('renders operator-wide dashboard data and crm summary', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    Ticket::factory()->open()->count(2)->create();

    $this->actingAs($operator)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/index')
            ->where('metrics.0.value', 2)
            ->has('crmSummary.companies')
            ->has('crmSummary.contacts')
            ->has('crmSummary.orgs'));
});
