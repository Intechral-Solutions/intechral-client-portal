<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Events\NotificationSending;

/**
 * Release 0.9 (H3): no notification reaches a suspended user. `NotificationSending` fires immediately before each
 * channel delivers, inside the queued job for a queued notification, so a notification dispatched before the
 * suspension is re-checked at send time. Suspension is read fresh from the database rather than from the
 * (possibly serialized, possibly stale) notifiable. Notifications routed to an address rather than a user (the
 * invitation) are unaffected. A future security notice that must reach a suspended user needs an explicit,
 * reviewed exemption here.
 */
final class SkipSuspendedRecipients
{
    public function handle(NotificationSending $event): ?bool
    {
        if (! $event->notifiable instanceof User) {
            return null;
        }

        $suspended = User::query()
            ->whereKey($event->notifiable->getKey())
            ->whereNotNull('disabled_at')
            ->exists();

        return $suspended ? false : null;
    }
}
