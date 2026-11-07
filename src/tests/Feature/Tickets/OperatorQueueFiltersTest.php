<?php

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = ticketUser('operator');
});

it('HD-P1: the queue offers a category filter and applies it', function () {
    $billing = ticketFor(ticketUser(), ['category' => 'Billing', 'title' => 'Billing question']);
    $technical = ticketFor(ticketUser(), ['category' => 'Technical', 'title' => 'Technical fault']);

    $response = $this->actingAs($this->operator)->get(route('operator.tickets.index', ['category' => 'Billing']));

    $response->assertOk()
        ->assertSee('name="category"', false)
        ->assertSee('All categories')
        ->assertSee('Billing question')
        ->assertDontSee('Technical fault');
});

it('HD-P1: filter placeholders read correctly and the waiting status uses the operator wording', function () {
    $response = $this->actingAs($this->operator)->get(route('operator.tickets.index'));

    $response->assertOk()
        ->assertSee('All statuses')->assertSee('All priorities')->assertSee('All assignees')
        ->assertDontSee('Statuss')
        ->assertSee('Waiting on customer')
        ->assertDontSee('Pending user');
    expect($response->getContent())->toContain('value="pending_user"');
});

it('HD-P1: a waiting ticket shows the operator label while the stored value is unchanged', function () {
    $ticket = ticketFor(ticketUser(), ['status' => 'pending_user']);

    $this->actingAs($this->operator)->get(route('operator.tickets.index'))
        ->assertSee('data-ticket-status="pending_user"', false)
        ->assertSee('Waiting on customer');

    expect($ticket->fresh()->status)->toBe('pending_user');
});
