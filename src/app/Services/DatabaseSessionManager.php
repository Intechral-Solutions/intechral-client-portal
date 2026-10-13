<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

final class DatabaseSessionManager
{
    /**
     * @return array<int, array{isCurrent: bool, ipAddress: string|null, userAgent: string|null, lastActiveAt: string}>
     */
    public function forUser(User $user, string $currentSessionId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($session) => [
                'isCurrent' => hash_equals((string) $session->id, $currentSessionId),
                'ipAddress' => $session->ip_address,
                'userAgent' => $this->describeUserAgent($session->user_agent),
                'lastActiveAt' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
            ])
            ->all();
    }

    public function revokeOtherSessions(User $user, string $currentSessionId): int
    {
        return DB::transaction(function () use ($user, $currentSessionId) {
            $user->setRememberToken(Str::random(60));
            $user->save();

            if (config('session.driver') !== 'database') {
                return 0;
            }

            return DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $currentSessionId)
                ->delete();
        });
    }

    /**
     * Sign the user out everywhere: rotate the remember-me token (so no remembered login can come back), delete any
     * outstanding password-reset token (so a link issued earlier cannot be used after a reactivation; R0.9-1C review
     * finding L-1) and delete every stored session. Used by account suspension and reactivation (Release 0.9, H3).
     *
     * Unlike the methods above it fails closed (finding L-2): with any session driver but `database` the sessions
     * cannot be deleted, so it throws before changing anything, and the caller's transaction rolls back. The database
     * driver is a Release 0.9 deployment prerequisite (docs/architecture/account-lifecycle.md).
     *
     * @throws RuntimeException
     */
    public function revokeAll(User $user): int
    {
        if (config('session.driver') !== 'database') {
            throw new RuntimeException('Signing a user out everywhere requires SESSION_DRIVER=database (a Release 0.9 '
                .'deployment prerequisite); the account was not changed.');
        }

        return DB::transaction(function () use ($user) {
            $user->setRememberToken(Str::random(60));
            $user->save();

            Password::broker(config('fortify.passwords'))->deleteToken($user);

            return DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        });
    }

    private function describeUserAgent(?string $userAgent): ?string
    {
        if (blank($userAgent)) {
            return null;
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser && $platform) {
            return "$browser on $platform";
        }

        return $browser ?? $platform ?? mb_strimwidth($userAgent, 0, 80, '...');
    }
}
