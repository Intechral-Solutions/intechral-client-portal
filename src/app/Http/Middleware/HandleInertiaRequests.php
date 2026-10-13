<?php

namespace App\Http\Middleware;

use App\Shared\Navigation\NavigationBuilder;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
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
            // The presentation family the shell should render, resolved server-side (EPIC-013 §23,
            // EPIC-017 P1) by the one resolver. It names a presentation, never a role, and is lazy so a
            // partial reload that does not ask for it resolves nothing.
            'shell' => fn () => [
                'presentation' => $this->presentation($request)->value,
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
                // EPIC-014 §15.2: the per-task outcome of POST /tasks/bulk, echoing only the
                // submitted ids ({action, succeeded, notPermitted, configurationError, failed}).
                'bulk' => fn () => $request->session()->get('bulk'),
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
            $this->navigation = app(NavigationBuilder::class)->build($request, $this->presentation($request));
        }

        return $this->navigation;
    }

    /**
     * The resolver memoizes on the request, so `shell` and `navigation` always agree and the answer
     * is computed once per request.
     */
    private function presentation(Request $request): ShellPresentation
    {
        return app(ShellPresentationResolver::class)->forRequest($request);
    }
}
