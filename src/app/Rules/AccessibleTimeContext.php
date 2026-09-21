<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class AccessibleTimeContext implements ValidationRule
{
    public function __construct(
        private readonly User $user,
        private readonly string $type,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::allows($this->user, $this->type, $value)) {
            $fail('The selected :attribute is invalid.');
        }
    }

    public static function allows(User $user, string $type, mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        return match ($type) {
            'project' => self::canViewProject($user, Project::find($value)),
            'task' => self::canUseTask($user, Task::with(['project', 'ticket'])->find($value)),
            'ticket' => self::canViewTicket($user, Ticket::find($value)),
            default => false,
        };
    }

    private static function canUseTask(User $user, ?Task $task): bool
    {
        if (! $task) {
            return false;
        }

        if ($task->assignee_id === $user->id) {
            return true;
        }

        if ($task->project && self::canViewProject($user, $task->project)) {
            return true;
        }

        return $task->ticket && self::canViewTicket($user, $task->ticket);
    }

    private static function canViewProject(User $user, ?Project $project): bool
    {
        return $project !== null && Gate::forUser($user)->allows('view', $project);
    }

    private static function canViewTicket(User $user, ?Ticket $ticket): bool
    {
        return $ticket !== null && Gate::forUser($user)->allows('view', $ticket);
    }
}
