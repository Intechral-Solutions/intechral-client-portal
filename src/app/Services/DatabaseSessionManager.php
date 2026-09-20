<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
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
