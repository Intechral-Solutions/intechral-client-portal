<?php

namespace App\Actions\Fortify;

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->after(function ($validator) use ($user, $input) {
            if (isset($input['password']) && $this->wasRecentlyUsed($user, $input['password'])) {
                $validator->errors()->add('password', 'You cannot reuse any of your last 5 passwords.');
            }
        })->validate();

        $this->storeHistory($user);

        $user->forceFill(['password' => Hash::make($input['password'])])->save();

        // Terminate all sessions on password reset (OWASP), when using DB session driver
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
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
            'user_id'    => $user->id,
            'password'   => $user->password,
            'created_at' => now(),
        ]);

        $keepIds = $user->passwordHistories()->take(5)->pluck('id');
        $user->passwordHistories()->whereNotIn('id', $keepIds)->delete();
    }
}
