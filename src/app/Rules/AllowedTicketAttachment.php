<?php

namespace App\Rules;

use Closure;
use finfo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The one ticket-attachment rule (EPIC-017 §20.1, closing EPIC-010D F-7's type gap). Ticket
 * creation and both reply routes use rules(); no controller states its own attachment rules.
 *
 * A file is accepted only when its extension AND the content type detected from its bytes form
 * an allowed pair. Neither the filename nor the browser-supplied MIME is trusted alone: the type
 * is read with finfo from the uploaded file itself, never from UploadedFile::getMimeType() (which
 * a test double answers from its name). HTML, SVG, scripts and executables are detected as types
 * no pair allows, so they are refused whatever they are named. ZIP is an opaque download: the
 * application never extracts, previews or renders any attachment.
 *
 * Adding a type is a reviewed change to ALLOWED and its test. The detected type comes from the
 * libmagic that PHP's fileinfo extension uses, so it depends on the PHP build the application runs
 * on and can differ between hosts; TicketAttachmentPolicyTest pins each pair against whatever
 * build runs it, and a deployment host's PHP must pass that suite before relying on this list.
 */
class AllowedTicketAttachment implements ValidationRule
{
    public const MAX_FILES = 10;

    public const MAX_KILOBYTES = 20480;

    /**
     * Extension => the detected content types allowed for it.
     *
     * - `csv`: libmagic reports comma-separated text as text/csv and other delimiters as text/plain.
     * - OOXML: libmagic names the format only when `[Content_Types].xml` is the archive's first
     *   entry; other writers' files are reported as the ZIP they are, and ZIP is allowed anyway.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'txt' => ['text/plain'],
        'log' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip' => ['application/zip'],
    ];

    /**
     * The complete attachment rules for a ticket create or reply request.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_KILOBYTES, new self],
        ];
    }

    /** The content type finfo detects from the file's bytes, or null when it cannot be read. */
    public static function detectedType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) ? strtolower($type) : null;
    }

    public static function allows(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $type = self::detectedType($file);

        return $type !== null && in_array($type, self::ALLOWED[$extension] ?? [], true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! self::allows($value)) {
            $fail('This file type cannot be attached. Allowed types: '.implode(', ', array_keys(self::ALLOWED)).'.');
        }
    }
}
