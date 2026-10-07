<?php

use App\Models\CmsPage;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TimeEntry;
use App\Models\User;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * EPIC-016 WP2 §18.3 "Domain mappings": the semantic-state presentation of the target Blade pages.
 *
 * The expected tone and glyph for every state is written out HERE, independently of the partials, from the
 * epic's §9 tables, so a wrong tone, a lost glyph or a lost label fails a test rather than silently
 * shipping. Domain behaviour (transitions, SLA rules, publish rules, invoice business rules) is owned by the
 * existing suites and is not re-tested; these tests only read what the pages show for existing server truth.
 *
 * Assertions are on parsed DOM (class tokens, attributes, text), never whole-markup snapshots.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function semActor(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/** A status mark's observable parts: [label text, tone class on the root, glyph name, glyph colour class]. */
function semMark(DOMElement $root): array
{
    $label = trim((string) $root->getElementsByTagName('span')->item(0)?->textContent);
    $svg = $root->getElementsByTagName('svg')->item(0);

    // A glyph that names a shape but draws nothing would leave colour as the only signal: the glyph must
    // really contain drawn geometry, not just carry a data-glyph name.
    if ($svg !== null) {
        $drawn = $svg->getElementsByTagName('circle')->length + $svg->getElementsByTagName('path')->length + $svg->getElementsByTagName('rect')->length;
        expect($drawn)->toBeGreaterThan(0, "the [{$label}] glyph draws nothing");
    }

    $toneClass = array_values(array_intersect(uiClasses($root), ['text-text-muted', 'text-accent', 'text-success', 'text-warning', 'text-danger', 'text-live-text']));

    return [$label, $toneClass[0] ?? null, $svg?->getAttribute('data-glyph'), $svg === null ? null : uiClasses($svg)];
}

/** Render a domain mapping partial on its own. */
function semPartial(string $view, array $data): DOMElement
{
    return uiOne(uiDom(uiRender("@include('{$view}', \$data)", [], ['data' => $data])), '//span[svg]');
}

const SEM_TONE_CLASS = [
    'neutral' => 'text-text-muted',
    'info' => 'text-accent',
    'success' => 'text-success',
    'warning' => 'text-warning',
    'danger' => 'text-danger',
    'live' => 'text-live-text',
];

// ── Mappings (EPIC-016 §9) ───────────────────────────────────────────────────

dataset('ticket lifecycle', [
    // status => [label, tone, glyph]   (§9.1; `dashed` is the recorded P6 substitute for the hourglass)
    'open' => ['open', 'Open', 'info', 'circle'],
    'in_progress' => ['in_progress', 'In Progress', 'info', 'half'],
    'pending_user' => ['pending_user', 'Pending', 'neutral', 'dashed'],
    'resolved' => ['resolved', 'Resolved', 'success', 'check'],
    'closed' => ['closed', 'Closed', 'neutral', 'check'],
]);

it('maps every ticket status to its tone, glyph and unchanged label', function (string $status, string $label, string $tone, string $glyph) {
    [$text, $toneClass, $shape] = semMark(semPartial('tickets._status_badge', ['status' => $status]));

    expect($text)->toBe($label)
        ->and($toneClass)->toBe(SEM_TONE_CLASS[$tone])
        ->and($shape)->toBe($glyph);
})->with('ticket lifecycle');

it('covers every status a ticket can hold, so a new status cannot slip in unmapped', function () {
    $mapped = array_keys(Ticket::TRANSITIONS);

    expect($mapped)->toEqualCanonicalizing(['open', 'in_progress', 'pending_user', 'resolved', 'closed']);
    foreach ($mapped as $status) {
        // Every real status carries a glyph AND a visible label, never colour alone.
        [$text, , $glyph] = semMark(semPartial('tickets._status_badge', ['status' => $status]));
        expect($text)->not->toBe('')->and($glyph)->not->toBeNull();
    }
});

it('keeps Open and In Progress apart by glyph and label, not by colour', function () {
    $open = semMark(semPartial('tickets._status_badge', ['status' => 'open']));
    $progress = semMark(semPartial('tickets._status_badge', ['status' => 'in_progress']));

    expect($open[1])->toBe($progress[1])           // same tone, by Direction D §10.4
        ->and($open[2])->not->toBe($progress[2])    // different glyph
        ->and($open[0])->not->toBe($progress[0]);   // different label
});

it('falls back to a neutral mark with a readable label for an unknown ticket status', function () {
    [$text, $toneClass, $glyph] = semMark(semPartial('tickets._status_badge', ['status' => 'escalated']));

    expect($text)->toBe('Escalated')->and($toneClass)->toBe('text-text-muted')->and($glyph)->toBe('circle');
});

dataset('ticket priority', [
    // priority => [label, filled bars, danger?]   (§9.2)
    'low' => ['low', 'Low', 1, false],
    'medium' => ['medium', 'Medium', 2, false],
    'high' => ['high', 'High', 3, false],
    'critical' => ['critical', 'Critical', 3, true],
]);

it('maps every ticket priority to its bars, tone and unchanged label', function (string $priority, string $label, int $bars, bool $danger) {
    $xpath = uiDom(uiRender("@include('tickets._priority_badge', ['priority' => \$priority])", [], ['priority' => $priority]));
    $root = uiOne($xpath, '//span[svg]');
    $filled = $xpath->query('//svg/rect[@data-bar="filled"]')->length;

    expect($filled)->toBe($bars)
        ->and(trim(uiOne($xpath, '//span[svg]/span')->textContent))->toBe($label)
        ->and(uiClasses($root))->toContain($danger ? 'text-danger' : 'text-text-secondary')
        ->and(uiClasses($root))->not->toContain($danger ? 'text-text-secondary' : 'text-danger');
})->with('ticket priority');

it('draws only critical in danger, and no priority as a pastel pill', function () {
    foreach (['low', 'medium', 'high', 'critical'] as $priority) {
        $html = uiRender("@include('tickets._priority_badge', ['priority' => \$priority])", [], ['priority' => $priority]);

        expect($html)->not->toMatch('/style=|rounded-full|#[0-9a-fA-F]{3,8}\b/')
            ->and(str_contains($html, 'text-danger'))->toBe($priority === 'critical');
    }
});

it('falls back to one neutral bar and a readable label for an unknown priority', function () {
    $xpath = uiDom(uiRender("@include('tickets._priority_badge', ['priority' => 'urgent'])"));

    expect($xpath->query('//svg/rect[@data-bar="filled"]')->length)->toBe(1)
        ->and(trim(uiOne($xpath, '//span[svg]/span')->textContent))->toBe('Urgent');
});

dataset('invoice lifecycle', [
    // status => [label, tone, glyph]   (§9.3; `half` and `square` are the recorded P11 substitutes)
    'draft' => ['draft', 'Draft', 'neutral', 'dashed'],
    'sent' => ['sent', 'Sent', 'info', 'half'],
    'paid' => ['paid', 'Paid', 'success', 'check'],
    'overdue' => ['overdue', 'Overdue', 'danger', 'square'],
    'cancelled' => ['cancelled', 'Cancelled', 'neutral', 'circle'],
]);

it('maps every invoice status to its tone, glyph and label through the one shared partial', function (string $status, string $label, string $tone, string $glyph) {
    [$text, $toneClass, $shape] = semMark(semPartial('billing._invoice_status', ['status' => $status]));

    expect($text)->toBe($label)
        ->and($toneClass)->toBe(SEM_TONE_CLASS[$tone])
        ->and($shape)->toBe($glyph);
})->with('invoice lifecycle');

it('covers exactly the statuses an invoice can hold', function () {
    expect(Invoice::STATUSES)->toEqualCanonicalizing(['draft', 'sent', 'paid', 'overdue', 'cancelled']);
});

it('keeps the five invoice glyphs distinct, so no two statuses differ by colour alone', function () {
    $glyphs = array_map(fn (string $s) => semMark(semPartial('billing._invoice_status', ['status' => $s]))[2], Invoice::STATUSES);

    expect(array_unique($glyphs))->toHaveCount(5);
});

it('has ONE invoice status mapping: all four views include the partial and none keeps its own colour map', function () {
    $views = ['billing/invoices/index', 'billing/invoices/show', 'billing/client/index', 'billing/client/show'];

    foreach ($views as $view) {
        $source = (string) file_get_contents(resource_path("views/{$view}.blade.php"));

        expect($source)->toContain("@include('billing._invoice_status'")
            ->and($source)->not->toMatch('/\$statusStyles|\$ss\b|--surface-(?:info|success|danger|muted)|--text-(?:info|success|danger)|--border-(?:info|success|danger|muted)|capitalize/');
    }

    // And nothing else in the views re-declares the lifecycle map.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/billing'))) as $file) {
        if ($file->isFile() && ! str_ends_with($file->getFilename(), '_invoice_status.blade.php')) {
            expect(file_get_contents($file->getPathname()))->not->toMatch("/'cancelled'\s*=>\s*\[/");
        }
    }
});

