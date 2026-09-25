<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H7 (Ticket CSV export safety) on GET /operator/tickets/reports/export
 * (Operator\TicketReportController::export), plus the report/export date window (F-6, deferred).
 * WP0 characterized the export; WP2 converted every defect test into the TARGET contract
 * (pre-fix evidence: EPIC-010D Amendment 1).
 *
 * Serialization: fputcsv(..., escape: '') (RFC 4180) with free-text cells neutralized by the same
 * App\Support\CsvText helper the Time export uses; rows ordered created_at DESC, id DESC.
 *
 * Prefixes: BASELINE (already correct, keep), TARGET (the WP2 contract), CHARACTERIZATION
 * (deferred behavior recorded as fact).
 */

const TICKET_CSV_HEADER = ['Ticket #', 'Title', 'Category', 'Priority', 'Status', 'Submitter', 'Assignee', 'Created', 'Resolved', 'SLA Due'];

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $this->operator = ticketUser('operator');
});

afterEach(function () {
    Carbon::setTestNow();
});

function ticketExportCsv(User $operator, array $query = []): array
{
    $response = test()->actingAs($operator)->get(route('operator.tickets.export', $query));
    $response->assertOk();

    return [$response, $response->streamedContent()];
}

/** Data rows keyed by ticket number. */
function ticketExportRows(string $csv): array
{
    $rows = ticketParseCsv($csv);
    array_shift($rows);

    return collect($rows)->keyBy(0)->all();
}

it('BASELINE H7: header row, content type and filename; no BOM', function () {
    Carbon::setTestNow('2026-09-24 12:00:00');

    [$response, $csv] = ticketExportCsv($this->operator);

    expect(ticketParseCsv($csv)[0])->toBe(TICKET_CSV_HEADER)
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeFalse()
        ->and(strtok($csv, "\n"))->toBe('"Ticket #",Title,Category,Priority,Status,Submitter,Assignee,Created,Resolved,"SLA Due"')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="tickets-2026-09-24.csv"');
});

it('TARGET H7: formula-leading titles are neutralized with a leading apostrophe', function (string $title) {
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(ticketExportRows($csv)[$ticket->ticket_number][1])->toBe("'".$title);
})->with([
    'equals' => '=HYPERLINK("https://evil.example","Click")',
    'plus' => '+SUM(1,1)',
    'minus' => '-2+3',
    'at' => '@SUM(A1:A2)',
    'leading spaces' => '  =1+1',
    'leading tab' => "\t=1+1",
]);

it('TARGET H7: text that merely contains or ends with a formula character is left alone', function (string $title) {
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(ticketExportRows($csv)[$ticket->ticket_number][1])->toBe($title);
})->with(['Total = 5', 'a+b', 'user@example.com', 'Costs -5', '100%']);
it('TARGET H7: a formula title planted through the ordinary create form reaches the operator export neutralized', function () {
    $customer = ticketUser();

    $this->actingAs($customer)->post(route('tickets.store'), [
        'title' => '=HYPERLINK("https://evil.example","Open")',
        'description' => 'x',
        'category' => 'General',
        'priority' => 'low',
    ])->assertRedirect();

    [, $csv] = ticketExportCsv($this->operator);

    expect($csv)->toContain('"\'=HYPERLINK(""https://evil.example"",""Open"")"')
        ->and($csv)->not->toContain(',"=HYPERLINK(');
});
it('TARGET H7: user-controlled submitter, assignee and category cells are neutralized', function () {
    $submitter = ticketUser(attributes: ['name' => "=cmd|'/c calc'!A1"]);
    $assignee = ticketUser('operator', ['name' => '@evil']);
    $ticket = ticketFor($submitter, ['assignee_id' => $assignee->id, 'category' => '+Injected']);

    [, $csv] = ticketExportCsv($this->operator);
    $row = ticketExportRows($csv)[$ticket->ticket_number];

    expect($row[2])->toBe("'+Injected")
        ->and($row[5])->toBe("'=cmd|'/c calc'!A1")
        ->and($row[6])->toBe("'@evil");
});
it('BASELINE H7: commas, double quotes, embedded newlines and UTF-8 round-trip through an RFC-4180 reader', function (string $title) {
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(ticketExportRows($csv)[$ticket->ticket_number][1])->toBe($title);
})->with([
    'comma' => 'Printer, second floor',
    'quotes' => 'He said "urgent"',
    'newline' => "Line one\nLine two",
    'utf8' => 'Ünïcödé — 日本語 ✓',
]);

it('TARGET H7: a backslash before a quote round-trips through an RFC-4180 reader', function () {
    $title = 'Path C:\\share\\"quoted" end';
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(ticketExportRows($csv)[$ticket->ticket_number][1])->toBe($title);
});

it('TARGET H7: the header row and system columns are written without neutralization or reshaping', function () {
    $ticket = ticketFor(ticketUser(), ['status' => 'open', 'priority' => 'high']);

    [, $csv] = ticketExportCsv($this->operator);
    $row = ticketExportRows($csv)[$ticket->ticket_number];

    expect(ticketParseCsv($csv)[0])->toBe(TICKET_CSV_HEADER)
        ->and([$row[3], $row[4]])->toBe(['high', 'open'])
        ->and($row[7])->toBe($ticket->created_at->toDateTimeString());
});
it('TARGET H7: rows tied on created_at are ordered by id DESC, and newer rows come first', function () {
    $tied = now()->subDay()->startOfSecond();
    $a = ticketFor(ticketUser(), ['created_at' => $tied]);
    $b = ticketFor(ticketUser(), ['created_at' => $tied]);
    $c = ticketFor(ticketUser(), ['created_at' => $tied]);
    $newer = ticketFor(ticketUser(), ['created_at' => now()->subHour()]);
    $sameAgain = ticketFor(ticketUser(), ['created_at' => $tied]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(array_keys(ticketExportRows($csv)))->toBe([
        $newer->ticket_number, $sameAgain->ticket_number, $c->ticket_number, $b->ticket_number, $a->ticket_number,
    ]);
});
it('CHARACTERIZATION F-6 (deferred): supplied dates are parsed with the current time of day, so both window edges sit at "now" on the given dates; report and export share the window', function () {
    Carbon::setTestNow('2026-09-24 12:00:00');
    $at = fn (string $ts, string $title) => ticketFor(ticketUser(), ['created_at' => Carbon::parse($ts), 'title' => $title, 'category' => 'General']);

    $at('2026-09-01 11:00:00', 'from-day before noon');
    $inFrom = $at('2026-09-01 13:00:00', 'from-day after noon');
    $inTo = $at('2026-09-10 11:00:00', 'to-day before noon');
    $at('2026-09-10 13:00:00', 'to-day after noon');

    $window = ['date_from' => '2026-09-01', 'date_to' => '2026-09-10'];
    [, $csv] = ticketExportCsv($this->operator, $window);

    expect(array_keys(ticketExportRows($csv)))->toEqualCanonicalizing([$inFrom->ticket_number, $inTo->ticket_number]);

    $report = $this->actingAs($this->operator)->get(route('operator.tickets.reports', $window))->assertOk();
    expect($report->viewData('byCategory')->sum())->toBe(2);
});

it('BASELINE H7: customers cannot reach the report or the export', function () {
    $customer = ticketUser();

    $this->actingAs($customer)->get(route('operator.tickets.reports'))->assertForbidden();
    $this->actingAs($customer)->get(route('operator.tickets.export'))->assertForbidden();
});
