<?php

namespace App\Console\Commands;

use App\Services\ProjectIntegrityAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('projects:audit-integrity {--json : Print the counts as JSON}')]
#[Description('Read-only report of legacy project/task rows the EPIC-011E hardening would reject (no data is modified)')]
class AuditProjectIntegrity extends Command
{
    public function handle(ProjectIntegrityAudit $audit): int
    {
        $counts = $audit->run();

        if ($this->option('json')) {
            $this->line(json_encode($counts, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'Rows'],
            collect($counts)->map(fn (int $rows, string $check) => [$check, $rows])->values()->all(),
        );
        $this->line('Read-only: nothing was modified.');

        return self::SUCCESS;
    }
}