it('maps CMS page state to Published (success, check) and Draft (neutral, dashed)', function () {
    [$publishedText, $publishedTone, $publishedGlyph] = semMark(semPartial('operator.cms._state', ['published' => true]));
    [$draftText, $draftTone, $draftGlyph] = semMark(semPartial('operator.cms._state', ['published' => false]));

    expect([$publishedText, $publishedTone, $publishedGlyph])->toBe(['Published', 'text-success', 'check'])
        ->and([$draftText, $draftTone, $draftGlyph])->toBe(['Draft', 'text-text-muted', 'dashed']);
});

it('draws the internal-note marker as a lock glyph and the existing "Internal Note" text in warning', function () {
    $xpath = uiDom(uiRender("@include('tickets._internal_note_label')"));
    $root = uiOne($xpath, '//span[@data-internal-note]');

    expect(uiClasses($root))->toContain('text-warning')
        ->and(trim(uiOne($xpath, '//span[@data-internal-note]/span')->textContent))->toBe('Internal Note')
        ->and(uiOne($xpath, '//span[@data-internal-note]/svg')->getAttribute('aria-hidden'))->toBe('true')
        ->and($xpath->query('//span[@data-internal-note]/svg/rect'))->toHaveCount(1)
        ->and($root->ownerDocument->saveHTML($root))->not->toMatch('/style=|#[0-9a-fA-F]{3,8}\b/');
});

