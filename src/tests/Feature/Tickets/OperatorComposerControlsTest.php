<?php

/*
 * Release 1.0 (HD-C1): the operator Helpdesk composer. The "Internal note" checkbox became a Visible/Hidden toggle and the due-date
 * disclosure became one inline button. This file pins what the SERVER renders and keeps true:
 *
 *   - the markup contract the progressive script reads and the accessible semantics (a pressed button, a tooltip, no colour-only state);
 *   - that the posted fields are exactly the existing contract (`is_internal`, `due_action`, `due_at`), refilled after a refusal;
 *   - that nothing about authorization, validation or the reply transaction moved.
 *
 * The script's own behaviour is in resources/js/shell/blade-ticket-composer.test.ts; the real picker and focus in
 * tests/Browser/operator-composer-controls.spec.ts. The date-validation matrix and the requester boundary stay in
 * TicketDueDateOverrideTest.php.
 */

use App\Models\Ticket;
use App\Models\TicketDueDateChange;
use App\Notifications\TicketRepliedNotification;
use App\Services\TicketService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';
require_once dirname(__DIR__, 2).'/Support/UiHtmlHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->operator = ticketUser('operator');
    $this->owner = ticketUser();
    $this->ticket = ticketFor($this->owner, ['status' => 'open', 'priority' => 'medium', 'sla_due_at' => Carbon::parse('2026-11-05 10:00:00')]);
    $this->show = route('operator.tickets.show', $this->ticket);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** The operator page DOM for a ticket. */
function composerPage(Ticket $ticket, $user): DOMXPath
{
    return uiDom(test()->actingAs($user)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent());
}

// ── The visibility toggle ────────────────────────────────────────────────────────────────────────

it('replaces the internal-note checkbox with a pressed-state button that starts Visible', function () {
    $page = composerPage($this->ticket, $this->operator);
    $button = uiOne($page, '//*[@data-visibility-button]');
    $input = uiOne($page, '//input[@name="is_internal"]');

    expect($button->tagName)->toBe('button')
        ->and($button->getAttribute('type'))->toBe('button')
        ->and($button->getAttribute('aria-pressed'))->toBe('false')
        // The name is the visible word plus a screen-reader tail (WCAG 2.5.3), not a separate label that could disagree with it.
        ->and($button->hasAttribute('aria-label'))->toBeFalse()
        ->and($input->getAttribute('type'))->toBe('hidden')
        ->and($input->getAttribute('value'))->toBe('0')
        ->and($page->query('//input[@type="checkbox"][@name="is_internal"]')->length)->toBe(0);

    $this->actingAs($this->operator)->get($this->show)->assertDontSee('Internal note (not visible to submitter)');
});

it('shows both states in words and glyphs, never as colour alone, with a tooltip for each', function () {
    $page = composerPage($this->ticket, $this->operator);
    $button = uiOne($page, '//*[@data-visibility-button]');
    $tooltip = uiOne($page, '//*[@id="visibility-toggle-tip"]');

    expect($tooltip->getAttribute('role'))->toBe('tooltip')
        ->and($page->query('.//svg[@data-glyph="eye"]', $button)->length)->toBe(1)
        ->and($page->query('.//svg[@data-glyph="eye-off"]', $button)->length)->toBe(1)
        // Each state's visible word starts its accessible name; the tail is screen-reader-only.
        ->and(preg_replace('/\s+/', ' ', trim($button->textContent)))->toBe('Visible to submitter Hidden from submitter')
        ->and(trim(uiOne($page, '(//*[@data-visibility-button]/span)[1]/span[@class="sr-only"]')->textContent))->toBe('to submitter')
        ->and(trim(uiOne($page, '(//*[@data-visibility-button]/span)[2]/span[@class="sr-only"]')->textContent))->toBe('from submitter')
        ->and(preg_replace('/\s+/', ' ', trim($tooltip->textContent)))->toBe('Visible to submitter Not visible to submitter');
});

