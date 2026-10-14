<?php

namespace App\Http\Presenters;

use App\Models\Project;
use App\Models\ProjectMilestone;

/**
 * The pilot customer's view of a project (Release 0.9, R0.9-CS1; docs/architecture/customer-pilot-surface.md §6).
 *
 * An exact-key allowlist. Nothing here is "the internal DTO minus some keys": each method names the fields it
 * sends and no model, relation or attribute reaches the page by default. Project data carries no internal or
 * public marker, so only what the owner contract names is classified customer-safe and the rest is withheld:
 *
 *   project      name, lifecycle status (and, on the list, the id that opens it)
 *   milestone    name, due date
 *
 * Withheld on purpose, whatever the viewer holds: description (operator-authored free text with no marker;
 * owner decision pending), budget, project dates, health and its reasons, task counts and progress, time,
 * members and their names, creator, client and company links, milestone descriptions, completion state, task counts, the
 * completion time and the completer's name.
 */
final class CustomerProjectPresenter
{
    /**
     * One project-list row. The project must have been selected with `id`, `name` and `status` only.
     *
     * @return array{id: int, name: string, status: string}
     */
    public static function row(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
        ];
    }

    /**
     * @return array{name: string, status: string}
     */
    public static function project(Project $project): array
    {
        return [
            'name' => $project->name,
            'status' => $project->status,
        ];
    }

    /**
     * A project's milestones, in due-date order. The milestone must have been selected with `name` and `due_date` only.
     * Completion is withheld with the rest (owner decision R0.9-CS1: names and dates only).
     *
     * @return array{name: string, dueDate: string}
     */
    public static function milestone(ProjectMilestone $milestone): array
    {
        return [
            'name' => $milestone->name,
            'dueDate' => $milestone->due_date->toDateString(),
        ];
    }
}
