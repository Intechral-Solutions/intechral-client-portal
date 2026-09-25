<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D WP0 characterization: H7 (Ticket CSV export safety) on
 * GET /operator/tickets/reports/export (Operator\TicketReportController::export), plus the
 * report/export date window (F-6, deferred).
 *
 * Serialization today: fputcsv() into php://output inside response()->stream(), with PHP's
 * default escape character "\" and no formula neutralization. The reference for WP2 is the Time
 * export (TimeEntryService::exportCsv + safeCsvText, escape: ''), pinned by TimeInertiaTest
 * "exports filtered RFC-compatible CSV and neutralizes spreadsheet formulas".
 *
 * Prefixes: BASELINE (keep), DEFECT H7 (CURRENT BROKEN behavior, WP2 flips it),
 * CHARACTERIZATION (current fact recorded for a later decision).
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

it('DEFECT H7 (WP2 flips to a leading apostrophe): formula-leading titles are exported verbatim', function (string $title) {
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);

    expect(ticketExportRows($csv)[$ticket->ticket_number][1])->toBe($title);
})->with([
    'equals' => '=HYPERLINK("https://evil.example","Click")',
    'plus' => '+SUM(1,1)',
    'minus' => '-2+3',
    'at' => '@SUM(A1:A2)',
    'leading spaces' => '  =1+1',
    'leading tab' => "\t=1+1",
]);

it('DEFECT H7 (WP2 flips): a customer can plant a formula title through the ordinary create form, and it reaches the operator export', function () {
    $customer = ticketUser();

    $this->actingAs($customer)->post(route('tickets.store'), [
        'title' => '=HYPERLINK("https://evil.example","Open")',
        'description' => 'x',
        'category' => 'General',
        'priority' => 'low',
    ])->assertRedirect();

    [, $csv] = ticketExportCsv($this->operator);

    expect($csv)->toContain('"=HYPERLINK(""https://evil.example"",""Open"")"');
});

it('DEFECT H7 (WP2 flips): user-controlled submitter and assignee names are exported verbatim', function () {
    $submitter = ticketUser(attributes: ['name' => '=cmd|\'/c calc\'!A1']);
    $assignee = ticketUser('operator', ['name' => '@evil']);
    $ticket = ticketFor($submitter, ['assignee_id' => $assignee->id]);

    [, $csv] = ticketExportCsv($this->operator);
    $row = ticketExportRows($csv)[$ticket->ticket_number];

    expect($row[5])->toBe('=cmd|\'/c calc\'!A1')
        ->and($row[6])->toBe('@evil');
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

it('DEFECT H7 (WP2 flips via escape: \'\'): a backslash before a quote is written in PHP\'s escape dialect, so an RFC-4180 reader corrupts the cell', function () {
    $title = 'Path C:\\share\\"quoted" end';
    $ticket = ticketFor(ticketUser(), ['title' => $title]);

    [, $csv] = ticketExportCsv($this->operator);
    $line = collect(explode("\n", $csv))->first(fn ($l) => str_starts_with($l, $ticket->ticket_number));

    // PHP's own backslash-escape reader recovers the value...
    expect(str_getcsv($line, escape: '\\')[1])->toBe($title)
        // ...an RFC-4180 reader (Excel, LibreOffice, escape: '') does not.
        ->and(ticketExportRows($csv)[$ticket->ticket_number][1] ?? null)->not->toBe($title);
});

it('DEFECT H7 (WP2 adds id DESC): export ordering is created_at DESC only, with no tie-breaker', function () {
    $sameInstant = now()->subDay()->startOfSecond();
    ticketFor(ticketUser(), ['created_at' => $sameInstant]);
    ticketFor(ticketUser(), ['created_at' => $sameInstant]);

    DB::enableQueryLog();
    ticketExportCsv($this->operator);
    $select = collect(DB::getQueryLog())->pluck('query')
        ->first(fn ($sql) => str_contains($sql, 'from `tickets`') && str_contains($sql, 'order by'));
    DB::disableQueryLog();

    expect($select)->toEndWith('order by `created_at` desc');
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