it('draws the SLA Overdue mark as a danger status with the square substitute and the existing label', function () {
    [$text, $toneClass, $glyph] = semMark(semPartial('tickets._overdue_status', ['class' => 'ml-1']));

    expect($text)->toBe('Overdue')->and($toneClass)->toBe('text-danger')->and($glyph)->toBe('square');
});

// ── Pages: the mappings reach the screen ─────────────────────────────────────

it('shows every ticket status and priority on the operator queue through the shared partials', function () {
    $operator = semActor('operator');
    foreach (['open', 'in_progress', 'pending_user', 'resolved', 'closed'] as $i => $status) {
        Ticket::factory()->for($operator, 'user')->create(['status' => $status, 'priority' => ['low', 'medium', 'high', 'critical', 'low'][$i]]);
    }

    $xpath = uiDom($this->actingAs($operator)->get(route('operator.tickets.index'))->assertOk()->getContent());
    $statuses = [];
    foreach ($xpath->query('//*[@data-ticket-status]') as $mark) {
        $statuses[] = $mark->getAttribute('data-ticket-status');
    }
    $priorities = [];
    foreach ($xpath->query('//*[@data-ticket-priority]') as $mark) {
        $priorities[] = $mark->getAttribute('data-ticket-priority');
    }

    expect($statuses)->toEqualCanonicalizing(['open', 'in_progress', 'pending_user', 'resolved', 'closed'])
        ->and($priorities)->toEqualCanonicalizing(['low', 'medium', 'high', 'critical', 'low']);
});

it('draws an overdue ticket as danger text plus a glyph and label, with no red row', function () {
    $operator = semActor('operator');
    Ticket::factory()->overdue()->for($operator, 'user')->create(['title' => 'Late one']);
    Ticket::factory()->open()->for($operator, 'user')->create(['title' => 'On time']);

    $html = $this->actingAs($operator)->get(route('operator.tickets.index'))->assertOk()->getContent();
    $xpath = uiDom($html);
    $late = uiOne($xpath, '//tr[.//p[normalize-space()="Late one"]]');
    $onTime = uiOne($xpath, '//tr[.//p[normalize-space()="On time"]]');
    $lateSla = uiOne($xpath, '//tr[.//p[normalize-space()="Late one"]]/td[.//*[@data-overdue-status]]');

    // The legacy light-only red row is gone, in both themes.
    expect($html)->not->toContain('bg-red-50')
        ->and(uiClasses($late))->not->toContain('bg-red-50')
        ->and(uiClasses($lateSla))->toContain('text-danger', 'font-medium')
        ->and($lateSla->getAttribute('style'))->toBe('')
        ->and(semMark(uiOne($xpath, '//tr[.//p[normalize-space()="Late one"]]//*[@data-overdue-status]')))->toMatchArray(['Overdue', 'text-danger', 'square'])
        ->and($onTime->getElementsByTagName('svg')->length)->toBeGreaterThan(0)
        ->and($xpath->query('//tr[.//p[normalize-space()="On time"]]//*[@data-overdue-status]'))->toHaveCount(0);
});