it('comes back pressed (Hidden) with is_internal = 1 after a refused internal note', function () {
    $this->actingAs($this->operator)->from($this->show)->post(route('operator.tickets.replies.store', $this->ticket), [
        'body' => 'Internal draft.', 'is_internal' => '1',
        'attachments' => [UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')],
    ])->assertRedirect($this->show)->assertSessionHasErrors();

    $page = composerPage($this->ticket, $this->operator);

    expect(uiOne($page, '//*[@data-visibility-button]')->getAttribute('aria-pressed'))->toBe('true')
        ->and(uiOne($page, '//input[@name="is_internal"]')->getAttribute('value'))->toBe('1');
});

it('posts exactly what the toggle holds: 0 is a public reply, 1 an internal note', function (string $value, bool $internal) {
    $this->actingAs($this->operator)
        ->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'Hello.', 'is_internal' => $value])
        ->assertRedirect()->assertSessionDoesntHaveErrors();

    expect($this->ticket->replies()->sole()->is_internal)->toBe($internal);

    if ($internal) {
        Notification::assertNothingSent();
    } else {
        Notification::assertSentToTimes($this->owner, TicketRepliedNotification::class, 1);
    }
})->with([
    'visible' => ['0', false],
    'hidden' => ['1', true],
]);

it('does not let an actor without internal visibility make a note by posting is_internal = 1', function () {
    $stranger = ticketUser();

    // A requester has no way into the operator page, and the shared reply route demotes the flag for any actor the policy refuses.
    $this->actingAs($this->owner)->get($this->show)->assertForbidden();
    $this->actingAs($this->owner)->post(route('tickets.replies.store', $this->ticket), ['body' => 'Sneaky.', 'is_internal' => '1'])->assertRedirect();

    expect($this->ticket->replies()->sole()->is_internal)->toBeFalse();

    $this->actingAs($stranger)->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'No.', 'is_internal' => '1'])->assertForbidden();
    expect($this->ticket->replies()->count())->toBe(1);
});

// ── The inline due-date control ──────────────────────────────────────────────────────────────────

it('renders the effective date as a button, with the zone, the instant and the bounds the script needs', function () {
    $page = composerPage($this->ticket, $this->operator);
    $control = uiOne($page, '//*[@data-due-control]');
    $trigger = uiOne($page, '//*[@data-due-trigger]');

    expect($trigger->tagName)->toBe('button')
        ->and($trigger->getAttribute('type'))->toBe('button')
        ->and($trigger->getAttribute('aria-expanded'))->toBe('false')
        ->and(trim(uiOne($page, '//*[@data-due-text]')->textContent))->toBe('05 Nov 2026 10:00')
        ->and(trim(uiOne($page, '//*[@data-due-zone]')->textContent))->toBe('UTC')
        ->and($control->getAttribute('data-timezone'))->toBe('UTC')
        ->and($control->getAttribute('data-original-wall'))->toBe('2026-11-05T10:00')
        ->and($control->getAttribute('data-original-epoch'))->toBe((string) (Carbon::parse('2026-11-05 10:00:00 UTC')->getTimestamp() * 1000))
        ->and($control->getAttribute('data-server-now'))->toBe((string) (Carbon::parse('2026-11-04 10:00:00 UTC')->getTimestamp() * 1000))
        ->and($control->getAttribute('data-has-override'))->toBe('0')
        ->and($control->getAttribute('data-max-days'))->toBe((string) TicketService::DUE_OVERRIDE_MAX_DAYS);

    $input = uiOne($page, '//input[@type="datetime-local"]');
    expect($input->getAttribute('aria-label'))->toBe('Due date and time (UTC)')
        ->and($input->getAttribute('min'))->toBe('2026-11-04T10:01')
        ->and($input->hasAttribute('name'))->toBeFalse()
        // Detached: never submitted, never validated by the browser, so a plain reply cannot be blocked by it.
        ->and($input->getAttribute('form'))->toBe('due-date-editor-detached')
        ->and($page->query('//*[@id="due-date-editor-detached"]')->length)->toBe(0);
});

