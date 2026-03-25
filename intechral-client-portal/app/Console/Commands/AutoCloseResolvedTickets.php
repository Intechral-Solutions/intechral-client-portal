<?php

namespace App\Console\Commands;

use App\Services\TicketService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tickets:auto-close {--hours=72 : Idle hours after resolve before auto-closing}')]
#[Description('Auto-close resolved tickets that have been idle for the configured number of hours')]
class AutoCloseResolvedTickets extends Command
{
    public function handle(TicketService $service): int
    {
        $hours = (int) $this->option('hours');
        $count = $service->autoCloseResolved($hours);

        $this->info("Auto-closed {$count} resolved ticket(s) idle for more than {$hours} hours.");

        return Command::SUCCESS;
    }
}