it('draws the operator ticket detail Overdue mark with the shared status and the due date in danger', function () {
    $operator = semActor('operator');
    $ticket = Ticket::factory()->overdue()->for($operator, 'user')->create();

    $html = $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent();
    $xpath = uiDom($html);

    expect($html)->not->toMatch('/#fef2f2|#dc2626/')
        ->and(semMark(uiOne($xpath, '//*[@data-overdue-status]')))->toMatchArray(['Overdue', 'text-danger', 'square'])
        ->and(uiClasses(uiOne($xpath, '//dt[normalize-space()="SLA Due"]/following-sibling::dd')))->toContain('text-danger');
});

it('draws the member ticket detail Overdue mark with the shared status', function () {
    $member = semActor('user');
    $ticket = Ticket::factory()->overdue()->for($member, 'user')->create();

    $xpath = uiDom($this->actingAs($member)->get(route('tickets.show', $ticket))->assertOk()->getContent());

    expect(semMark(uiOne($xpath, '//*[@data-overdue-status]')))->toMatchArray(['Overdue', 'text-danger', 'square']);
});

it('draws an internal note as a dashed warning card with a lock marker, and a public reply without them', function () {
    $operator = semActor('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();
    TicketReply::factory()->internal()->create(['ticket_id' => $ticket->id, 'user_id' => $operator->id, 'body' => 'Secret thought']);
    TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $operator->id, 'body' => 'Public words', 'is_internal' => false]);

    $html = $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk()->getContent();
    $xpath = uiDom($html);
    $note = uiOne($xpath, '//div[contains(@class,"rounded-xl")][.//*[contains(normalize-space(),"Secret thought")]][not(.//*[contains(normalize-space(),"Public words")])]');
    $public = uiOne($xpath, '//div[contains(@class,"rounded-xl")][.//*[contains(normalize-space(),"Public words")]][not(.//*[contains(normalize-space(),"Secret thought")])]');

    expect(uiClasses($note))->toContain('border-dashed', 'border-warning-glyph', 'bg-warning-soft')
        ->and($note->getAttribute('style'))->toBe('')
        ->and($note->getElementsByTagName('svg')->length)->toBe(1)
        ->and(trim(uiOne($xpath, '//span[@data-internal-note]/span')->textContent))->toBe('Internal Note')
        ->and(uiClasses($public))->not->toContain('border-dashed', 'bg-warning-soft')
        ->and($xpath->query('//span[@data-internal-note]'))->toHaveCount(1)
        // The old hard-coded light-theme hex styling is gone.
        ->and($html)->not->toMatch('/#fef3c7|#92400e|#d97706|#fffbeb/i');
});

it('draws the internal-note card with the same class string in both ticket views and in the browser gallery', function () {
    // tests/Support/semantic_state_gallery.php (the browser spec's gallery) copies this one class string, so a
    // change to the cards must reach the gallery too, or the browser evidence would measure a stale card.
    $card = 'border-dashed border-warning-glyph bg-warning-soft';

    foreach (['tickets/show', 'operator/tickets/show'] as $view) {
        expect(file_get_contents(resource_path("views/{$view}.blade.php")))->toContain($card, "@include('tickets._internal_note_label')");
    }

    expect(file_get_contents(base_path('tests/Support/semantic_state_gallery.php')))->toContain($card);
});

it('draws a list of invoices with every status through the shared partial, striking only a cancelled amount', function () {
    $operator = semActor('operator');
    foreach (Invoice::STATUSES as $status) {
        Invoice::factory()->{$status}()->create(['total' => 123.45]);
    }

    $xpath = uiDom($this->actingAs($operator)->get(route('billing.invoices.index'))->assertOk()->getContent());
    $seen = [];
    foreach ($xpath->query('//tbody/tr') as $row) {
        $mark = uiOne(uiDom($row->ownerDocument->saveHTML($row)), '//*[@data-invoice-status]');
        $status = $mark->getAttribute('data-invoice-status');
        $amount = uiOne(uiDom($row->ownerDocument->saveHTML($row)), '//td[contains(@class,"text-right")]');
        $seen[$status] = [semMark($mark)[0], in_array('line-through', uiClasses($amount), true)];
    }

    expect(array_keys($seen))->toEqualCanonicalizing(Invoice::STATUSES)
        ->and(array_map(fn ($v) => $v[0], $seen))->toBe(array_combine(array_keys($seen), array_map('ucfirst', array_keys($seen))))
        ->and(array_filter(array_map(fn ($v) => $v[1], $seen)))->toBe(['cancelled' => true]);
});

