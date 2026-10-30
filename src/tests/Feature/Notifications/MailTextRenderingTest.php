<?php

use App\Models\Invitation;
use App\Models\TicketStatusHistory;
use App\Notifications\InvitationNotification;
use App\Notifications\NewTicketAlertNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Notifications\TicketStatusNotification;
use App\Support\Mail\MailText;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * EPIC-017 F-3, asserted on what a recipient is actually given: each notification is delivered through the real mail channel and both
 * alternatives of the message are read, the HTML part and the text/plain part. Laravel renders the HTML through CommonMark, so
 * user-authored text must come out literal there (no link, image, formatting or markup made from it); the text part is not Markdown at all,
 * so the same text must come out exactly as it was typed, with no escaping artefacts and every genuine backslash intact.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->mtrSent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) {
        $this->mtrSent[] = $event->message;
    });
});

/** Hostile and awkward strings, each a different way to become markup in a Markdown mail, or to be mangled by escaping. */
const MTR_PAYLOADS = [
    'markdown link' => '[Reset your password](https://evil.example/phish)',
    'remote image' => '![pixel](https://evil.example/pixel.png)',
    'emphasis and code' => '**urgent** _now_ `rm -rf` ~~x~~',
    'raw html' => '<a href="https://evil.example/h">click</a><script>alert(1)</script><img src="https://evil.example/i.png">',
    'angle autolink' => '<https://evil.example/auto>',
    'reference link' => "[ref]: https://evil.example/ref\n[ref]",
    'heading, list and quote' => "# Heading\n- item\n1. item\n> quote",
    'entities' => 'AT&amp;T &lt;b&gt; &#42;not bold&#42; &copy;',
    'table' => "| a | b |\n|---|---|\n| c | d |",
    'every ASCII punctuation mark' => '!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~',
    'unicode' => 'Zoë Ørsted 日本語 – “quotes” … 🙂 ½',
    'literal backslashes' => 'C:\Users\dana\new \\server \*not bold\* \\\\ trailing\\',
    'backslash before a bracket' => 'path\[1] and \]',
    'plain words' => 'Printer is broken',
];

/** @return array<string, array{string, string}> a named dataset row per payload */
function mtrPayloadSets(): array
{
    $sets = [];
    foreach (MTR_PAYLOADS as $name => $payload) {
        $sets[$name] = [$name, $payload];
    }

    return $sets;
}

/** The href and src attribute values of the ELEMENTS in rendered HTML (text that merely looks like an attribute is not one). */
function mtrTargets(string $html): array
{
    $targets = [];
    foreach (mtrXPath($html)->query('//*[@href or @src]') as $node) {
        foreach (['href', 'src'] as $attribute) {
            if ($node->hasAttribute($attribute)) {
                $targets[] = $node->getAttribute($attribute);
            }
        }
    }

    return array_values(array_unique($targets));
}

/** @return array<string, int> how many elements of each name the HTML holds */
function mtrElements(string $html): array
{
    $counts = [];
    foreach (mtrXPath($html)->query('//*') as $node) {
        $counts[$node->nodeName] = ($counts[$node->nodeName] ?? 0) + 1;
    }
    ksort($counts);

    return $counts;
}

function mtrXPath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();

    return new DOMXPath($dom);
}

/**
 * Laravel's Markdown mail renderer ends with `str_replace('\\[', '[', $contents)` (Illuminate\Mail\Markdown::render) over the finished
 * HTML, so a backslash a person typed immediately before `[` is not shown in the HTML part. Cosmetic and safe; it is the one place the HTML
 * reader sees less than was typed, and it does not touch the text part.
 */
function mtrAsLaravelShows(string $typed): string
{
    return str_replace('\\[', '[', $typed);
}

/** What an HTML reader sees: tags removed, entities decoded, whitespace collapsed. */
function mtrVisible(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
}

/**
 * Deliver the notification built by $build through the real mail channel (a transport that keeps the message) and return both
 * alternatives of what would have been sent.
 *
 * @param  Closure(string): array{0: object, 1: Closure(object): void}  $build  text -> [notification, a closure that delivers it]
 * @return array{html: string, text: string, subject: string}
 */
