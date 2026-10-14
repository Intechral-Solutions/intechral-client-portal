<?php

namespace App\Console\Commands;

use App\Services\CustomerSurfacePreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customer-surface:preflight {--expect-mode= : Fail unless the application resolved this customer surface mode (closed, pilot or legacy)} {--strict : Treat accounts and roles awaiting an eligibility decision as failures} {--json : Print the report as JSON}')]
#[Description('Read-only readiness report for the customer-surface boundary: the resolved mode, portal.operator provisioning, and accounts or custom roles that hold operational capabilities without it (Release 0.9, R0.9-CS1)')]
class CustomerSurfacePreflightCommand extends Command
{
    public function handle(CustomerSurfacePreflight $preflight): int
    {
        $report = $preflight->report($this->option('expect-mode') ?: null);
        $failed = $report['failures'] !== [] || ($this->option('strict') && $report['warnings'] !== []);

        if ($this->option('json')) {
            $this->line(json_encode($report + ['passed' => ! $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Customer surface mode: '.$report['mode']);
        $this->line('Accounts holding portal.operator: '.$report['operators']);

        foreach ($report['failures'] as $failure) {
            $this->components->error($failure);
        }

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($report['unresolvedUsers'] as $user) {
            $this->line(sprintf('  account #%d %s  roles: %s  capabilities: %s', $user['id'], $user['email'], implode(', ', $user['roles']) ?: '(direct grants)', implode(', ', $user['capabilities'])));
        }

        foreach ($report['unresolvedRoles'] as $role) {
            $this->line(sprintf('  custom role "%s" (%d member(s))  capabilities: %s', $role['name'], $role['members'], implode(', ', $role['capabilities'])));
        }

        if (! $failed && $report['failures'] === []) {
            $this->components->info('No readiness failures. Nothing was written.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
