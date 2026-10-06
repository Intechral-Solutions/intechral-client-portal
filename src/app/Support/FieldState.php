<?php

namespace App\Support;

use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Support\Arr;
use Illuminate\Support\ViewErrorBag;
use InvalidArgumentException;

/**
 * The Blade field identity contract (EPIC-016 §7.5), in one place.
 *
 * Four identifiers are related and kept separate: the HTML `name` (what is submitted), the DOM `id`
 * (the `label[for]` target and the root of every derived id), the validation `error-key`(s) looked up
 * in the error bag, and the error element id, which is always `{id}-error`. The `x-ui` field
 * components and `x-ui.field-error` resolve them here so the rules cannot drift between components.
 *
 * It reads only what it is handed (a name, an id, keys, an error bag); it never touches the request,
 * the session or the database.
 */
final class FieldState
{
    /** A simple scalar name: it is its own id and its own error key (§7.5 rule 1). */
    private const SIMPLE_NAME = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /**
     * @param  list<string>  $errorKeys
     */
    private function __construct(
        public readonly ?string $id,
        public readonly array $errorKeys,
        public readonly bool $invalid,
        public readonly ?string $errorId,
        public readonly ?string $describedBy,
    ) {}

    public static function isSimpleName(?string $name): bool
    {
        return $name !== null && preg_match(self::SIMPLE_NAME, $name) === 1;
    }

    /**
     * @param  string|list<string>|null  $errorKey  one key, or an ordered list (wildcards allowed)
     * @param  mixed  $errors  a ViewErrorBag, a MessageBag, or null (no errors known)
     * @param  string|null  $describedBy  the caller's own `aria-describedby` tokens, kept in order
     * @param  bool  $invalid  forces the invalid state regardless of the error bag
     * @param  bool  $requireId  false for a checkbox wrapped in its own <label> (§7.2: it needs no id)
     *
     * @throws InvalidArgumentException when a non-simple `name` is given without an explicit `id`
     */
    public static function resolve(
        ?string $name,
        ?string $id,
        string|array|null $errorKey,
        mixed $errors,
        ?string $describedBy = null,
        bool $invalid = false,
        bool $requireId = true,
    ): self {
        $id = ($id !== null && $id !== '') ? $id : null;

        if ($id === null && $name !== null && $name !== '') {
            if (! self::isSimpleName($name)) {
                if (! $requireId) {
                    return self::resolveWithoutId($errorKey, $errors, $describedBy, $invalid);
                }

                throw new InvalidArgumentException(
                    "A field named \"{$name}\" is not a simple name, so it needs an explicit id (EPIC-016 §7.5 rule 2).",
                );
            }

            $id = $name;
        }

        $keys = self::keys($errorKey, self::isSimpleName($name) ? $name : null);
        $invalid = $invalid || self::hasAnyError($errors, $keys);
        $errorId = $id !== null ? $id.'-error' : null;

        $tokens = preg_split('/\s+/', trim((string) $describedBy), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($invalid && $errorId !== null) {
            $tokens[] = $errorId;
        }
        $merged = array_values(array_unique($tokens));

        return new self(
            id: $id,
            errorKeys: $keys,
            invalid: $invalid,
            errorId: $errorId,
            describedBy: $merged === [] ? null : implode(' ', $merged),
        );
    }

    /**
     * A wrapped checkbox with a non-simple name (`permissions[]`) and no id: it has no error element of
     * its own (a group error is rendered once after the group, §7.5 rule 8), so only the caller's own
     * `aria-describedby` tokens are kept.
     *
     * @param  string|list<string>|null  $errorKey
     */
    private static function resolveWithoutId(string|array|null $errorKey, mixed $errors, ?string $describedBy, bool $invalid): self
    {
        $keys = self::keys($errorKey, null);
        $tokens = array_values(array_unique(preg_split('/\s+/', trim((string) $describedBy), -1, PREG_SPLIT_NO_EMPTY) ?: []));

        return new self(
            id: null,
            errorKeys: $keys,
            invalid: $invalid || self::hasAnyError($errors, $keys),
            errorId: null,
            describedBy: $tokens === [] ? null : implode(' ', $tokens),
        );
    }

    /**
     * The first error message across the keys, in key order, or null.
     *
     * @param  string|list<string>|null  $errorKey
     */
    public static function firstError(mixed $errors, string|array|null $errorKey): ?string
    {
        $bag = self::bag($errors);

        if ($bag === null) {
            return null;
        }

        foreach (self::keys($errorKey, null) as $key) {
            $messages = Arr::flatten($bag->get($key));

            if ($messages !== []) {
                return (string) $messages[0];
            }
        }

        return null;
    }

    /**
     * @param  string|list<string>|null  $errorKey
     * @return list<string>
     */
    private static function keys(string|array|null $errorKey, ?string $fallback): array
    {
        if ($errorKey === null || $errorKey === [] || $errorKey === '') {
            return $fallback !== null ? [$fallback] : [];
        }

        return array_values(array_filter((array) $errorKey, fn ($key) => is_string($key) && $key !== ''));
    }

    /**
     * @param  list<string>  $keys
     */
    private static function hasAnyError(mixed $errors, array $keys): bool
    {
        $bag = self::bag($errors);

        if ($bag === null) {
            return false;
        }

        foreach ($keys as $key) {
            if (Arr::flatten($bag->get($key)) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function bag(mixed $errors): ?MessageBagContract
    {
        if ($errors instanceof ViewErrorBag) {
            return $errors->getBag('default');
        }

        return $errors instanceof MessageBagContract ? $errors : null;
    }
}
