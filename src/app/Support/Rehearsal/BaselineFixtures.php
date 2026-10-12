<?php

namespace App\Support\Rehearsal;

use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * The deterministic records a rehearsal starts from, written against the CURRENT committed schema (it does
 * not use any column a candidate migration introduces; `users.disabled_at`, for one, does not exist yet).
 *
 * Every id, timestamp and value is fixed, so two runs produce byte-identical rows and file contents, and a
 * difference between snapshots is always a difference in the migration, never in the fixture. Rows are
 * inserted directly, not through models or services, so no event, observer, queued notification, mail or
 * activity-log write can fire, and the data does not depend on the application's behaviour at the time.
 *
 * It is a small set that nevertheless covers each kind of record the Release 0.9 data contract names, and
 * only relationships the application and schema actually support: for example it creates no ticket-derived
 * task (dormant, no code path produces one) and writes no `tickets.company_id` or `projects.client_id`
 * (nothing writes them). Financial records exist to prove they are PRESERVED; the harness does not enable
 * billing. Nothing here is a real person, a real credential or a usable secret: addresses are `.test`,
 * password hashes and token values are inert placeholders.
 *
 * Run it only inside {@see RehearsalDatabase::within()}: it writes to the application's default connection.
 */
final class BaselineFixtures
{
    private const USER = 'App\\Models\\User';

    /** The one wall-clock instant every timestamp is derived from. */
    private const EPOCH = '2026-01-15 09:00:00';

    /** An inert bcrypt-shaped string: 60 characters, not a hash of any real password. */
    private const PASSWORD = '$2y$04$rehearsal.fixture.placeholder.not.a.real.hash.0000000000';

    /** @var array<string, int> */
    private array $inventory = [];

    public function __construct(private readonly RehearsalTarget $target) {}

    /**
     * Seed the baseline and return the per-table row counts it wrote.
     *
     * @return array<string, int>
     */
    public function seed(): array
    {
        DB::transaction(function () {
            $this->accounts();
            $this->roles();
            $this->directory();
            $this->helpdesk();
            $this->projects();
            $this->finance();
            $this->time();
            $this->audit();
        });

        $this->attachments();

        ksort($this->inventory);

        return $this->inventory;
    }

