<?php

use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;

/*
 * EPIC-011E WP7 (§21) moved the project task page to React, leaving the ticket show page as
 * the only surviving embed of the Blade `x-time-tracker` component and its time panel. This
 * regression is Ticket-specific, not Projects-specific — it was originally pinned alongside
 * project/task Blade-era characterization tests in ProjectBladeRegressionTest.php, which WP9
 * retired once its other two assertions were superseded by stronger Inertia-era coverage (see
 * EPIC-011E Amendment 10). It lives here because the surface it guards is a Ticket page, and
 * stays load-bearing for as long as Tickets remain Blade (until EPIC-011F).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('leaves the ticket time panel untouched by the project/task React migration', function () {
    $owner = User::factory()->create()->assignRole('user');
    $other = User::factory()->create(['name' => 'Ticket Coworker'])->assignRole('user');
    $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
    TimeEntry::factory()->create(['user_id' => $other->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 60, 'date' => today()]);

    $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
        ->assertSee('Ticket Coworker')->assertSee('1h');
});
