<?php

namespace App\Actions\Fortify;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Services\DatabaseSessionManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly DatabaseSessionManager $sessions) {}

    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->after(function ($validator) use ($user, $input) {
            if (isset($input['password']) && $this->wasRecentlyUsed($user, $input['password'])) {
                $validator->errors()->add('password', 'You cannot reuse any of your last 5 passwords.');
            }
        })->validateWithBag('updatePassword');

        $this->storeHistory($user);

        $user->forceFill(['password' => Hash::make($input['password'])])->save();

        $this->sessions->revokeOtherSessions($user, session()->getId());
    }

    private function wasRecentlyUsed(User $user, string $newPassword): bool
    {
        return $user->passwordHistories()
            ->latest()
            ->take(5)
            ->get()
            ->contains(fn ($history) => Hash::check($newPassword, $history->password));
    }

    private function storeHistory(User $user): void
    {
        PasswordHistory::create([
            'user_id' => $user->id,
            'password' => $user->password,
            'created_at' => now(),
        ]);

        // Keep only last 5
        $keepIds = $user->passwordHistories()->take(5)->pluck('id');
        $user->passwordHistories()->whereNotIn('id', $keepIds)->delete();
    }
}