function mtrDeliver(Closure $build, string $text): array
{
    [$notification, $deliver] = $build($text);
    $before = count(test()->mtrSent);

    $deliver($notification);

    expect(test()->mtrSent)->toHaveCount($before + 1);
    $message = test()->mtrSent[$before];

    return ['html' => (string) $message->getHtmlBody(), 'text' => (string) $message->getTextBody(), 'subject' => (string) $message->getSubject()];
}

/** The parts of a delivered message that differ between two otherwise identical messages: ticket numbers, ids, tokens and the random priority. */
function mtrNormalise(string $body): string
{
    $body = preg_replace('/TKT-[A-Za-z0-9]+/', 'TKT-N', $body);
    $body = preg_replace('~/(tickets|invitation)/[A-Za-z0-9]+~', '/$1/N', $body);

    return preg_replace('/Priority:\*\* \w+/', 'Priority:** P', $body);
}

/**
 * Assert $payload, put into every user-authored field of the notification, is shown literally in BOTH parts of the delivered mail:
 *  - HTML: every link points at the application (the layout's own header link or one of $allowedPrefixes), and the hostile text added no
 *    element that the same message built with innocuous text does not have; the reader sees the typed characters;
 *  - text/plain: the typed characters appear exactly as typed, between the same neighbours as the innocuous message has, with no escaping
 *    artefact (no added backslash, no entity), and every genuine backslash intact;
 *  - the subject: one line, the typed words.
 *
 * @param  list<string>  $allowedPrefixes
 * @param  list<string>  $alsoTyped  other renderings of the payload the message may legitimately hold (a field the application cuts shorter)
 */
function mtrAssertLiteral(Closure $build, string $payload, array $allowedPrefixes, array $alsoTyped = []): void
{
    $mail = mtrDeliver($build, $payload);
    $baseline = mtrDeliver($build, 'Plain text');
    $typed = MailText::plain($payload);

    foreach (mtrTargets($mail['html']) as $target) {
        expect($target === config('app.url') || collect($allowedPrefixes)->contains(fn (string $prefix) => str_starts_with($target, $prefix)))
            ->toBeTrue("unexpected link target in the HTML part: {$target} for payload: {$payload}");
    }
    expect(mtrElements($mail['html']))->toBe(mtrElements($baseline['html']))
        ->and(mtrVisible($mail['html']))->toContain(mtrAsLaravelShows($typed));

    // The text part: replace the innocuous words in the baseline by the typed text and the two must be the same message.
    $unTyped = $mail['text'];
    foreach (array_unique([$typed, ...$alsoTyped]) as $variant) {
        $unTyped = str_replace($variant, 'Plain text', $unTyped);
    }
    expect($mail['text'])->toContain($typed)
        ->and(mtrNormalise($unTyped))->toBe(mtrNormalise($baseline['text']), 'the text/plain part differs from the plain-words message beyond the typed text');

    expect($mail['subject'])->not->toContain("\n")->not->toContain("\r");
}

function mtrTicket(string $title, string $description = 'A description.', array $owner = []): array
{
    $user = ticketUser('user', $owner);

    return [$user, ticketFor($user, ['title' => $title, 'description' => $description])];
}

describe('TicketCreatedNotification', function () {
    it('shows the title and the recipient name literally in the HTML and the text part', function (string $name, string $payload) {
        mtrAssertLiteral(function (string $text) {
            [$user, $ticket] = mtrTicket($text, 'x', ['name' => $text]);

            return [new TicketCreatedNotification($ticket), fn ($n) => $user->notifyNow($n)];
        }, $payload, [url('/tickets/')]);
    })->with(mtrPayloadSets());

    it('keeps a plain, single-line subject', function () {
        [$user, $ticket] = mtrTicket("Printer\r\nBcc: attacker@evil.example\nbroken");
        $subject = (new TicketCreatedNotification($ticket))->toMail($user)->subject;

        expect($subject)->toBe("[{$ticket->ticket_number}] Ticket received: Printer Bcc: attacker@evil.example broken")->not->toContain("\n")->not->toContain("\r");
    });
});

