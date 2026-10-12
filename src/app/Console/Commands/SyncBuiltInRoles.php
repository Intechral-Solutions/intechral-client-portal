<?php

namespace App\Console\Commands;

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\RoleSyncRefused;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\RoleSyncPlan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('roles:sync {--apply : Apply a reviewed plan (default: dry run, nothing is written)} {--plan= : Fingerprint of the plan reviewed in the dry run (required with --apply)} {--allow-revocations : Approve the plan\'s permission revocations} {--json : Print the plan as JSON}')]
#[Description('Preview or apply the synchronization of the built-in roles (operator, user) with the code that owns them (Release 0.9, H4)')]
class SyncBuiltInRoles extends Command
{
    public function handle(BuiltInRoleSynchronizer $synchronizer): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply && ($this->option('plan') !== null || $this->option('allow-revocations'))) {
            $this->components->error('--plan and --allow-revocations only apply together with --apply. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            if (! $apply) {
                $plan = $synchronizer->plan();
                $this->render($plan);
                if (! $this->option('json')) {
                    $this->line('Dry run: nothing was written.'.($plan->isEmpty() ? '' : ' To apply after review: php artisan roles:sync --apply --plan='
                        .$plan->fingerprint()));
                    if ($plan->hasRevocations()) {
                        // Never suggested as part of the command: approving revocations is a separate, deliberate act.
                        $this->components->warn('Applying this plan requires explicit --allow-revocations approval.');
                    }
                }

                return self::SUCCESS;
            }

            $reviewed = $this->option('plan');
            if (! is_string($reviewed) || $reviewed === '') {
                $this->components->error('--apply needs --plan=<fingerprint> from a dry run you reviewed. Nothing was changed.');

                return self::FAILURE;
            }

            $plan = $synchronizer->apply($reviewed, (bool) $this->option('allow-revocations'));
            $this->render($plan);
            if (! $this->option('json')) {
                $this->components->info($plan->isEmpty() ? 'Already in sync; nothing to apply.' : 'Applied plan '.$plan->fingerprint().'.');
            }

            return self::SUCCESS;
        } catch (RoleSyncRefused|LastAccountAdministratorException $refusal) {
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        }
    }

    private function render(RoleSyncPlan $plan): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('Plan '.$plan->fingerprint().($plan->isEmpty() ? ' (built-in roles are in sync)' : ''));
        foreach ($plan->actions as $action) {
            $this->line('  '.$plan->describe($action));
        }
        if ($plan->hasRevocations()) {
            $this->components->warn(count($plan->revocations()).' revocation(s): these remove access that the roles hold now.');
        }
        $this->line('Active account administrators now:   ['.implode(', ', $plan->administratorsBefore).']');
        $this->line('Active account administrators after: ['.implode(', ', $plan->administratorsAfter).']'
            .($plan->administratorsChange() ? '  (CHANGES)' : ''));
        if ($plan->stalePermissions !== []) {
            $this->line('Stored permissions no built-in role defines (reported, never changed): '.implode(', ', $plan->stalePermissions));
        }
    }
}
