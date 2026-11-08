<?php

namespace App\Console\Commands;

use App\Services\OrganizationPreflight;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('organizations:preflight {--json : Print the report as JSON}')]
#[Description('Read-only report on organization, company and membership data ahead of the managed-organization migrations: duplicate company links, organizations without a company, admin and operator-held admin memberships, the administrator-approval inventory, multi-organization users, attributed tickets (Release 1.0, MO-1A, MO-2A1)')]
class OrganizationPreflightCommand extends Command
{
    public function handle(OrganizationPreflight $preflight): int
    {
        $report = $preflight->report();
        $failed = $report['failures'] !== [];

        if ($this->option('json')) {
            $this->line(json_encode($report + ['passed' => ! $failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->line("Organizations: {$report['organizations']}   CRM companies: {$report['companies']}   Companies without an organization: {$report['companiesWithoutOrganization']}");
        $this->line('Tickets with a company attribution: '.$report['attributedTickets']['tickets'].' (across '.$report['attributedTickets']['companies'].' company/companies)');

        if ($report['relationships'] === null) {
            $this->line('Managed relationships: not migrated yet (the MO-1A columns do not exist).');
        } else {
            $this->line("Managed relationships: {$report['relationships']['partners']} partner(s), {$report['relationships']['managed']} managed organization(s)");
        }

        if ($report['adminApproval'] === null) {
            $this->line('Administrator approval: not migrated yet (the MO-2A1 columns do not exist).');
        } else {
            $a = $report['adminApproval'];
            $this->line(sprintf(
                'Administrator approval: %d unapproved, %d approved and active, %d approved but suspended, %d operator-held; %d organization(s) without an approved administrator',
                count($a['unapprovedAdministrators']), count($a['approvedActiveCustomerAdministrators']), count($a['approvedSuspendedAdministrators']),
                count($a['operatorHeldAdministrators']), count($a['organizationsWithoutApprovedAdministrator'])
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->components->error($failure);
        }

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        foreach ($report['duplicateLinks'] as $link) {
            $this->line(sprintf('  organization #%d is linked to companies %s', $link['organization_id'], implode(', ', $link['company_ids'])));
        }

        foreach ($report['adminMemberships'] as $admin) {
            $this->line(sprintf('  admin: organization #%d  user #%d %s%s%s', $admin['organization_id'], $admin['user_id'], $admin['email'], $admin['operator'] ? '  [operator]' : '', $admin['active'] ? '' : '  [suspended]'));
        }

        foreach ($report['multiOrganizationUsers'] as $user) {
            $this->line(sprintf('  user #%d %s belongs to %d organizations', $user['user_id'], $user['email'], $user['organizations']));
        }

        if (! $failed) {
            $this->components->info('No blocking findings. Nothing was written.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
