<?php

namespace App\Support\Mail;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * User-authored text inside a Markdown mail (EPIC-017 §11, F-3).
 *
 * A notification's lines, greeting and names are rendered twice from one Blade template: through CommonMark into the HTML part, and, with
 * Blade's ordinary `{{ }}` encoding followed by one `html_entity_decode`, into the text/plain part. A person's title or name that contains
 * Markdown (`[text](https://elsewhere)`, `![x](https://elsewhere/p.png)`, `**`, a backtick, `# `) would become a live link, a remote image or
 * formatting in the HTML part, so it has to be neutralised; but the text part is not Markdown, so any backslash-style escaping would be
 * shown there as typed (`\*`, `\#`).
 *
 * The one representation that is right for both is a numeric character reference. CommonMark decodes `&#42;` to a literal `*` and never lets a
 * reference stand for structure (a reference cannot start emphasis, a link, a heading, a list or a quote), and the text part's
 * `html_entity_decode` turns the same reference back into the character that was typed. So {@see escape()} returns every ASCII punctuation
 * character as `&#NN;` (the five that HTML itself needs, `& < > " '`, included), as an {@see Htmlable}, which both templates echo untouched
 * instead of encoding a second time. A genuine backslash is `&#92;`, which CommonMark does not read as an escape character, so it survives.
 *
 * Nothing is done to the renderer: no replaced Markdown service, no changed theme or view. The cost is that a line with user text in it must be
 * built with {@see line()}, which keeps the template's own words (and its deliberate `**bold**`) as they are and joins the escaped parts to them.
 *
 * Limits, stated rather than hidden: a bare `https://…` in the text stays text in this HTML, but a mail client may link it itself; and Laravel's
 * Markdown renderer finishes with `str_replace('\[', '[')` over the HTML part, so a backslash a person typed immediately before `[` is not shown
 * there (the text part is untouched). Both are cosmetic and cannot be made worse by a template.
 *
 * Use {@see plain()} for a mail subject (a header, not Markdown), {@see escape()} for one piece of user text and {@see line()} for a line, a
 * greeting or any other value that mixes template words with escaped user text.
 */
final class MailText
{
    /**
     * One line of plain text: invalid UTF-8 replaced, every control, line and paragraph separator and bidirectional override
     * removed, runs of whitespace collapsed to one space, trimmed, and cut to $limit characters (0 means no limit) with an ellipsis.
     */
    public static function plain(?string $text, int $limit = 0): string
    {
        $text = mb_scrub((string) $text, 'UTF-8');

        // Controls (CR and LF included), U+0085, U+2028/2029 line and paragraph separators, and the bidirectional embedding,
        // override and isolate controls that can make one name or title read as another.
        $text = (string) preg_replace('/[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($limit > 0 && mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit - 1)).'…';
        }

        return $text;
    }

    /**
     * {@see plain()}, then every ASCII punctuation character as a numeric character reference: literal in the HTML part, the typed
     * characters again in the text part. Letters, digits, spaces and non-ASCII characters are left as they are.
     */
    public static function escape(?string $text, int $limit = 0): HtmlString
    {
        $plain = self::plain($text, $limit);

        return new HtmlString((string) preg_replace_callback(
            '/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/',
            static fn (array $m): string => '&#'.ord($m[0]).';',
            $plain,
        ));
    }

    /**
     * A line, greeting or other value made of the template's own words and escaped user text. A `string` part is template text: it keeps
     * its Markdown (`**Subject:**`) and is HTML-encoded only as Blade would have encoded it. An {@see Htmlable} part (from {@see escape()})
     * is used as it is. The result is an {@see HtmlString}, which neither template encodes again.
     */
    public static function line(string|Htmlable ...$parts): HtmlString
    {
        return new HtmlString(implode('', array_map(
            static fn (string|Htmlable $part): string => $part instanceof Htmlable
                ? $part->toHtml()
                : htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $parts,
        )));
    }
}