it('draws an overdue invoice due date in danger on all four invoice views, and an on-time one without', function () {
    $operator = semActor('operator');
    $member = semActor('user');
    // `issued_at` is pinned far from both due dates: the factory's random issue date could otherwise print the
    // same text as a due date and make the cell lookup ambiguous.
    $late = Invoice::factory()->sent()->create(['client_id' => $member->id, 'issued_at' => now()->subDays(90)->toDateString(), 'due_at' => now()->subDays(5)->toDateString()]);
    $onTime = Invoice::factory()->sent()->create(['client_id' => $member->id, 'issued_at' => now()->subDays(91)->toDateString(), 'due_at' => now()->addDays(5)->toDateString()]);
    $lateDue = $late->due_at->format('M j, Y');

    $operatorIndex = uiDom($this->actingAs($operator)->get(route('billing.invoices.index'))->assertOk()->getContent());
    $operatorShow = uiDom($this->actingAs($operator)->get(route('billing.invoices.show', $late))->assertOk()->getContent());
    $clientIndex = uiDom($this->actingAs($member)->get(route('billing.client.invoices.index'))->assertOk()->getContent());
    $clientShow = uiDom($this->actingAs($member)->get(route('billing.client.invoices.show', $late))->assertOk()->getContent());

    foreach ([$operatorIndex, $clientIndex] as $index) {
        $cell = uiOne($index, '//td[normalize-space()="'.$lateDue.'"]');
        expect(uiClasses($cell))->toContain('text-danger', 'font-medium')
            ->and($cell->getAttribute('style'))->toBe('');

        $fine = uiOne($index, '//td[normalize-space()="'.$onTime->due_at->format('M j, Y').'"]');
        expect(uiClasses($fine))->not->toContain('text-danger');
    }
    foreach ([$operatorShow, $clientShow] as $detail) {
        expect(uiClasses(uiOne($detail, '//p[normalize-space()="'.$lateDue.'"]')))->toContain('text-danger', 'font-medium');
    }
});

it('draws the invoice detail status through the shared partial for every status, and strikes a cancelled total', function () {
    $operator = semActor('operator');
    $member = semActor('user');

    foreach (Invoice::STATUSES as $status) {
        $invoice = Invoice::factory()->{$status}()->create(['client_id' => $member->id]);
        $invoice->items()->create(['description' => 'Design', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10, 'position' => 0]);

        foreach ([[$operator, route('billing.invoices.show', $invoice)], [$member, route('billing.client.invoices.show', $invoice)]] as [$actor, $url]) {
            $xpath = uiDom($this->actingAs($actor)->get($url)->assertOk()->getContent());
            $total = uiOne($xpath, '//td[contains(@class,"text-base")]');

            expect(uiOne($xpath, '//*[@data-invoice-status]')->getAttribute('data-invoice-status'))->toBe($status)
                ->and(in_array('line-through', uiClasses($total), true))->toBe($status === 'cancelled');
        }
    }
});

it('draws ✓ Paid and the ledger amount in success on the operator invoice page', function () {
    $operator = semActor('operator');
    $invoice = Invoice::factory()->paid()->create();
    $invoice->items()->create(['description' => 'Design', 'quantity' => 1, 'unit_price' => 10, 'amount' => 10, 'position' => 0]);
    $invoice->payments()->create(['amount' => 10, 'method' => 'manual', 'paid_at' => now()]);

    $xpath = uiDom($this->actingAs($operator)->get(route('billing.invoices.show', $invoice))->assertOk()->getContent());

    expect(uiClasses(uiOne($xpath, '//p[contains(normalize-space(),"Paid ")][contains(.,"✓")]')))->toContain('text-success')
        ->and(uiClasses(uiOne($xpath, '//span[starts-with(normalize-space(),"+")]')))->toContain('text-success');
});

it('draws Built-in and Custom role types as tags, never as accent-coloured status', function () {
    Role::create(['name' => 'sem-custom', 'guard_name' => 'web']);
    $xpath = uiDom($this->actingAs(semActor('operator'))->get(route('roles.index'))->assertOk()->getContent());

    $tags = [];
    foreach ($xpath->query('//*[@id="main-content"]//span[contains(@class,"rounded-tag")]') as $tag) {
        $tags[trim($tag->textContent)][] = uiClasses($tag);
    }

    expect($tags)->toHaveKeys(['Built-in', 'Custom'])
        ->and($tags['Built-in'][0])->toContain('font-mono', 'uppercase', 'border-rule-control', 'text-text-muted')
        ->and($tags['Custom'][0])->toContain('font-mono', 'uppercase', 'border-rule-control', 'text-text-muted')
        ->and($tags['Built-in'][0])->not->toContain('text-accent', 'bg-accent-soft', 'rounded-full');
});

