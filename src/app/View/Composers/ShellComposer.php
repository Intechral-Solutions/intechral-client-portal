<?php

namespace App\View\Composers;

use App\Shared\Navigation\NavigationBuilder;
use App\Support\Initials;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Binds the shell's server data to the Blade Direction D shell (EPIC-013 §12.3 rule 11, §14.4).
 *
 * Both renderers receive the same payload from the same builder: React through
 * `HandleInertiaRequests::share`, Blade through here. The Blade shell renders `navigation` exactly as
 * given — it filters nothing, matches no URL and computes no active state (L14).
 *
 * Bound to `layouts.app` itself rather than to the shell partials (a WP5 refinement of §14.4): the
 * root `<html>` element needs `currentWorkspace` and the panel default for the pre-paint bootstrap
 * (§15.3 step 1), and one binding on the layout keeps the builder at one invocation per request while
 * every `layouts.partials.shell.*` include inherits the data.
 */
final class ShellComposer
{
    public function __construct(
        private readonly NavigationBuilder $builder,
        private readonly Request $request,
    ) {}

    public function compose(View $view): void
    {
        $navigation = $this->builder->build($this->request);
        $user = $this->request->user();

        $view->with([
            'navigation' => $navigation,
            'shell' => ['presentation' => 'operational'],
            'shellRoot' => self::rootState($navigation),
            'shellWorkspace' => self::currentWorkspace($navigation),
            'shellUser' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => ['initials' => Initials::from($user->name), 'url' => null],
            ] : null,
        ]);
    }

    /**
     * The server-stamped inputs of the shared pre-paint bootstrap (§15.3), used by both roots.
     *
     * `workspace` is the builder's `currentWorkspace`. `drawerDefault` is that workspace's
     * `presentation.operational.panel` — and null when its `context` is empty, because an empty
     * context means no contextual panel for any presentation (§12.3 rule 6). `drawerSurface` is
     * `presentation.operational.surface` (Direction D §5.3, EPIC-015 WP5): the key a choice on this
     * surface is remembered under, null when the workspace key is. This is the same projection
     * `OperatorShell` makes on the React side; it reads the payload and decides nothing.
     *
     * @param  array{currentWorkspace?: string|null, workspaces?: array<int, array<string, mixed>>}|null  $navigation
     * @return array{workspace: string|null, drawerDefault: string|null, drawerSurface: string|null, hasPanel: bool}
     */
    public static function rootState(?array $navigation): array
    {
        $workspace = self::currentWorkspace($navigation);
        $hasPanel = $workspace !== null && ($workspace['context'] ?? []) !== [];
        $default = $hasPanel ? ($workspace['presentation']['operational']['panel'] ?? null) : null;
        $surface = $hasPanel ? ($workspace['presentation']['operational']['surface'] ?? null) : null;

        return [
            'workspace' => $workspace['key'] ?? null,
            'drawerDefault' => $default,
            'drawerSurface' => $surface,
            'hasPanel' => $hasPanel,
        ];
    }

    /**
     * The workspace the server marked current, taken from the payload by key — never matched from the
     * URL. Null when the route belongs to no workspace.
     *
     * @param  array{currentWorkspace?: string|null, workspaces?: array<int, array<string, mixed>>}|null  $navigation
     * @return array<string, mixed>|null
     */
    public static function currentWorkspace(?array $navigation): ?array
    {
        $key = $navigation['currentWorkspace'] ?? null;

        foreach ($navigation['workspaces'] ?? [] as $candidate) {
            if ($key !== null && ($candidate['key'] ?? null) === $key) {
                return $candidate;
            }
        }

        return null;
    }
}
