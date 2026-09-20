<?php

namespace App\Http\Middleware;

use App\Shared\Navigation\NavigationBuilder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'permissions' => fn () => $user
                    ? $user->getAllPermissions()->pluck('name')->sort()->values()->all()
                    : [],
            ],
            'navigation' => fn () => app(NavigationBuilder::class)->build($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => match ($request->session()->get('status')) {
                    'profile-information-updated' => 'Profile information updated.',
                    'password-updated' => 'Password updated.',
                    'recovery-codes-generated' => 'New recovery codes generated.',
                    default => $request->session()->get('status'),
                },
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