it('draws the Built-in tag in the role edit heading', function () {
    $xpath = uiDom($this->actingAs(semActor('operator'))->get(route('roles.edit', Role::findByName('user')))->assertOk()->getContent());

    expect(trim(uiOne($xpath, '//*[@id="main-content"]//h1/following-sibling::span[contains(@class,"rounded-tag")]')->textContent))->toBe('Built-in');
});

it('draws an organization member role as a tag, so admin is a kind and not a warning', function () {
    $operator = semActor('operator');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach(semActor('user')->id, ['role' => 'admin']);
    $organization->members()->attach(semActor('user')->id, ['role' => 'member']);

    $html = $this->actingAs($operator)->get(route('organizations.show', $organization))->assertOk()->getContent();
    $xpath = uiDom($html);
    $labels = [];
    foreach ($xpath->query('//*[@id="main-content"]//span[contains(@class,"rounded-tag")]') as $tag) {
        $labels[] = trim($tag->textContent);
    }

    expect($labels)->toEqualCanonicalizing(['Admin', 'Member'])
        ->and($html)->not->toMatch('/--surface-warning|--text-warning/');
});

it('draws MFA Enabled as a success status with a glyph', function () {
    $subject = semActor('user');
    $subject->forceFill(['two_factor_confirmed_at' => now()])->save();

    $xpath = uiDom($this->actingAs(semActor('operator'))->get(route('users.show', $subject))->assertOk()->getContent());

    expect(semMark(uiOne($xpath, '//dd//span[svg][normalize-space()="Enabled"]')))->toMatchArray(['Enabled', 'text-success', 'check']);
});

it('draws MFA Disabled as a neutral dashed status, the counterpart of Enabled (owner ruling A2.12 #7)', function () {
    $subject = semActor('user');
    $subject->forceFill(['two_factor_confirmed_at' => null])->save();

    $xpath = uiDom($this->actingAs(semActor('operator'))->get(route('users.show', $subject))->assertOk()->getContent());
    $mark = uiOne($xpath, '//dt[normalize-space()="2FA"]/following-sibling::dd//span[svg]');

    expect(semMark($mark))->toMatchArray(['Disabled', 'text-text-muted', 'dashed'])
        ->and($mark->getAttribute('style'))->toBe('')
        ->and($xpath->query('//dt[normalize-space()="2FA"]/following-sibling::dd//*[normalize-space()="Enabled"]'))->toHaveCount(0);
});

it('draws CMS Published and Draft on the index and the edit page', function () {
    $operator = semActor('operator');
    $published = CmsPage::factory()->create(['title' => 'Live page', 'status' => 'published', 'published_at' => now()->subDay()]);
    $draft = CmsPage::factory()->create(['title' => 'Draft page', 'status' => 'draft', 'published_at' => null]);

    $index = uiDom($this->actingAs($operator)->get(route('operator.cms.index'))->assertOk()->getContent());
    expect(semMark(uiOne($index, '//tr[contains(.,"Live page")]//*[@data-page-state]')))->toMatchArray(['Published', 'text-success', 'check'])
        ->and(semMark(uiOne($index, '//tr[contains(.,"Draft page")]//*[@data-page-state]')))->toMatchArray(['Draft', 'text-text-muted', 'dashed']);

    $edit = uiDom($this->actingAs($operator)->get(route('operator.cms.edit', $published))->assertOk()->getContent());
    expect(semMark(uiOne($edit, '//*[@data-page-state]')))->toMatchArray(['Published', 'text-success', 'check']);
    $edit = uiDom($this->actingAs($operator)->get(route('operator.cms.edit', $draft))->assertOk()->getContent());
    expect(semMark(uiOne($edit, '//*[@data-page-state]')))->toMatchArray(['Draft', 'text-text-muted', 'dashed']);
});

