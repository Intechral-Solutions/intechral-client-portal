<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    public function invite(string $email, User $invitedBy): Invitation
    {
        // Invalidate any existing pending invitation for this email
        Invitation::where('email', $email)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        $invitation = Invitation::create([
            'email' => strtolower(trim($email)),
            'token' => Str::random(64),
            'invited_by' => $invitedBy->id,
            'status' => 'pending',
            'expires_at' => now()->addHours(48),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationNotification($invitation));

        return $invitation;
    }

    public function findValid(string $token): ?Invitation
    {
        $invitation = Invitation::where('token', $token)->first();

        if (! $invitation || ! $invitation->isPending()) {
            return null;
        }

        return $invitation;
    }

    public function accept(Invitation $invitation, User $user): void
    {
        $invitation->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $user->update(['invitation_id' => $invitation->id]);
    }
}