describe('TicketRepliedNotification', function () {
    it('shows the title, the author name, the recipient name and the preview literally in the HTML and the text part', function (string $name, string $payload) {
        mtrAssertLiteral(function (string $text) {
            [$owner, $ticket] = mtrTicket($text, 'x', ['name' => $text]);
            $reply = ticketReply($ticket, ticketUser('operator', ['name' => $text]), false, $text);

            return [new TicketRepliedNotification($ticket, $reply), fn ($n) => $owner->notifyNow($n)];
        }, $payload, [url('/tickets/')]);
    })->with(mtrPayloadSets());

    it('still limits the preview to 200 characters, then `...`, in both parts', function () {
        [$owner, $ticket] = mtrTicket('Title');
        $reply = ticketReply($ticket, ticketUser('operator'), false, str_repeat('a', 150).str_repeat('b', 100).'TAILMARKER');

        $owner->notifyNow(new TicketRepliedNotification($ticket, $reply));
        $message = $this->mtrSent[0];

        foreach ([(string) $message->getHtmlBody(), (string) $message->getTextBody()] as $body) {
            expect($body)->toContain(str_repeat('a', 150).str_repeat('b', 50).'...')->not->toContain('TAILMARKER');
        }
    });
});

describe('InvitationNotification', function () {
    it('shows the inviter name literally in the HTML and the text part', function (string $name, string $payload) {
        mtrAssertLiteral(function (string $text) {
            $inviter = ticketUser('operator', ['name' => $text]);
            $invitation = Invitation::factory()->create(['invited_by' => $inviter->id]);

            return [new InvitationNotification($invitation), fn ($n) => Notification::route('mail', 'new.person@desk.invalid')->notifyNow($n)];
        }, $payload, [rtrim(route('invitation.show', ['token' => 'x']), 'x')]);
    })->with(mtrPayloadSets());
});

describe('NewTicketAlertNotification', function () {
    it('shows every user-authored field literally in the HTML and the text part', function (string $name, string $payload) {
        mtrAssertLiteral(function (string $text) {
            [, $ticket] = mtrTicket($text, $text, ['name' => $text]);
            $ticket->forceFill(['category' => mb_substr($text, 0, 64)])->save();

            return [new NewTicketAlertNotification($ticket->fresh()), fn ($n) => Notification::route('mail', 'helpdesk@desk.invalid')->notifyNow($n)];
        }, $payload, [url('/operator/tickets/')], [MailText::plain(mb_substr($payload, 0, 64))]);
    })->with(mtrPayloadSets());
});

describe('TicketStatusNotification', function () {
    it('shows the title and the recipient name literally in the HTML and the text part', function (string $status, string $name, string $payload) {
        mtrAssertLiteral(function (string $text) use ($status) {
            [$owner, $ticket] = mtrTicket($text, 'x', ['name' => $text]);
            $ticket->forceFill(['status' => $status])->save();
            $history = TicketStatusHistory::create(['ticket_id' => $ticket->id, 'user_id' => ticketUser('operator')->id, 'old_status' => 'open', 'new_status' => $status, 'created_at' => now()]);

            return [new TicketStatusNotification($ticket->fresh(), $status, $history->id), fn ($n) => $owner->notifyNow($n)];
        }, $payload, [url('/tickets/')]);
    })->with(function () {
        foreach (['pending_user', 'resolved'] as $status) {
            foreach (MTR_PAYLOADS as $name => $payload) {
                yield "$status / $name" => [$status, $name, $payload];
            }
        }
    });
});

it('proves the assertion can fail: the same payloads rendered WITHOUT MailText do become links and images', function () {
    $raw = fn (string $text) => (string) (new MailMessage)->greeting('Hello')->line($text)->render();

    expect(mtrTargets($raw(MTR_PAYLOADS['markdown link'])))->toContain('https://evil.example/phish')
        ->and(mtrTargets($raw(MTR_PAYLOADS['remote image'])))->toContain('https://evil.example/pixel.png')
        ->and($raw(MTR_PAYLOADS['emphasis and code']))->toContain('<strong')->toContain('<code>');
});