it('has no keep/set dropdown and no accordion', function () {
    $this->actingAs($this->operator)->get($this->show)->assertOk()
        ->assertDontSee('Change due date')->assertDontSee('Keep the current due date')->assertDontSee('Set a new due date')
        ->assertDontSee('data-due-date-adjust', false);

    expect(composerPage($this->ticket, $this->operator)->query('//select[@name="due_action"] | //details')->length)->toBe(0);
});

it('carries no pending change by default: keep, and no date', function () {
    $page = composerPage($this->ticket, $this->operator);

    expect(uiOne($page, '//input[@name="due_action"]')->getAttribute('value'))->toBe('keep')
        ->and(uiOne($page, '//input[@name="due_at"]')->getAttribute('value'))->toBe('')
        ->and(uiOne($page, '//*[@data-due-trigger]')->hasAttribute('data-pending'))->toBeFalse();
});

it('says "No due date" for a ticket with no deadline, and keeps the control usable', function () {
    $legacy = ticketFor($this->owner, ['status' => 'open', 'sla_due_at' => null]);
    $page = composerPage($legacy, $this->operator);

    expect(trim(uiOne($page, '//*[@data-due-text]')->textContent))->toBe('No due date')
        ->and(uiOne($page, '//*[@data-due-control]')->getAttribute('data-original-epoch'))->toBe('')
        ->and(uiOne($page, '//*[@data-due-trigger]')->getAttribute('data-urgency'))->toBe('none');
});

it('shows the override as the effective date and offers the original SLA date to restore', function () {
    $this->ticket->forceFill(['due_override_at' => Carbon::parse('2026-11-09 14:30:00')])->saveQuietly();
    $page = composerPage($this->ticket, $this->operator);
    $control = uiOne($page, '//*[@data-due-control]');

    expect(trim(uiOne($page, '//*[@data-due-text]')->textContent))->toBe('09 Nov 2026 14:30')
        ->and($control->getAttribute('data-has-override'))->toBe('1')
        ->and($control->getAttribute('data-sla-wall'))->toBe('2026-11-05T10:00')
        ->and($control->getAttribute('data-sla-text'))->toBe('05 Nov 2026 10:00')
        ->and(uiOne($page, '//*[@data-due-restore]')->textContent)->toContain('Restore SLA');
});

it('reads the wall clock in the application zone while the instant stays true', function () {
    config(['app.timezone' => 'America/New_York']);
    $page = composerPage($this->ticket, $this->operator);
    $control = uiOne($page, '//*[@data-due-control]');

    // 10:00 UTC is 05:00 in New York in November (EST). The text the operator edits is the zone's wall clock; the epoch is unchanged.
    expect($control->getAttribute('data-timezone'))->toBe('America/New_York')
        ->and($control->getAttribute('data-original-wall'))->toBe('2026-11-05T05:00')
        ->and(trim(uiOne($page, '//*[@data-due-text]')->textContent))->toBe('05 Nov 2026 05:00')
        ->and($control->getAttribute('data-original-epoch'))->toBe((string) (Carbon::parse('2026-11-05 10:00:00 UTC')->getTimestamp() * 1000))
        ->and(trim(uiOne($page, '//*[@data-due-zone]')->textContent))->toBe('EST');
});

it('renders no due-date control on a resolved ticket, and still shows the visibility toggle', function () {
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->saveQuietly();
    $page = composerPage($this->ticket, $this->operator);

    expect($page->query('//*[@data-due-control]')->length)->toBe(0)
        ->and($page->query('//input[@name="due_action"] | //input[@name="due_at"]')->length)->toBe(0)
        ->and($page->query('//*[@data-visibility-button]')->length)->toBe(1);
});

it('puts both controls together in one labelled group under the message and renames the submit', function () {
    $page = composerPage($this->ticket, $this->operator);
    $group = uiOne($page, '//*[@role="group"][@aria-label="Update options"]');

    expect($page->query('.//*[@data-visibility-toggle]', $group)->length)->toBe(1)
        ->and($page->query('.//*[@data-due-control]', $group)->length)->toBe(1)
        ->and($page->query('//button[@type="submit"][normalize-space()="Post Update"]')->length)->toBe(1)
        ->and($page->query('//button[normalize-space()="Post Reply"]')->length)->toBe(0);
});

