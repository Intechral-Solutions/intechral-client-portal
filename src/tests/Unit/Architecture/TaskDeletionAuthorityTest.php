<?php

/*
 * EPIC-014 §12.1 / INV-11 (WP1): a task or project row — and a project delete cascades to its
 * tasks — is hard-deleted only through RecordedTimeGuard, which refuses while time references it
 * and maps the RESTRICT backstop. The behavioural suites (ProjectDeletionGuardTest,
 * TaskServiceTest) prove each existing path goes through the guard; this test stops a new path
 * from appearing beside it unnoticed.
 *
 * It is a narrow inventory, not a parser for intent: every delete-style call (`delete`,
 * `destroy`, `forceDelete`, `truncate` reached with `->`, `?->` or `::`) in an application file
 * that mentions tasks or projects must be one of the reviewed calls below. Adding one — even a
 * legitimate delete of some other model in such a file — fails here on purpose, so a reviewer
 * decides whether it can reach a task row. Declarations (`function delete(`) are not calls and
 * are ignored, as are comments and strings.
 */

/** Reviewed calls, as "relative/path.php" => the call's source lines, in file order. */
const REVIEWED_DELETE_CALLS = [
    // The authority itself: the one place a Task or Project model is deleted.
    'Services/RecordedTimeGuard.php' => ['$model->delete();'],
    // Time entries (their own allocation-aware delete), not tasks.
    'Services/TimeEntryService.php' => ['$current->delete();', '$current->delete();'],
    'Http/Controllers/TimeEntryController.php' => ['$this->service->delete($entry);'],
    // Child rows of a task/project, never the task or project row.
    'Http/Controllers/ProjectTaskController.php' => ['$task->checklistItems()->findOrFail($item)->delete();'],
    'Http/Controllers/ProjectMilestoneController.php' => ['$milestone->delete();'],
    // An invoice, which a project never cascades to.
    'Http/Controllers/Billing/InvoiceController.php' => ['$invoice->delete();'],
];

/**
 * Whether a file is one that can reach a task or project row. Identifiers and comments-stripped
 * code are checked for the words; string literals count only when they name the tables, so
 * `DB::table('tasks')->...->delete()` in an otherwise unrelated file is inspected while prose in a
 * message string does not pull a file in.
 */
function isTaskAwareSource(array $tokens): bool
{
    foreach ($tokens as $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING
            && preg_match('/(^|[^a-z_])(tasks|projects|project_columns)([^a-z_]|$)/i', $token[1])) {
            return true;
        }

        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_VARIABLE], true)
            && preg_match('/\b(tasks?|projects?)\b/i', $token[1])) {
            return true;
        }
    }

    return false;
}

/** @return list<string> source lines of delete-style calls in a task-aware source, else [] */
function deleteCallLinesInSource(string $source): array
{
    $tokens = token_get_all($source);
    if (! isTaskAwareSource($tokens)) {
        return [];
    }

    $lines = explode("\n", $source);
    $significant = array_values(array_filter($tokens, fn ($t) => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $found = [];
    foreach ($significant as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING
            || ! in_array(strtolower($token[1]), ['delete', 'destroy', 'forcedelete', 'truncate'], true)) {
            continue;
        }

        $before = $significant[$i - 1] ?? null;
        $after = $significant[$i + 1] ?? null;
        $isAccess = is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);

        if ($isAccess && $after === '(') {
            $found[] = trim($lines[$token[2] - 1]);
        }
    }

    return $found;
}

/** @return array<string, list<string>> relative path => source lines of delete-style calls */
function deleteCallsInTaskAwareFiles(): array
{
    $root = app_path();
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $calls = deleteCallLinesInSource(file_get_contents($file->getPathname()));
        if ($calls !== []) {
            $found[substr($file->getPathname(), strlen($root) + 1)] = $calls;
        }
    }

    ksort($found);

    return $found;
}

it('hard-deletes task and project rows only through RecordedTimeGuard', function () {
    $expected = REVIEWED_DELETE_CALLS;
    ksort($expected);

    expect(deleteCallsInTaskAwareFiles())->toBe($expected);
});

it('finds delete calls at all (the scan is not vacuous)', function () {
    $calls = deleteCallsInTaskAwareFiles();

    expect($calls)->toHaveKey('Services/RecordedTimeGuard.php')
        ->and(array_sum(array_map('count', $calls)))->toBeGreaterThanOrEqual(7);
});

it('inspects a raw table delete in an otherwise unrelated file (regression: literals used to be stripped before the task-aware check)', function (string $source) {
    // None of these mention a task or project outside a string literal.
    expect(deleteCallLinesInSource($source))->not->toBe([]);
})->with([
    'query builder' => ["<?php namespace App\\Console; use Illuminate\\Support\\Facades\\DB; class Purge { function run() { DB::table('tasks')->where('id', 1)->delete(); } }"],
    'projects table' => ["<?php DB::table('projects')->whereIn('id', [1])->delete();"],
    'raw SQL' => ["<?php DB::delete('delete from tasks where id = ?', [1]);"],
]);

it('does not pull unrelated files in: no task/project word, or only prose in a string', function (string $source) {
    expect(deleteCallLinesInSource($source))->toBe([]);
})->with([
    'sessions' => ["<?php DB::table('sessions')->where('user_id', 1)->delete();"],
    'prose string' => ["<?php \$page->delete(); \$x = 'a message about your work';"],
    'comment only' => ["<?php // deletes a task\n\$page->delete();"],
]);