it('draws report figures as plain mono text, not accent', function () {
    $operator = semActor('operator');
    Ticket::factory()->open()->for($operator, 'user')->create(['category' => 'Billing', 'priority' => 'high']);

    $html = $this->actingAs($operator)->get(route('operator.tickets.reports'))->assertOk()->getContent();
    $xpath = uiDom($html);
    $figures = $xpath->query('//li/span[contains(@class,"tabular-nums")]');

    expect($figures->length)->toBeGreaterThan(0);
    foreach ($figures as $figure) {
        expect(uiClasses($figure))->toContain('font-mono', 'tabular-nums', 'text-text')
            ->and($figure->getAttribute('style'))->toBe('');
    }
    // No report figure is a link, and none is accent-coloured.
    expect($xpath->query('//li/span[contains(@class,"tabular-nums")]/a'))->toHaveCount(0)
        ->and($html)->not->toContain('var(--accent)');
});

// ── Flash and banners ────────────────────────────────────────────────────────

it('draws a success flash as a polite status alert and keeps its text exactly', function () {
    $html = $this->actingAs(semActor('operator'))->withSession(['status' => 'Role saved exactly.'])->get(route('roles.index'))->assertOk()->getContent();
    $alert = uiOne(uiDom($html), '//div[@data-variant="success"]');

    expect($alert->getAttribute('role'))->toBe('status')
        ->and(trim(uiOne(uiDom($html), '//div[@data-variant="success"]/div[@data-alert-body]')->textContent))->toBe('Role saved exactly.')
        ->and($html)->not->toMatch('/--surface-success|--border-success|--text-success/');
});

it('draws a validation banner as an assertive alert and keeps its text exactly', function () {
    // A real failed submission: the role form posts an empty name and redirects back to the list.
    $response = $this->actingAs(semActor('operator'))->followingRedirects()->from(route('roles.index'))->post(route('roles.store'), ['name' => '']);
    $html = $response->assertOk()->getContent();
    $xpath = uiDom($html);
    $alert = uiOne($xpath, '//div[@data-variant="danger"]');

    expect($alert->getAttribute('role'))->toBe('alert')
        ->and(trim(uiOne($xpath, '//div[@data-variant="danger"]/div[@data-alert-body]')->textContent))->toBe('The name field is required.');
});

it('never announces an ordinary success message assertively on any page that flashes one', function () {
    $operator = semActor('operator');
    $pages = [
        route('roles.index') => 'status',
        route('users.index') => 'status',
        route('operator.cms.index') => 'success',
        route('crm.contacts.index') => 'success',
        route('crm.companies.index') => 'success',
        route('organizations.index') => 'success',
        route('billing.invoices.index') => 'success',
        route('operator.tickets.index') => 'status',
    ];

    foreach ($pages as $url => $key) {
        $xpath = uiDom($this->actingAs($operator)->withSession([$key => 'Done.'])->get($url)->assertOk()->getContent());

        expect(uiOne($xpath, '//div[@data-variant="success"]')->getAttribute('role'))->toBe('status', $url)
            ->and($xpath->query('//div[@data-variant="success"][@role="alert"]'))->toHaveCount(0);
    }
});

it('renders the Stripe error region as a hidden danger alert whose script id and body hook survive', function () {
    $source = (string) file_get_contents(resource_path('views/billing/payment/show.blade.php'));

    expect($source)->toContain('<x-ui.alert id="payment-message" variant="danger" class="hidden">')
        ->and($source)->toContain("msg.querySelector('[data-alert-body]').textContent = error.message;")
        ->and($source)->not->toMatch('/--surface-danger|--text-danger/');

    $html = uiRender('<x-ui.alert id="payment-message" variant="danger" class="hidden"></x-ui.alert>');
    $alert = uiOne(uiDom($html), '//div[@id="payment-message"]');

    expect($alert->getAttribute('role'))->toBe('alert')
        ->and(uiClasses($alert))->toContain('hidden')
        ->and(uiDom($html)->query('//div[@id="payment-message"]//div[@data-alert-body]'))->toHaveCount(1);
});

// ── The embedded ticket time tracker (EPIC-016 §9.9; WP1 review ruling A1.15 #2) ──

