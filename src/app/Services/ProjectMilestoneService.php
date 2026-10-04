<?php

namespace App\Services;

use App\Models\ProjectMilestone;
use App\Models\User;

/**
 * Explicit milestone completion (EPIC-015 §9, Q2, INV-P9). The only writer of `completed_at` and
 * `completed_by`. Authorization is the caller's (the milestone routes: A9).
 *
 * Both operations touch the milestone row only: no task moves, no task status changes, no timer
 * stops, and finishing every linked task never completes a milestone by itself.
 *
 * Each is one conditional UPDATE, so it is idempotent and safe under a concurrent repeat without a
 * lock: completing an already-complete milestone matches no row and keeps the original
 * `completed_at`/`completed_by`; reopening an open one changes nothing.
 */
final class ProjectMilestoneService
{
    public function complete(ProjectMilestone $milestone, User $actor): ProjectMilestone
    {
        ProjectMilestone::query()
            ->whereKey($milestone->id)
            ->whereNull('completed_at')
            ->update(['completed_at' => now(), 'completed_by' => $actor->id]);

        return $milestone->refresh();
    }

    public function reopen(ProjectMilestone $milestone): ProjectMilestone
    {
        ProjectMilestone::query()
            ->whereKey($milestone->id)
            ->whereNotNull('completed_at')
            ->update(['completed_at' => null, 'completed_by' => null]);

        return $milestone->refresh();
    }
}
