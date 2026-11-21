<?php

namespace App\Services;

use App\Exceptions\DoneColumnConfigurationException;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use App\Shared\Pilot\CustomerPilotGate;
use App\Support\Organizations\PortalState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProjectService
{
    /** Default columns created with every new project */
    private const DEFAULT_COLUMNS = [
        ['name' => 'Backlog',     'position' => 0, 'is_done_column' => false],
        ['name' => 'To Do',       'position' => 1, 'is_done_column' => false],
        ['name' => 'In Progress', 'position' => 2, 'is_done_column' => false],
        ['name' => 'In Review',   'position' => 3, 'is_done_column' => false],
        ['name' => 'Done',        'position' => 4, 'is_done_column' => true],
    ];

    /** How often a transaction is retried when the database picks it as a deadlock victim. */
    private const LOCK_ATTEMPTS = 3;

    /** How often a move/delete starts over because the task changed column while it waited. */
    private const RESTART_ATTEMPTS = 5;

    public function __construct(private RecordedTimeGuard $recordedTime) {}

    /**
     * Create a project: the row, its default board, the creator's membership, any extra members and
     * any linked Organizations, as ONE transaction (EPIC-015 INV-P10, EPIC-014 A1.3.1(2)). A failure
     * anywhere leaves no project, column, membership or Organization-link row behind, so every project
     * that exists has exactly one Done column (INV-8).
     *
     * Optional `$data['members']` (`[['user_id' => X, 'role' => 'member|manager'], ...]`) and
     * `$data['companies']` (Organization ids; the wire name is kept until the unified Directory
     * interface) are applied inside the same transaction. Whether the actor may supply members is the
     * caller's authorization (ProjectController::store); whether each Organization exists and is one the
     * creator may link is decided HERE, under the locks, so a stale or forged id writes nothing.
     *
     * LOCK ORDER (ORG-0 section 6.1; owner rulings 8; CP6 F-3): EVERY user row the writes will reference (the creator, whose row `created_by` and the manager
     * membership reference, and each extra member, whose row the `project_user` foreign key checks), SHARED, in ONE statement in ascending id order -> the
     * linked Organization rows, SHARED, in ascending id order -> the project writes. The member rows used to be locked implicitly by the foreign key AFTER the
     * Organization rows, which broke "user rows before Organization rows"; they are now taken first, explicitly, so the foreign key finds its parent already
     * locked and takes nothing new. A shared Organization lock is what stops an Organization being deleted (which takes the row exclusively) between the check and
     * the link; ascending order is what lets two creations with overlapping sets never deadlock. No Gate call and no permission-cache load happens in the
     * transaction: whether the creator is an unrestricted operator is read from the TABLES, after the user locks (CP6 F-2: never from a Gate answer given
     * before the transaction, which a revoked capability would leave stale). Every read that decides a link is a LOCKING read (CP6 F-1), so it sees the latest
     * committed state and not the older snapshot a plain read earlier in the transaction would have fixed. Nothing leaves the database inside the transaction:
     * no event, queue job, mail or file write.
     *
     * ACTOR STILL ACTIVE (CP8): the creator's row is among the user rows locked shared in that first statement, and the same statement returns its `disabled_at`.
     * A creator who is suspended (committed before the lock was granted) is refused with an AuthorizationException BEFORE any write; the members' own suspension
     * is deliberately not checked (a suspended member may be in the list). Suspension takes the row exclusively, so the two serialize: suspension first -> refused;
     * creation first -> it completes, then the suspension follows. This is a narrow tightening, for these two operations only, of the in-flight-request rule of
     * docs/architecture/account-lifecycle.md (Info-4).
     *
     * DISABLED ORGANIZATIONS (CP8, owner ruling B-2): an unrestricted operator may link any Organization; anyone else may link only an ENABLED one (a new
     * association made through membership alone is refused for a Disabled Organization). The state is read from the Organization rows locked in this transaction.
     */
    public function create(User $creator, array $data): Project
    {
        $organizationIds = $this->organizationIds($data['companies'] ?? []);
        $userIds = $this->userIds([$creator->getKey(), ...array_column($data['members'] ?? [], 'user_id')]);

        return DB::transaction(function () use ($creator, $data, $organizationIds, $userIds): Project {
            $this->requireActiveActor($this->lockUsers($userIds), (int) $creator->getKey());

            $unrestricted = $this->isUnrestricted((int) $creator->getKey());
            $present = $this->lockLinkableOrganizations($organizationIds, (int) $creator->getKey(), $unrestricted);
            $this->requireLinkable($organizationIds, $present, $unrestricted);

            $project = Project::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'created_by' => $creator->id,
                'start_date' => $data['start_date'] ?? null,
                'target_date' => $data['target_date'] ?? null,
                'status' => $data['status'] ?? 'active',
                'budget' => $data['budget'] ?? null,
            ]);

            // Seed default columns
            foreach (self::DEFAULT_COLUMNS as $col) {
                $project->columns()->create($col);
            }

            // Add creator as manager
            $project->members()->attach($creator->id, ['role' => 'manager']);

            if (! empty($data['members'])) {
                $this->syncMembers($project, $data['members']);
            }

            $this->insertLinks($project->id, $organizationIds);

            return $project;
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * Replace the Organizations a project is linked to, FOR THE ACTOR. Only the links the actor may see are theirs to change: an operator's set is
     * every link; anyone else's is the links to Organizations they belong to. A link the actor cannot see is PRESERVED (never removed by omission: the
     * submitted list cannot name it, and omitting it is not a request to remove it). A submitted id that is unknown or that the actor may not link fails
     * with ONE message, so a hidden Organization cannot be told from a missing one, and nothing is written.
     *
     * LOCK ORDER (ORG-0 section 6.1; owner ruling 8): the actor's user row (shared) -> the actor's operator eligibility, from the tables -> every Organization this call may add or remove, SHARED, in ascending
     * id order (the superset of the submitted ids and the currently linked ones, read first and re-checked below) -> the PROJECT row, exclusively, which is
     * what serializes two synchronizations of one project -> the link rows, read under lock -> the writes. If, under the project lock, the links turn out to
     * differ from the ones whose Organizations were locked (another synchronization committed meanwhile), the attempt starts over rather than touch an
     * Organization it did not lock. The actor's row is read as it is locked and a suspended actor is refused before anything is written (CP8, see
     * {@see create()}); a NEW link to a Disabled or Not enabled Organization is refused for anyone but an unrestricted operator (owner ruling B-2), judged from the
     * Organization rows locked here against the links read under the project lock. No Gate call and no permission-cache load in the transaction (CP6 F-2: eligibility is re-derived from the tables after the actor lock, so a
     * capability revoked before that read is seen; a revocation that commits LATER, or one made to a role the actor merely holds, does not serialize with the
     * actor's row and is outside this guarantee, see docs/product/release-1.0-org-2-physical-consolidation.md section 15). Every read that decides a link or a
     * membership is a LOCKING read taken after the Organization locks (CP6 F-1), because a plain read earlier in the transaction (the links read to compute the
     * lock set) fixes a REPEATABLE READ snapshot that predates a membership removal the Organization lock waited for.
     *
     * @param  array<int, int|string>  $submitted  Organization ids
     *
     * @throws ValidationException an id is unknown or not linkable by the actor
     * @throws ConflictHttpException the links kept changing under it
     */
    public function syncOrganizations(Project $project, User $actor, array $submitted): void
    {
        $ids = $this->organizationIds($submitted);

        for ($attempt = 0; $attempt < self::RESTART_ATTEMPTS; $attempt++) {
            $settled = DB::transaction(function () use ($project, $actor, $ids): bool {
                $this->requireActiveActor($this->lockUsers([(int) $actor->getKey()]), (int) $actor->getKey());

                // From the TABLES, after the actor's row lock: an authority change committed before this read is seen (CP6 F-2).
                $unrestricted = $this->isUnrestricted((int) $actor->getKey());

                $linkedNow = DB::table('project_organization')->where('project_id', $project->getKey())->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
                $candidates = $this->organizationIds([...$ids, ...$linkedNow]);

                $present = $this->lockLinkableOrganizations($ids, (int) $actor->getKey(), $unrestricted, lock: $candidates);

                // The project row: the mutex for every synchronization of it.
                if (DB::table('projects')->where('id', $project->getKey())->lockForUpdate()->value('id') === null) {
                    throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
                }

                // Read UNDER the lock, as a locking read, so it sees the latest committed links and not this transaction's older snapshot.
                $existing = DB::table('project_organization')->where('project_id', $project->getKey())->sharedLock()->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
                $mine = $unrestricted ? $existing : $this->memberOrganizationIds((int) $actor->getKey(), $existing);
                $hidden = array_values(array_diff($existing, $mine));

                $target = $this->organizationIds([...$ids, ...$hidden]);
                $add = array_values(array_diff($target, $existing));
                $remove = array_values(array_diff($existing, $target));

                if (array_diff([...$add, ...$remove], $candidates) !== []) {
                    return false;   // an Organization outside the locked set is involved: start over
                }

                // CP8 (owner ruling B-2): only a NEW association is judged by the Organization's portal state. A link that is merely retained, and a removal, are not.
                $this->requireLinkable($add, $present, $unrestricted);

                if ($remove !== []) {
                    DB::table('project_organization')->where('project_id', $project->getKey())->whereIn('organization_id', $remove)->delete();
                }

                $this->insertLinks((int) $project->getKey(), $add);

                return true;
            }, self::LOCK_ATTEMPTS);

            if ($settled) {
                return;
            }
        }

        throw new ConflictHttpException('The linked organizations kept changing; try again.');
    }

    /**
     * Lock the given Organization rows SHARED, ascending, and require each of `$ids` to exist and (unless `$unrestricted`) to be one the actor belongs
     * to. `$lock` is the set actually locked (default `$ids`); it may be wider, for the existing links a synchronization may remove. A failure is ONE
     * validation message for an unknown id and for an Organization the actor cannot see alike. Returns the portal state of every row it locked, by id, read
     * from the locking statement itself (no further lock).
     *
     * @param  list<int>  $ids
     * @param  list<int>|null  $lock
     * @return array<int, PortalState>
     */
    private function lockLinkableOrganizations(array $ids, int $actorId, bool $unrestricted, ?array $lock = null): array
    {
        $lock = $this->organizationIds($lock ?? $ids);

        if ($lock === []) {
            return [];
        }

        $rows = DB::table('organizations')->whereIn('id', $lock)->orderBy('id')->sharedLock()->get(['id', ...PortalState::columns()]);
        $states = [];

        foreach ($rows as $row) {
            $states[(int) $row->id] = PortalState::of($row);
        }

        $present = array_keys($states);
        $linkable = $unrestricted ? $present : $this->memberOrganizationIds($actorId, $present);

        if (array_diff($ids, $linkable) !== []) {
            throw ValidationException::withMessages(['companies' => 'The selected organization is invalid.']);
        }

        return $states;
    }

    /**
     * Owner ruling B-2 (CP8): a Project association is business metadata and grants no portal authority, but anyone other than an unrestricted operator may
     * create a NEW one only to an ENABLED Organization. An operator may link any Organization (Disabled and Directory-only included). The refusal is the
     * same message an unknown or hidden id gets, so the state of an Organization is not a channel. Existing links are never judged here (the caller passes only
     * the ids being added), so disabling an Organization neither removes nor blocks the retention of a link.
     *
     * @param  list<int>  $newIds
     * @param  array<int, PortalState>  $states  the locked Organization rows
     */
    private function requireLinkable(array $newIds, array $states, bool $unrestricted): void
    {
        if ($unrestricted) {
            return;
        }

        foreach ($newIds as $id) {
            if (! isset($states[$id]) || ! $states[$id]->isEnabled()) {
                throw ValidationException::withMessages(['companies' => 'The selected organization is invalid.']);
            }
        }
    }

    /**
     * CP8: refuse an actor who is suspended (or no longer exists) as read from its own row, which the transaction has just locked shared, before any write.
     * `$locked` is the result of {@see lockUsers()}.
     *
     * @param  array<int, bool>  $locked  user id => whether the account is active
     *
     * @throws AuthorizationException
     */
    private function requireActiveActor(array $locked, int $actorId): void
    {
        if (($locked[$actorId] ?? false) !== true) {
            throw new AuthorizationException;
        }
    }

    /**
     * Of the given Organization ids, those the user belongs to (membership rows, read directly) as a LOCKING read, so it returns the latest COMMITTED membership and
     * not the REPEATABLE READ snapshot an earlier plain read of this transaction fixed (CP6 F-1).
     *
     * Which Organization rows the caller holds when it asks: `lockLinkableOrganizations` asks about the rows it has JUST locked shared (the Organization row is
     * held, so every writer of a membership row, which takes its Organization row first, is excluded). `syncOrganizations` asks again under the project row, about
     * the links it then read (`existing`). Those are the same rows unless another synchronization committed a link in between; for a link outside the locked set
     * the question is asked WITHOUT its Organization row. That is harmless: the answer only decides which links are preserved as hidden, and the attempt then
     * finds the link outside `$candidates` and starts over before it writes. The read can wait for a membership writer that holds such a row, but it holds no lock
     * that writer needs after the Organization row, so it adds no cycle of its own.
     * The Organization rows of the hidden links are among those locked (the lock set is the submitted ids plus every link read at the start), shared and in
     * ascending order like the rest; a hidden link is merely PRESERVED: its link row is read (it is part of `existing`) and is never written.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function memberOrganizationIds(int $userId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('organization_members')->where('user_id', $userId)->whereIn('organization_id', $ids)->orderBy('organization_id')->sharedLock()->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Lock the given user rows SHARED, ascending, in ONE statement, as the first thing a transaction here does. Ascending order is the order InnoDB takes the
     * records of one ordered index scan in; a test proves it from the engine (a probe on the middle row), it is not assumed from the ORDER BY.
     *
     * Returns, for each row found, whether the account is ACTIVE (`disabled_at` null), as that locking read saw it.
     *
     * @param  list<int>  $ids
     * @return array<int, bool>
     */
    private function lockUsers(array $ids): array
    {
        $active = [];

        foreach (DB::table('users')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'disabled_at']) as $row) {
            $active[(int) $row->id] = $row->disabled_at === null;
        }

        return $active;
    }

    /**
     * User ids as positive integers, unique, ascending (the lock order). Not-an-id values are dropped here: whether a member exists is the foreign key's
     * and the caller's validation's business, not a reason to lock a row that is not there.
     *
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function userIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        ksort($ids);

        return array_values($ids);
    }

    /**
     * Whether the actor is an unrestricted operator (holds `portal.operator`, directly or through a role), asked of the TABLES inside the transaction, after the
     * actor's row lock, never of the Gate or the permission cache. It is the same test as {@see CustomerPilotGate::isOperator()} (a drift test keeps them equal).
     * What it decides is whose links are the actor's to change: an operator's are all of them.
     */
    private function isUnrestricted(int $actorId): bool
    {
        return OrganizationAuthority::holdsOperatorCapability($actorId);
    }

    /** @param  list<int>  $ids */
    private function insertLinks(int $projectId, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $now = now();
        DB::table('project_organization')->insert(array_map(fn (int $id) => [
            'project_id' => $projectId, 'organization_id' => $id, 'created_at' => $now, 'updated_at' => $now,
        ], $ids));
    }

    /**
     * Organization ids as positive integers, unique, ASCENDING (the lock order). A value that is not an id fails the same way an unknown one does.
     *
     * @param  array<int|string, mixed>  $values
     * @return list<int>
     */
    private function organizationIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1) {
                throw ValidationException::withMessages(['companies' => 'The selected organization is invalid.']);
            }

            $ids[(int) $value] = (int) $value;
        }

        ksort($ids);

        return array_values($ids);
    }

    public function syncMembers(Project $project, array $memberData): void
    {
        // $memberData = [['user_id' => X, 'role' => 'member|manager'], ...]
        $sync = collect($memberData)->mapWithKeys(fn ($m) => [
            $m['user_id'] => ['role' => $m['role'] ?? 'member'],
        ])->all();

        $project->members()->sync($sync);

        // Ensure creator always stays as manager
        $project->members()->syncWithoutDetaching([
            $project->created_by => ['role' => 'manager'],
        ]);
    }

    // ── Tasks ────────────────────────────────────────────────────────────────

    /**
     * Append a task to the end of a column. The column row is the mutex for everything that
     * changes a column's order, so the new position is the true tail even when legacy rows
     * left gaps or duplicates (those are normalised while the lock is held).
     */
    public function createTask(Project $project, User $creator, array $data): Task
    {
        return DB::transaction(function () use ($project, $creator, $data) {
            $column = ProjectColumn::query()
                ->whereKey($data['column_id'])
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if ($column === null) {
                throw ValidationException::withMessages(['column_id' => 'The selected column is invalid.']);
            }

            $positions = $this->columnPositions($column->id);
            $this->writeOrder($column->id, array_keys($positions), $positions);

            return $project->tasks()->create([
                ...array_intersect_key($data, array_flip([
                    'column_id', 'title', 'description', 'assignee_id', 'milestone_id', 'priority', 'due_date',
                ])),
                'project_id' => $project->id,
                'created_by' => $creator->id,
                'position' => count($positions),
                'status' => 'todo',
            ]);
        }, self::LOCK_ATTEMPTS);
    }

    /**
     * Move a task to a column and index (0-based, clamped to the target's length). One
     * transaction: the source and target column rows are locked in ascending id order, both
     * lists are read in (position, id) order, spliced, and rewritten densely as 0..n-1. That
     * also repairs whatever gaps or duplicates older code left behind.
     *
     * Locks are only ever taken in one order: column rows (ascending id), then the task row.
     * The task's current column has to be read before that, so it can be stale; it is re-read
     * under the lock and the whole attempt starts over when it changed, rather than locking
     * another column out of order (which would invite a deadlock).
     *
     * @throws ModelNotFoundException when the task or the target column no longer exists
     * @throws ValidationException when the column belongs to another project
     */
    public function moveTask(Task $task, int $targetColumnId, int $position): void
    {
        $this->relocate($task, $targetColumnId, $position);
    }

    /**
     * Append a task to the tail of a column (EPIC-014 P3: Complete/Reopen), through exactly the
     * locking, restart and dense-order protocol of `moveTask`. `$unless` receives the task row as
     * read under the column and task locks; when it returns true the task is already where the
     * caller wants it, and nothing is written (no move, no reorder). That keeps an idempotent
     * Complete/Reopen decided under the same locks as the move itself.
     *
     * @param  (callable(Task): bool)|null  $unless
     * @return bool whether the task moved
     *
     * @throws ModelNotFoundException when the task or the target column no longer exists
     * @throws ValidationException when the column belongs to another project
     */
    public function appendToColumn(Task $task, int $targetColumnId, ?callable $unless = null): bool
    {
        return $this->relocate($task, $targetColumnId, PHP_INT_MAX, $unless);
    }

    /**
     * Delete a task and close the gap it leaves. Refused while any time entry references it
     * (D4); the foreign key is the backstop when a timer starts between the check and the
     * DELETE, and that violation is reported with the same message. Both rules live in
     * `RecordedTimeGuard`, which runs here under the column and task locks.
     *
     * @throws ValidationException on the "delete" key
     */
    public function deleteTask(Task $task): void
    {
        $this->untilStable(function () use ($task) {
            $columnId = $this->currentColumnId($task);

            return DB::transaction(function () use ($task, $columnId) {
                $this->lockColumns(array_filter([$columnId]));

                $current = Task::whereKey($task->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

                if ($current->column_id !== $columnId) {
                    return false;
                }

                $this->recordedTime->deleteTask($current);

                if ($columnId !== null) {
                    $positions = $this->columnPositions($columnId);
                    $this->writeOrder($columnId, array_keys($positions), $positions);
                }

                return true;
            }, self::LOCK_ATTEMPTS);
        });
    }

    /**
     * Delete a project. Refused while any time entry references the project or one of its
     * tasks (D4), for billed, invoiced, stopped and running entries alike (`RecordedTimeGuard`).
     *
     * @throws ValidationException on the "delete" key
     */
    public function deleteProject(Project $project): void
    {
        DB::transaction(fn () => $this->recordedTime->deleteProject($project), self::LOCK_ATTEMPTS);
    }

    /**
     * The project's single designated Done column (EPIC-014 Q2, INV-8): the destination of an
     * explicit Complete. Exactly one `is_done_column` is required; zero or several is a board
     * configuration error, reported rather than resolved by guessing (never the last column,
     * never a name match, never `tasks.status`). Read-only.
     *
     * @throws DoneColumnConfigurationException
     */
    public function doneColumn(Project $project): ProjectColumn
    {
        $columns = ProjectColumn::query()
            ->where('project_id', $project->id)
            ->where('is_done_column', true)
            ->get();

        if ($columns->count() !== 1) {
            throw new DoneColumnConfigurationException($project->id, $columns->count());
        }

        return $columns->first();
    }

    /**
     * The Reopen destination (EPIC-014 Q2): the project's first non-Done column by position,
     * ties broken by id. A board with no open column cannot reopen anything and is reported the
     * same way as a Done-column misconfiguration, never resolved by guessing. Read-only.
     *
     * @throws DoneColumnConfigurationException
     */
    public function firstOpenColumn(Project $project): ProjectColumn
    {
        return ProjectColumn::query()
            ->where('project_id', $project->id)
            ->where('is_done_column', false)
            ->orderBy('position')
            ->orderBy('id')
            ->first()
            ?? throw DoneColumnConfigurationException::forMissingOpenColumn(
                $project->id,
                ProjectColumn::where('project_id', $project->id)->where('is_done_column', true)->count(),
            );
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The one board move: `moveTask` and `appendToColumn` both run it. See `moveTask` for the
     * locking protocol. `$unless`, when given, is asked under the locks whether to leave the task
     * where it is.
     *
     * @param  (callable(Task): bool)|null  $unless
     * @return bool whether the task moved
     */
    private function relocate(Task $task, int $targetColumnId, int $position, ?callable $unless = null): bool
    {
        $moved = false;

        $this->untilStable(function () use ($task, $targetColumnId, $position, $unless, &$moved) {
            $sourceId = $this->currentColumnId($task);

            return DB::transaction(function () use ($task, $sourceId, $targetColumnId, $position, $unless, &$moved) {
                $locked = $this->lockColumns(array_filter([$sourceId, $targetColumnId]));
                $target = $locked[$targetColumnId] ?? throw (new ModelNotFoundException)->setModel(ProjectColumn::class, [$targetColumnId]);

                if ($target->project_id !== $task->project_id) {
                    throw ValidationException::withMessages(['column_id' => 'The selected column is invalid.']);
                }

                $current = Task::whereKey($task->id)->lockForUpdate()->first()
                    ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

                if ($current->column_id !== $sourceId) {
                    return false; // moved by someone else while this one waited: start over
                }

                if ($unless !== null && $unless($current)) {
                    $moved = false;

                    return true; // already where the caller wants it: nothing to write
                }

                // Everything below runs after the locks, so these reads see the latest committed
                // state (the snapshot only starts with the first plain read, which is this one).
                $targetPositions = $this->columnPositions($targetColumnId);

                if ($sourceId === $targetColumnId) {
                    $ids = array_values(array_diff(array_keys($targetPositions), [$task->id]));
                    array_splice($ids, max(0, min($position, count($ids))), 0, [$task->id]);
                    $this->writeOrder($targetColumnId, $ids, $targetPositions, $task->id, $sourceId);
                } else {
                    if ($sourceId !== null) {
                        $sourcePositions = $this->columnPositions($sourceId);
                        unset($sourcePositions[$task->id]);
                        $this->writeOrder($sourceId, array_keys($sourcePositions), $sourcePositions);
                    }

                    $ids = array_keys($targetPositions);
                    array_splice($ids, max(0, min($position, count($ids))), 0, [$task->id]);
                    $this->writeOrder($targetColumnId, $ids, $targetPositions, $task->id, $sourceId);
                }

                $moved = true;

                return true;
            }, self::LOCK_ATTEMPTS);
        });

        $task->refresh();

        return $moved;
    }

    /**
     * Run an attempt until it reports success. An attempt returns false when it noticed, under
     * its locks, that the task had moved since it looked; it then starts over from a fresh read.
     *
     * @param  callable(): bool  $attempt
     */
    private function untilStable(callable $attempt): void
    {
        for ($tries = 0; $tries < self::RESTART_ATTEMPTS; $tries++) {
            if ($attempt()) {
                return;
            }
        }

        throw new ConflictHttpException('The task kept moving while this request was processed. Please try again.');
    }

    /**
     * The task's column right now, read without a lock and outside any transaction of its own
     * (a plain read inside the locked section would pin an older snapshot). May be stale by the
     * time the locks are held; the caller re-checks.
     *
     * @throws ModelNotFoundException when the task no longer exists
     */
    private function currentColumnId(Task $task): ?int
    {
        $row = Task::whereKey($task->id)->first(['id', 'column_id'])
            ?? throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);

        return $row->column_id;
    }

    /**
     * Lock column rows in ascending id order, the one order every writer uses.
     *
     * @param  array<int, int>  $ids
     * @return array<int, ProjectColumn> keyed by id
     */
    private function lockColumns(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        return ProjectColumn::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /** @return array<int, int> task id => stored position, in board order (position, id) */
    private function columnPositions(int $columnId): array
    {
        return Task::where('column_id', $columnId)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('position', 'id')
            ->map(fn ($position) => (int) $position)
            ->all();
    }

    /**
     * Make a column read exactly $orderedIds as positions 0..n-1, writing only the rows whose
     * position differs. The moved task, if any, also takes the column and a fresh updated_at.
     *
     * @param  array<int, int>  $orderedIds
     * @param  array<int, int>  $current  task id => stored position (before the change)
     * @param  int|null  $movedFromColumnId  the column the moved task was in (under the lock)
     */
    private function writeOrder(int $columnId, array $orderedIds, array $current, ?int $movedId = null, ?int $movedFromColumnId = null): void
    {
        $changes = [];
        foreach ($orderedIds as $index => $id) {
            if ($id !== $movedId && ($current[$id] ?? null) !== $index) {
                $changes[$id] = $index;
            }
        }

        foreach (array_chunk($changes, 200, true) as $chunk) {
            $cases = str_repeat(' WHEN ? THEN ?', count($chunk));
            $bindings = [];
            foreach ($chunk as $id => $index) {
                array_push($bindings, $id, $index);
            }
            DB::update(
                "UPDATE `tasks` SET `position` = CASE `id`{$cases} END WHERE `id` IN (".implode(',', array_fill(0, count($chunk), '?')).')',
                [...$bindings, ...array_keys($chunk)],
            );
        }

        if ($movedId !== null) {
            $index = array_search($movedId, $orderedIds, true);

            if (($current[$movedId] ?? null) !== $index || $movedFromColumnId !== $columnId) {
                Task::whereKey($movedId)->update([
                    'column_id' => $columnId,
                    'position' => $index,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
