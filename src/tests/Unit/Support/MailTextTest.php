<?php

use App\Support\Mail\MailText;
use Illuminate\Support\HtmlString;

/*
 * EPIC-017 F-3: user-authored text inside a Markdown mail. These are the string rules; what a recipient is actually given, in the HTML part and
 * in the text/plain part, is asserted in tests/Feature/Notifications/MailTextRenderingTest.php.
 */

it('turns a value into one plain line: controls, separators and bidirectional overrides gone, whitespace collapsed', function () {
    $dirty = "  Printer\r\nis\tbroken\x00\x07 \u{2028}now\u{2029}\u{202E}txt.exe\u{2066}  ";

    expect(MailText::plain($dirty))->toBe('Printer is broken now txt.exe');
});

it('returns an empty value for null, empty and whitespace-only input', function (?string $value) {
    expect(MailText::plain($value))->toBe('')->and((string) MailText::escape($value))->toBe('');
})->with([[null], [''], ["  \n\t "]]);

it('replaces invalid UTF-8 instead of failing', function () {
    $value = "caf\xE9 broken \xC3\x28";

    expect(MailText::plain($value))->toBeString()->and(mb_check_encoding(MailText::plain($value), 'UTF-8'))->toBeTrue()
        ->and(mb_check_encoding((string) MailText::escape($value), 'UTF-8'))->toBeTrue();
});

it('cuts to the limit with an ellipsis counted inside it, by characters not bytes', function () {
    expect(MailText::plain('abcdefghij', 5))->toBe('abcd…')
        ->and(MailText::plain('abcde', 5))->toBe('abcde')
        ->and(MailText::plain('ééééééé', 4))->toBe('ééé…')
        ->and(MailText::plain('abcdefghij'))->toBe('abcdefghij');
});

it('returns an HtmlString, so neither mail template encodes the text a second time', function () {
    expect(MailText::escape('a'))->toBeInstanceOf(HtmlString::class)->and(MailText::line('a'))->toBeInstanceOf(HtmlString::class);
});

it('writes every ASCII punctuation character as a numeric character reference and leaves everything else alone', function () {
    expect((string) MailText::escape('Hello Wörld 42 日本 🙂'))->toBe('Hello Wörld 42 日本 🙂')
        ->and((string) MailText::escape('[a](b)'))->toBe('&#91;a&#93;&#40;b&#41;')
        ->and((string) MailText::escape('**bold** _it_ `code`'))->toBe('&#42;&#42;bold&#42;&#42; &#95;it&#95; &#96;code&#96;')
        ->and((string) MailText::escape('a & b < c > d "e" \'f\''))->toBe('a &#38; b &#60; c &#62; d &#34;e&#34; &#39;f&#39;')
        ->and((string) MailText::escape('back\\slash'))->toBe('back&#92;slash');
});

it('decodes back to exactly the typed text, whatever it holds', function (string $typed) {
    // What both templates do with the value: the text part runs html_entity_decode over it, CommonMark decodes it in the HTML part.
    expect(html_entity_decode((string) MailText::escape($typed), ENT_QUOTES, 'UTF-8'))->toBe($typed);
})->with([
    'every ASCII punctuation mark' => ['!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~'],
    'literal backslashes' => ['C:\Users\dana \\server \*x\* trailing\\'],
    'a typed entity' => ['AT&amp;T &lt;b&gt; &#42; &copy;'],
    'unicode' => ['Zoë Ørsted 日本語 – “quotes” … 🙂'],
]);

it('escapes after cutting, so the limit counts what the author typed', function () {
    expect((string) MailText::escape('****', 3))->toBe('&#42;&#42;…');
});

describe('line()', function () {
    it('keeps template words and their Markdown, encoding them only the way Blade would', function () {
        expect((string) MailText::line('**Subject:** We\'ll <b> & "go"'))->toBe('**Subject:** We&#039;ll &lt;b&gt; &amp; &quot;go&quot;');
    });

    it('uses an escaped part as it is and does not encode it a second time', function () {
        expect((string) MailText::line('**Subject:** ', MailText::escape('[x](y) & z'), '.'))->toBe('**Subject:** &#91;x&#93;&#40;y&#41; &#38; z.');
    });

    it('joins any number of parts in order', function () {
        expect((string) MailText::line('a', MailText::escape('*'), 'b', MailText::escape('_'), 'c'))->toBe('a&#42;b&#95;c');
    });
});
