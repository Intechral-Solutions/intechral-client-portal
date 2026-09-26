<?php

namespace App\View\Composers;

use App\Shared\Navigation\LegacyShellNavigation;
use App\Shared\Navigation\NavigationBuilder;
use App\Support\Initials;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Binds the shell's server data to the Blade shell partials (EPIC-013 §12.3 rule 11, §14.4).
 *
 * Before WP3 the view layer instantiated `NavigationBuilder` itself, inside `partials/nav.blade.php`.
 * That made a Blade view a second entry point into navigation truth. The composer replaces it, so
 * both renderers now receive the same payload from the same builder: React through
 * `HandleInertiaRequests::share`, Blade through here.
 *
 * WP5 rebinds this to the new `layouts.partials.shell.*` partials; the payload does not change.
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
            // TEMPORARY, as in the Inertia prop: the flat shape `partials/nav.blade.php` still
            // renders. Removed with that partial in WP5.
            'navigationLegacy' => LegacyShellNavigation::groups($navigation),
            'shell' => ['presentation' => 'operational'],
            'shellUser' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => ['initials' => Initials::from($user->name), 'url' => null],
            ] : null,
        ]);
    }
}