    private function at(int $days = 0, int $minutes = 0): string
    {
        return date('Y-m-d H:i:s', strtotime(self::EPOCH) + $days * 86400 + $minutes * 60);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(string $table, array $rows): void
    {
        DB::table($table)->insert($rows);
        $this->inventory[$table] = ($this->inventory[$table] ?? 0) + count($rows);
    }

    private function user(int $id, string $name, string $email, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
            'password' => self::PASSWORD,
            'remember_token' => null,
            'created_at' => $this->at(0, $id),
            'updated_at' => $this->at(0, $id),
            'invitation_id' => null,
            'invited_by' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ], $extra);
    }

    private function accounts(): void
    {
        // 1 operator · 2 support agent (custom role) · 3 customer · 4 SSO-only customer · 5 invited customer
        // with two-factor fields set (inert placeholders).
        $this->insert('users', [
            $this->user(1, 'Rehearsal Operator', 'operator@rehearsal.test'),
            $this->user(2, 'Rehearsal Support Agent', 'agent@rehearsal.test', ['invited_by' => 1]),
            $this->user(3, 'Rehearsal Customer A', 'customer-a@rehearsal.test', ['invited_by' => 1]),
            $this->user(4, 'Rehearsal Customer B (SSO)', 'customer-b@rehearsal.test', ['password' => null, 'invited_by' => 1]),
            $this->user(5, 'Rehearsal Customer C', 'customer-c@rehearsal.test', [
                'invited_by' => 1,
                'two_factor_secret' => 'REHEARSAL-INERT-PLACEHOLDER',
                'two_factor_recovery_codes' => 'REHEARSAL-INERT-PLACEHOLDER',
                'two_factor_confirmed_at' => $this->at(1),
            ]),
        ]);

        $this->insert('invitations', [
            ['id' => 1, 'email' => 'invitee-pending@rehearsal.test', 'token' => hash('sha256', 'rehearsal-invitation-1'), 'invited_by' => 1, 'status' => 'pending', 'expires_at' => $this->at(400), 'accepted_at' => null, 'created_at' => $this->at(2), 'updated_at' => $this->at(2)],
            ['id' => 2, 'email' => 'customer-c@rehearsal.test', 'token' => hash('sha256', 'rehearsal-invitation-2'), 'invited_by' => 1, 'status' => 'accepted', 'expires_at' => $this->at(3), 'accepted_at' => $this->at(1), 'created_at' => $this->at(0, 30), 'updated_at' => $this->at(1)],
            ['id' => 3, 'email' => 'invitee-expired@rehearsal.test', 'token' => hash('sha256', 'rehearsal-invitation-3'), 'invited_by' => 1, 'status' => 'expired', 'expires_at' => $this->at(-10), 'accepted_at' => null, 'created_at' => $this->at(-12), 'updated_at' => $this->at(-10)],
        ]);
        DB::table('users')->where('id', 5)->update(['invitation_id' => 2]);

        $this->insert('social_accounts', [
            ['id' => 1, 'user_id' => 4, 'provider' => 'google', 'provider_id' => 'rehearsal-google-subject-4', 'token' => 'REHEARSAL-INERT-PLACEHOLDER', 'refresh_token' => null, 'token_expires_at' => $this->at(30), 'created_at' => $this->at(0, 4), 'updated_at' => $this->at(0, 4)],
        ]);
        $this->insert('password_histories', [
            ['id' => 1, 'user_id' => 3, 'password' => self::PASSWORD, 'created_at' => $this->at(0, 3)],
        ]);
    }

    private function roles(): void
    {
        $all = PermissionCatalogue::all();
        $this->insert('permissions', array_map(
            fn (string $name, int $i) => ['id' => $i + 1, 'name' => $name, 'guard_name' => 'web', 'created_at' => $this->at(), 'updated_at' => $this->at()],
            $all,
            array_keys($all)
        ));
        $id = array_flip($all);
        $permissionId = fn (string $name) => $id[$name] + 1;

        $this->insert('roles', [
            ['id' => 1, 'name' => 'operator', 'guard_name' => 'web', 'created_at' => $this->at(), 'updated_at' => $this->at()],
            ['id' => 2, 'name' => 'user', 'guard_name' => 'web', 'created_at' => $this->at(), 'updated_at' => $this->at()],
            ['id' => 3, 'name' => 'support-agent', 'guard_name' => 'web', 'created_at' => $this->at(), 'updated_at' => $this->at()],
        ]);

        $agent = array_values(array_intersect($all, [
            PermissionCatalogue::TICKETS_VIEW, PermissionCatalogue::TICKETS_CREATE, PermissionCatalogue::TICKETS_ASSIGN,
            PermissionCatalogue::PROJECTS_VIEW, PermissionCatalogue::TIME_LOG, PermissionCatalogue::TIME_VIEW_OWN,
        ]));

        $grants = [];
        foreach ([1 => $all, 2 => PermissionCatalogue::userDefaults(), 3 => $agent] as $role => $names) {
            foreach ($names as $name) {
                $grants[] = ['permission_id' => $permissionId($name), 'role_id' => $role];
            }
        }
        $this->insert('role_has_permissions', $grants);

        $this->insert('model_has_roles', [
            ['role_id' => 1, 'model_type' => self::USER, 'model_id' => 1],
            ['role_id' => 3, 'model_type' => self::USER, 'model_id' => 2],
            ['role_id' => 2, 'model_type' => self::USER, 'model_id' => 3],
            ['role_id' => 2, 'model_type' => self::USER, 'model_id' => 4],
            ['role_id' => 2, 'model_type' => self::USER, 'model_id' => 5],
        ]);
        // A customer with one extra capability granted directly (the "operational member" shape).
        $this->insert('model_has_permissions', [
            ['permission_id' => $permissionId(PermissionCatalogue::PROJECTS_MANAGE), 'model_type' => self::USER, 'model_id' => 4],
        ]);
    }

    private function directory(): void
    {
        $this->insert('organizations', [
            ['id' => 1, 'name' => 'Rehearsal Organization', 'slug' => 'rehearsal-organization', 'owner_id' => 1, 'created_at' => $this->at(0, 10), 'updated_at' => $this->at(0, 10)],
        ]);
        $this->insert('organization_members', [
            ['id' => 1, 'organization_id' => 1, 'user_id' => 1, 'role' => 'admin', 'created_at' => $this->at(0, 10), 'updated_at' => $this->at(0, 10)],
            ['id' => 2, 'organization_id' => 1, 'user_id' => 3, 'role' => 'member', 'created_at' => $this->at(0, 11), 'updated_at' => $this->at(0, 11)],
        ]);
        $this->insert('crm_companies', [
            ['id' => 1, 'name' => 'Acme Rehearsal Ltd', 'website' => 'https://acme.rehearsal.test', 'phone' => null, 'address' => null, 'notes' => 'Linked to the organization.', 'organization_id' => 1, 'created_by' => 1, 'created_at' => $this->at(0, 12), 'updated_at' => $this->at(0, 12)],
            ['id' => 2, 'name' => 'Solo Rehearsal', 'website' => null, 'phone' => null, 'address' => null, 'notes' => null, 'organization_id' => null, 'created_by' => 1, 'created_at' => $this->at(0, 13), 'updated_at' => $this->at(0, 13)],
        ]);
        $this->insert('crm_contacts', [
            ['id' => 1, 'crm_company_id' => 1, 'first_name' => 'Casey', 'last_name' => 'Contact', 'email' => 'casey@acme.rehearsal.test', 'phone' => null, 'job_title' => 'Buyer', 'notes' => null, 'created_by' => 1, 'created_at' => $this->at(0, 14), 'updated_at' => $this->at(0, 14)],
            ['id' => 2, 'crm_company_id' => null, 'first_name' => 'Robin', 'last_name' => 'Unattached', 'email' => null, 'phone' => null, 'job_title' => null, 'notes' => null, 'created_by' => 1, 'created_at' => $this->at(0, 15), 'updated_at' => $this->at(0, 15)],
        ]);
        $this->insert('cms_pages', [
            ['id' => 1, 'slug' => 'rehearsal-draft', 'title' => 'Rehearsal draft', 'body' => 'Draft body.', 'status' => 'draft', 'published_at' => null, 'created_by' => 1, 'updated_by' => null, 'created_at' => $this->at(0, 16), 'updated_at' => $this->at(0, 16)],
            ['id' => 2, 'slug' => 'rehearsal-published', 'title' => 'Rehearsal published', 'body' => 'Published body.', 'status' => 'published', 'published_at' => $this->at(1), 'created_by' => 1, 'updated_by' => 1, 'created_at' => $this->at(0, 17), 'updated_at' => $this->at(1)],
        ]);
    }

    /** The five statuses, public replies, internal notes, histories, and attachment ROWS (files follow). */
    private function helpdesk(): void
    {
        $ticket = fn (int $id, int $owner, ?int $assignee, string $title, string $priority, string $status, array $extra = []) => array_merge([
            'id' => $id,
            'ticket_number' => 'TKT-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT),
            'user_id' => $owner,
            'company_id' => null,
            'assignee_id' => $assignee,
            'title' => $title,
            'description' => "Description of \"{$title}\".\nSecond line, with “quotes” and ünïcode.",
            'category' => 'Technical',
            'priority' => $priority,
            'status' => $status,
            'sla_due_at' => null,
            'resolved_at' => null,
            'closed_at' => null,
            'created_at' => $this->at(2, $id),
            'updated_at' => $this->at(2, $id + 60),
        ], $extra);

        $this->insert('tickets', [
            $ticket(1, 3, 2, 'Printer offline', 'medium', 'open'),
            $ticket(2, 3, 2, 'Need the invoice address changed', 'high', 'pending_user', ['category' => 'Billing']),
            $ticket(3, 4, 1, 'Cannot sign in with Google', 'low', 'resolved', ['category' => 'Account', 'resolved_at' => $this->at(3)]),
            $ticket(4, 4, 1, 'Old question, since closed', 'medium', 'closed', ['category' => 'General', 'resolved_at' => $this->at(3), 'closed_at' => $this->at(6)]),
            $ticket(5, 5, 2, 'Export fails on large projects', 'critical', 'in_progress', ['sla_due_at' => $this->at(4)]),
        ]);

        $reply = fn (int $id, int $ticket, int $user, string $body, bool $internal) => [
            'id' => $id, 'ticket_id' => $ticket, 'user_id' => $user, 'body' => $body, 'is_internal' => $internal,
            'created_at' => $this->at(2, 100 + $id), 'updated_at' => $this->at(2, 100 + $id),
        ];

        $this->insert('ticket_replies', [
            $reply(1, 1, 3, 'It stopped printing after the update.', false),
            $reply(2, 1, 1, 'Can you share the printer model?', false),
            $reply(3, 1, 1, 'Internal: likely the driver; do not tell the customer yet.', true),
            $reply(4, 2, 1, 'Please confirm the new address.', false),
            $reply(5, 3, 1, 'Fixed on our side; please try again.', false),
            $reply(6, 5, 2, 'Internal: reproduced with the 10k-row sample.', true),
        ]);

        $this->insert('ticket_status_histories', [
            ['id' => 1, 'ticket_id' => 2, 'user_id' => 1, 'old_status' => 'open', 'new_status' => 'in_progress', 'created_at' => $this->at(2, 200)],
            ['id' => 2, 'ticket_id' => 2, 'user_id' => 1, 'old_status' => 'in_progress', 'new_status' => 'pending_user', 'created_at' => $this->at(2, 201)],
            ['id' => 3, 'ticket_id' => 3, 'user_id' => 1, 'old_status' => 'open', 'new_status' => 'resolved', 'created_at' => $this->at(3)],
            ['id' => 4, 'ticket_id' => 4, 'user_id' => null, 'old_status' => 'resolved', 'new_status' => 'closed', 'created_at' => $this->at(6)],
            ['id' => 5, 'ticket_id' => 5, 'user_id' => 2, 'old_status' => 'open', 'new_status' => 'in_progress', 'created_at' => $this->at(2, 202)],
        ]);

        // The rows only; attachments() writes the files they point at.
        $row = fn (int $id, int $ticket, ?int $reply, int $user, string $name) => [
            'id' => $id, 'ticket_id' => $ticket, 'reply_id' => $reply, 'user_id' => $user,
            'filename' => $name,
            'path' => "tickets/{$ticket}/rehearsal-{$id}.txt",
            'mime_type' => 'text/plain',
            'size' => strlen($this->attachmentBody($id)),
            'created_at' => $this->at(2, 300 + $id), 'updated_at' => $this->at(2, 300 + $id),
        ];

        $this->insert('ticket_attachments', [
            $row(1, 1, null, 3, 'printer-settings.txt'),
            $row(2, 1, 3, 1, 'internal-driver-notes.txt'),
            $row(3, 2, 4, 1, 'address-form.txt'),
            $row(4, 5, null, 5, 'sample-export.txt'),
        ]);
    }

    private function attachmentBody(int $id): string
    {
        return "Rehearsal attachment {$id}\n".str_repeat("deterministic line {$id}\n", $id * 3);
    }

    /** The private files the attachment rows name, written to the rehearsal root, never the real disk. */
    private function attachments(): void
    {
        $disk = RehearsalFiles::disk($this->target->filesRoot());

        foreach (DB::table('ticket_attachments')->orderBy('id')->get(['id', 'path']) as $row) {
            $disk->put($row->path, $this->attachmentBody((int) $row->id));
        }
    }

    private function projects(): void
    {
        $this->insert('projects', [
            ['id' => 1, 'name' => 'Rehearsal Portal Build', 'description' => 'A project with members, tasks and milestones.', 'created_by' => 1, 'client_id' => null, 'start_date' => '2026-01-20', 'target_date' => '2026-04-30', 'status' => 'active', 'budget' => '12500.00', 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
            ['id' => 2, 'name' => 'Archived Pilot', 'description' => null, 'created_by' => 1, 'client_id' => null, 'start_date' => null, 'target_date' => null, 'status' => 'archived', 'budget' => null, 'created_at' => $this->at(5, 1), 'updated_at' => $this->at(6)],
        ]);

        // The board the application creates for every project: five columns, exactly one marked Done.
        $columns = [];
        foreach ([1 => 0, 2 => 5] as $project => $firstId) {
            foreach (['Backlog', 'To Do', 'In Progress', 'In Review', 'Done'] as $position => $name) {
                $columns[] = ['id' => $firstId + $position + 1, 'project_id' => $project, 'name' => $name, 'position' => $position, 'is_done_column' => $name === 'Done', 'created_at' => $this->at(5), 'updated_at' => $this->at(5)];
            }
        }
        $this->insert('project_columns', $columns);

        $this->insert('project_members', [
            ['project_id' => 1, 'user_id' => 1, 'role' => 'manager', 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
            ['project_id' => 1, 'user_id' => 2, 'role' => 'member', 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
            ['project_id' => 1, 'user_id' => 3, 'role' => 'member', 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
            ['project_id' => 2, 'user_id' => 1, 'role' => 'manager', 'created_at' => $this->at(5, 1), 'updated_at' => $this->at(5, 1)],
        ]);
        $this->insert('project_company', [
            ['project_id' => 1, 'crm_company_id' => 1, 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
        ]);

        $this->insert('project_milestones', [
            ['id' => 1, 'project_id' => 1, 'name' => 'Pilot launch', 'due_date' => '2026-03-01', 'description' => 'Open milestone.', 'completed_at' => null, 'completed_by' => null, 'created_at' => $this->at(5), 'updated_at' => $this->at(5)],
            ['id' => 2, 'project_id' => 1, 'name' => 'Kickoff', 'due_date' => '2026-01-25', 'description' => null, 'completed_at' => $this->at(10), 'completed_by' => 1, 'created_at' => $this->at(5), 'updated_at' => $this->at(10)],
        ]);

        $task = fn (int $id, ?int $project, ?int $column, ?int $milestone, ?int $assignee, string $title, string $status, string $priority, int $position) => [
            'id' => $id, 'project_id' => $project, 'ticket_id' => null, 'column_id' => $column, 'milestone_id' => $milestone,
            'assignee_id' => $assignee, 'created_by' => 1, 'title' => $title, 'description' => "About \"{$title}\".",
            'due_date' => '2026-02-20', 'priority' => $priority, 'position' => $position, 'status' => $status,
            'created_at' => $this->at(6, $id), 'updated_at' => $this->at(6, $id),
        ];

        // Three board tasks (project, column and milestone agree) and one standalone task.
        $this->insert('tasks', [
            $task(1, 1, 2, 1, 2, 'Set up the pilot environment', 'todo', 'high', 0),
            $task(2, 1, 5, 2, 3, 'Kickoff call', 'done', 'medium', 0),
            $task(3, 1, 3, null, 2, 'Import the customer list', 'in_progress', 'medium', 0),
            $task(4, null, null, null, 1, 'Renew the domain', 'todo', 'low', 0),
        ]);
        $this->insert('task_dependencies', [['task_id' => 3, 'depends_on_task_id' => 1]]);
        $this->insert('task_checklist_items', [
            ['id' => 1, 'task_id' => 1, 'title' => 'Provision database', 'completed' => false, 'position' => 0, 'created_at' => $this->at(6), 'updated_at' => $this->at(6)],
            ['id' => 2, 'task_id' => 1, 'title' => 'Configure mail', 'completed' => false, 'position' => 1, 'created_at' => $this->at(6), 'updated_at' => $this->at(6)],
            ['id' => 3, 'task_id' => 3, 'title' => 'Collect the CSV', 'completed' => true, 'position' => 0, 'created_at' => $this->at(6), 'updated_at' => $this->at(7)],
        ]);
        $this->insert('task_comments', [
            ['id' => 1, 'task_id' => 1, 'user_id' => 1, 'body' => 'Start with the database.', 'created_at' => $this->at(6, 30), 'updated_at' => $this->at(6, 30)],
            ['id' => 2, 'task_id' => 1, 'user_id' => 2, 'body' => 'On it.', 'created_at' => $this->at(6, 40), 'updated_at' => $this->at(6, 40)],
        ]);
    }

    /** Retained, not enabled: invoices exist so a migration that damages them is caught. */
    private function finance(): void
    {
        $this->insert('invoices', [
            ['id' => 1, 'invoice_number' => 'INV-0001', 'client_id' => 3, 'created_by' => 1, 'project_id' => 1, 'issued_at' => '2026-02-01', 'due_at' => '2026-03-03', 'paid_at' => $this->at(20), 'sent_at' => $this->at(17), 'subtotal' => '300.00', 'tax_rate' => '10.00', 'tax_amount' => '30.00', 'total' => '330.00', 'currency' => 'USD', 'status' => 'paid', 'stripe_payment_intent_id' => null, 'notes' => null, 'created_at' => $this->at(16), 'updated_at' => $this->at(20)],
            ['id' => 2, 'invoice_number' => 'INV-0002', 'client_id' => 4, 'created_by' => 1, 'project_id' => null, 'issued_at' => '2026-02-10', 'due_at' => '2026-03-12', 'paid_at' => null, 'sent_at' => null, 'subtotal' => '50.00', 'tax_rate' => '0.00', 'tax_amount' => '0.00', 'total' => '50.00', 'currency' => 'USD', 'status' => 'draft', 'stripe_payment_intent_id' => null, 'notes' => null, 'created_at' => $this->at(25), 'updated_at' => $this->at(25)],
        ]);
        $this->insert('invoice_items', [
            ['id' => 1, 'invoice_id' => 1, 'description' => 'Design', 'quantity' => '2.00', 'unit_price' => '100.00', 'amount' => '200.00', 'position' => 1, 'created_at' => $this->at(16), 'updated_at' => $this->at(16)],
            ['id' => 2, 'invoice_id' => 1, 'description' => 'Build', 'quantity' => '1.00', 'unit_price' => '100.00', 'amount' => '100.00', 'position' => 2, 'created_at' => $this->at(16), 'updated_at' => $this->at(16)],
            ['id' => 3, 'invoice_id' => 2, 'description' => 'Consultation', 'quantity' => '1.00', 'unit_price' => '50.00', 'amount' => '50.00', 'position' => 1, 'created_at' => $this->at(25), 'updated_at' => $this->at(25)],
        ]);
        $this->insert('invoice_payments', [
            ['id' => 1, 'invoice_id' => 1, 'amount' => '330.00', 'currency' => 'USD', 'stripe_payment_intent_id' => null, 'stripe_charge_id' => null, 'method' => 'manual', 'notes' => 'Bank transfer.', 'paid_at' => $this->at(20), 'created_at' => $this->at(20), 'updated_at' => $this->at(20)],
        ]);
    }

    /** One context per entry; running, stopped, ticket, task, project and billed, with 15-minute blocks. */
    private function time(): void
    {
        $entry = fn (int $id, int $user, array $context, string $date, int $minutes, string $description, bool $billable, bool $billed, ?int $invoice, ?string $running, ?string $stopped) => array_merge(
            ['project_id' => null, 'task_id' => null, 'ticket_id' => null],
            $context,
            [
                'id' => $id, 'user_id' => $user, 'invoice_id' => $invoice, 'date' => $date, 'duration_minutes' => $minutes,
                'description' => $description, 'billable' => $billable, 'billed' => $billed,
                'timer_started_at' => $running, 'stopped_at' => $stopped,
                'created_at' => $this->at(8, $id), 'updated_at' => $this->at(8, $id),
            ]
        );

        $this->insert('time_entries', [
            $entry(1, 1, ['project_id' => 1], '2026-02-02', 60, 'Billed design work', true, true, 1, null, '2026-02-02 11:00:00'),
            $entry(2, 1, ['task_id' => 1], '2026-02-03', 30, 'Environment setup', true, false, null, null, '2026-02-03 09:30:00'),
            $entry(3, 2, ['ticket_id' => 1], '2026-02-04', 45, 'Ticket work', false, false, null, null, '2026-02-04 10:00:00'),
            $entry(4, 3, ['project_id' => 1], '2026-02-05', 0, 'Running timer', false, false, null, '2026-02-05 08:00:00', null),
            $entry(5, 1, ['task_id' => 3], '2026-02-03', 15, 'Shares a slot with entry 2', false, false, null, null, '2026-02-03 09:15:00'),
        ]);

        $block = fn (int $id, int $entry, int $user, string $date, int $number, string $pct) => [
            'id' => $id, 'time_entry_id' => $entry, 'user_id' => $user, 'block_date' => $date, 'block_number' => $number,
            'allocation_pct' => $pct, 'is_overridden' => false, 'created_at' => $this->at(8, $id), 'updated_at' => $this->at(8, $id),
        ];

        $this->insert('time_entry_blocks', [
            $block(1, 1, 1, '2026-02-02', 36, '100.00'), $block(2, 1, 1, '2026-02-02', 37, '100.00'),
            $block(3, 1, 1, '2026-02-02', 38, '100.00'), $block(4, 1, 1, '2026-02-02', 39, '100.00'),
            // Entries 2 and 5 occupy the same user/date/slot 36, so each holds half of it.
            $block(5, 2, 1, '2026-02-03', 36, '50.00'), $block(6, 2, 1, '2026-02-03', 37, '100.00'),
            $block(7, 5, 1, '2026-02-03', 36, '50.00'),
            $block(8, 3, 2, '2026-02-04', 40, '100.00'), $block(9, 3, 2, '2026-02-04', 41, '100.00'),
            $block(10, 3, 2, '2026-02-04', 42, '100.00'),
        ]);
    }

    private function audit(): void
    {
        $this->insert('activity_log', [
            ['id' => 1, 'log_name' => 'default', 'description' => 'created', 'subject_type' => 'App\\Models\\Ticket', 'event' => 'created', 'subject_id' => 1, 'causer_type' => self::USER, 'causer_id' => 3, 'properties' => '{"attributes":{"status":"open"}}', 'batch_uuid' => null, 'created_at' => $this->at(2, 1), 'updated_at' => $this->at(2, 1)],
            ['id' => 2, 'log_name' => 'default', 'description' => 'updated', 'subject_type' => 'App\\Models\\Project', 'event' => 'updated', 'subject_id' => 1, 'causer_type' => self::USER, 'causer_id' => 1, 'properties' => '{"attributes":{"status":"active"}}', 'batch_uuid' => null, 'created_at' => $this->at(5, 2), 'updated_at' => $this->at(5, 2)],
            ['id' => 3, 'log_name' => 'default', 'description' => 'roles synced', 'subject_type' => self::USER, 'event' => 'updated', 'subject_id' => 3, 'causer_type' => self::USER, 'causer_id' => 1, 'properties' => null, 'batch_uuid' => null, 'created_at' => $this->at(0, 20), 'updated_at' => $this->at(0, 20)],
        ]);
    }
}
