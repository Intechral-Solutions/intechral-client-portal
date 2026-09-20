<?php

namespace App\Services;

use App\Exceptions\InvitationEmailMismatchException;
use App\Exceptions\InvitationUnavailableException;
use App\Models\Invitation;
use App\Models\PasswordHistory;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class InvitationService
{
    public function invite(string $email, User $invitedBy): Invitation
    {
        $email = Str::lower(trim($email));

        Invitation::where('email', $email)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        $invitation = Invitation::create([
            'email' => $email,
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

    public function acceptWithPassword(string $token, string $name, string $password): User
    {
        return $this->acceptAtomically($token, function (Invitation $invitation) use ($name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'invitation_id' => $invitation->id,
                'invited_by' => $invitation->invited_by,
            ]);

            $user->assignRole('user');
            PasswordHistory::create([
                'user_id' => $user->id,
                'password' => $user->password,
                'created_at' => now(),
            ]);

            return $user;
        });
    }

    /** @param array{name: ?string, email: ?string, provider_id: string, token: ?string, refresh_token: ?string, token_expires_at: mixed} $identity */
    public function acceptWithSocialAccount(string $token, string $provider, array $identity): User
    {
        return $this->acceptAtomically($token, function (Invitation $invitation) use ($provider, $identity) {
            $providerEmail = Str::lower(trim((string) $identity['email']));

            if ($providerEmail === '' || $providerEmail !== Str::lower(trim($invitation->email))) {
                throw new InvitationEmailMismatchException;
            }

            $user = User::create([
                'name' => $identity['name'] ?: $invitation->email,
                'email' => Str::lower(trim($invitation->email)),
                'password' => null,
                'invitation_id' => $invitation->id,
                'invited_by' => $invitation->invited_by,
            ]);

            $user->assignRole('user');
            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $identity['provider_id'],
                'token' => $identity['token'],
                'refresh_token' => $identity['refresh_token'],
                'token_expires_at' => $identity['token_expires_at'],
            ]);

            return $user;
        });
    }

    private function acceptAtomically(string $token, callable $createUser): User
    {
        try {
            return DB::transaction(function () use ($token, $createUser) {
                $invitation = Invitation::where('token', $token)->lockForUpdate()->first();

                if (! $invitation || ! $invitation->isPending()) {
                    throw new InvitationUnavailableException;
                }

                if (User::where('email', Str::lower(trim($invitation->email)))->exists()) {
                    throw new InvitationUnavailableException;
                }

                $user = $createUser($invitation);
                $invitation->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);

                return $user;
            });
        } catch (InvitationEmailMismatchException $exception) {
            throw $exception;
        } catch (InvitationUnavailableException $exception) {
            $this->expirePendingInvitation($token);

            throw new InvitationUnavailableException(previous: $exception);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyConflict($exception)) {
                throw $exception;
            }

            $this->expirePendingInvitation($token);

            throw new InvitationUnavailableException(previous: $exception);
        }
    }

    private function expirePendingInvitation(string $token): void
    {
        Invitation::where('token', $token)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }

    private function isDuplicateKeyConflict(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23000' && $driverCode === 1062;
    }
}