it('draws the stopped tracker with a secondary sm Start Timer button inside a data-hooked control row', function () {
    $owner = semActor('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();
    $html = $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()->getContent();
    $xpath = uiDom($html);

    $controls = uiOne($xpath, '//*[@data-time-tracker-controls]');
    $start = uiOne($xpath, '//*[@data-time-tracker-controls]/button[@data-time-tracker-start]');

    expect(uiClasses($start))->toContain('bg-surface', 'border-control-edge', 'text-text', 'h-8', 'text-xs')
        ->and(uiClasses($start))->not->toContain('bg-ink', 'text-danger', 'border-danger')
        ->and(preg_replace('/\s+/', ' ', trim($start->textContent)))->toBe('▶ Start Timer')
        ->and($controls->getElementsByTagName('svg')->length)->toBe(0)
        ->and($xpath->query('//*[@data-time-tracker-controls]//*[@data-time-tracker-running]'))->toHaveCount(0);
});

it('draws the running tracker with a live dot and label, and Stop as a non-destructive secondary sm button', function () {
    $owner = semActor('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();
    $entry = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id]);

    $xpath = uiDom($this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()->getContent());
    $running = uiOne($xpath, '//*[@data-time-tracker-controls]//*[@data-time-tracker-running]');
    $stop = uiOne($xpath, '//*[@data-time-tracker-controls]/button[@data-time-tracker-stop]');

    // Running is `live`, never `success`.
    expect(semMark($running))->toMatchArray(['Timer running', 'text-live-text', 'dot'])
        ->and(semMark($running)[3])->toContain('text-live')
        ->and(uiClasses($running))->not->toContain('text-success')
        ->and(uiClasses($stop))->toContain('bg-surface', 'border-control-edge', 'text-text', 'h-8', 'text-xs')
        ->and(uiClasses($stop))->not->toContain('text-danger', 'border-danger', 'bg-destructive', 'bg-ink')
        ->and(trim($stop->textContent))->toBe('Stop')
        ->and($stop->getAttribute('data-entry-id'))->toBe((string) $entry->id)
        ->and($xpath->query('//*[@data-time-tracker-controls]/button[@data-time-tracker-start]'))->toHaveCount(0);
});

it('ships the running state once as a server-rendered template, and the script builds no markup or colour', function () {
    $owner = semActor('user');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();
    $html = $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()->getContent();

    // The <template> holds the same component markup the running state uses (live status + secondary Stop).
    preg_match('/<template data-time-tracker-running-template>(.*?)<\/template>/s', $html, $match);
    $fragment = uiDom($match[1] ?? '');

    expect($match)->not->toBeEmpty()
        ->and(semMark(uiOne($fragment, '//*[@data-time-tracker-running]')))->toMatchArray(['Timer running', 'text-live-text', 'dot'])
        ->and(trim(uiOne($fragment, '//button[@data-time-tracker-stop]')->textContent))->toBe('Stop')
        ->and(uiOne($fragment, '//button[@data-time-tracker-stop]')->hasAttribute('data-entry-id'))->toBeFalse();

    $source = (string) file_get_contents(resource_path('views/components/time-tracker.blade.php'));
    preg_match('/<script>(.*?)<\/script>/s', $source, $script);
    $js = $script[1] ?? '';

    expect($js)->not->toBeEmpty()
        // No markup, no colour and no utility-class selector in the script.
        ->and($js)->not->toMatch('/innerHTML|style=|var\(--|#[0-9a-fA-F]{3,8}\b|rounded|text-xs|animate-pulse/')
        ->and($js)->not->toContain('.flex.items-center.gap-2')
        ->and($js)->not->toContain('.time-tracker-start-btn')
        ->and($js)->not->toContain('.time-tracker-stop-btn')
        ->and($js)->toContain('[data-time-tracker-controls]', '[data-time-tracker-running-template]', '[data-time-tracker-start]', '[data-time-tracker-stop]');
});

it('keeps every timer endpoint and the reconciliation event contract in the tracker script', function () {
    $js = (string) file_get_contents(resource_path('views/components/time-tracker.blade.php'));

    expect($js)->toContain("'/time/timer/start'", "'/time/timer/' + entryId + '/stop'", "'timerStarted'", "'timerStopped'", 'location.reload()', 'Retry stop');
});

// ── No legacy semantic-state styling is left at the WP2 sites ────────────────

it('leaves no hex, legacy status variable, palette utility or pastel pill at the migrated semantic-state sites', function () {
    $views = [
        'tickets/_status_badge', 'tickets/_priority_badge', 'tickets/_overdue_status', 'tickets/_internal_note_label',
        'billing/_invoice_status', 'operator/cms/_state', 'components/time-tracker', 'components/time-tracker/running',
        'billing/payment/show', 'admin/roles/index', 'organizations/show', 'operator/tickets/reports',
    ];

    foreach ($views as $view) {
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path("views/{$view}.blade.php")));

        expect($code)->not->toMatch('/--surface-(?:success|danger|warning|info|accent)\b|--border-(?:success|danger|warning|info)\b|--text-(?:success|danger|warning|info)\b|--accent\b|bg-red-|#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b(?![\w-])/', $view);
    }
});