it('survives a crafted post whose refused flash holds an array, instead of failing the page', function () {
    $this->actingAs($this->operator)->from($this->show)
        ->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'x', 'due_action' => ['set'], 'due_at' => ['2026-11-10T14:30']])
        ->assertRedirect($this->show)->assertSessionHasErrors();

    $page = composerPage($this->ticket, $this->operator);

    expect(uiOne($page, '//input[@name="due_action"]')->getAttribute('value'))->toBe('keep')
        ->and(uiOne($page, '//input[@name="due_at"]')->getAttribute('value'))->toBe('');
});

it('points the control and its field at the client error line and at every server error line', function () {
    $this->actingAs($this->operator)->from($this->show)
        ->post(route('operator.tickets.replies.store', $this->ticket), ['body' => 'x', 'due_action' => 'set', 'due_at' => '2026-11-01T10:00'])
        ->assertRedirect($this->show);

    $page = composerPage($this->ticket, $this->operator);
    $described = explode(' ', uiOne($page, '//*[@data-due-trigger]')->getAttribute('aria-describedby'));

    expect($described)->toContain('due-date-tip', 'due-date-editor-message', 'due_at-error')
        ->and(explode(' ', uiOne($page, '//input[@type="datetime-local"]')->getAttribute('aria-describedby')))->toContain('due-date-editor-message')
        ->and($page->query('//*[@id="due-date-editor-message"][@role="alert"][@hidden]')->length)->toBe(1)
        ->and(uiOne($page, '//*[@data-due-trigger]')->getAttribute('aria-invalid'))->toBe('true');
});

it('keeps the attachment field in the form', function () {
    $page = composerPage($this->ticket, $this->operator);

    expect(uiOne($page, '//input[@type="file"][@name="attachments[]"]')->getAttribute('aria-label'))->toBe('Attachments');
});

// ── Submission: the contract and the transaction are the ones that already existed ───────────────

it('posts a date change, an attachment and a public reply together, and notifies the requester once', function () {
    $this->actingAs($this->operator)->post(route('operator.tickets.replies.store', $this->ticket), [
        'body' => 'More time, with the log attached.', 'is_internal' => '0', 'due_action' => 'set', 'due_at' => '2026-11-10T14:30',
        'attachments' => [ticketUpload('log-attached.pdf')],
    ])->assertRedirect()->assertSessionDoesntHaveErrors()->assertSessionHas('status', 'Reply added and due date updated.');

    $reply = $this->ticket->replies()->sole();

    expect($reply->is_internal)->toBeFalse()
        ->and($reply->attachments()->count())->toBe(1)
        ->and($this->ticket->fresh()->due_override_at->toDateTimeString())->toBe('2026-11-10 14:30:00')
        ->and(TicketDueDateChange::sole()->reply_id)->toBe($reply->id);
    Notification::assertSentToTimes($this->owner, TicketRepliedNotification::class, 1);
});

it('records nothing and notifies nobody when the date is refused: reply and change are one transaction', function () {
    $this->actingAs($this->operator)->from($this->show)->post(route('operator.tickets.replies.store', $this->ticket), [
        'body' => 'Should not stick.', 'is_internal' => '0', 'due_action' => 'set', 'due_at' => '2026-11-01T10:00',
    ])->assertRedirect($this->show)->assertSessionHasErrors('due_at');

    expect($this->ticket->replies()->count())->toBe(0)
        ->and(TicketDueDateChange::count())->toBe(0)
        ->and($this->ticket->fresh()->due_override_at)->toBeNull();
    Notification::assertNothingSent();
});

it('treats the inferred keep (keep with an empty date) as a plain reply', function () {
    $this->actingAs($this->operator)->post(route('operator.tickets.replies.store', $this->ticket), [
        'body' => 'Just a reply.', 'is_internal' => '0', 'due_action' => 'keep', 'due_at' => '',
    ])->assertRedirect()->assertSessionDoesntHaveErrors()->assertSessionHas('status', 'Reply added.');

    expect(TicketDueDateChange::count())->toBe(0)->and($this->ticket->fresh()->due_override_at)->toBeNull();
});
