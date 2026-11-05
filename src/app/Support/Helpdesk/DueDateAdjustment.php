<?php

namespace App\Support\Helpdesk;

use App\Services\TicketService;
use Carbon\CarbonImmutable;

/**
 * An operator's request to change a ticket's working due date, carried with a reply or note (Release 1.0).
 *
 * Either a new override instant, or "restore" (clear the override so the original SLA deadline applies again). Whether it is
 * allowed (future, within the horizon, ticket still open, something to restore) is decided by
 * {@see TicketService::addReply()} against the committed row, not here.
 */
final readonly class DueDateAdjustment
{
    private function __construct(public ?CarbonImmutable $to) {}

    public static function set(CarbonImmutable $to): self
    {
        return new self($to);
    }

    public static function restore(): self
    {
        return new self(null);
    }

    public function isRestore(): bool
    {
        return $this->to === null;
    }
}
