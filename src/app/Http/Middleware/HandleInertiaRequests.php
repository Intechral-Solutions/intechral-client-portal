<?php

namespace App\Http\Middleware;

use App\Shared\Navigation\NavigationBuilder;
use App\Support\Initials;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    private ?Request $navigationRequest = null;

    /** @var array{currentWorkspace: string|null, workspaces: array<int, array<string, mixed>>}|null */
    private ?array $navigation = null;

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
                    // EPIC-013 §12.5. `url` is null: nothing in the application stores a photo yet,
                    // and the initials are derived server-side so both renderers agree.
                    'avatar' => [
                        'initials' => Initials::from($user->name),
                        'url' => null,
                    ],
                ] : null,
                'permissions' => fn () => $user
                    ? $user->getAllPermissions()->pluck('name')->sort()->values()->all()
                    : [],
            ],
            // The presentation family the shell should render, resolved server-side (EPIC-013 §23).
            // Always 'operational' in this epic: it names a presentation, never a role.
            'shell' => [
                'presentation' => 'operational',
            ],
            'navigation' => fn () => $this->navigation($request),
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

    /**
     * One builder invocation per request (EPIC-013 §12.3 rule 11). Keyed on the request so a reused
     * middleware instance can never serve another request's navigation.
     *
     * @return array{currentWorkspace: string|null, workspaces: array<int, array<string, mixed>>}
     */
    private function navigation(Request $request): array
    {
        if ($this->navigationRequest !== $request || $this->navigation === null) {
            $this->navigationRequest = $request;
            $this->navigation = app(NavigationBuilder::class)->build($request);
        }

        return $this->navigation;
    }
}
