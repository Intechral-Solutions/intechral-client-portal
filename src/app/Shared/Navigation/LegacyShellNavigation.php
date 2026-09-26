<?php

namespace App\Shared\Navigation;

/**
 * TEMPORARY compatibility seam for the pre-Direction-D Blade shell. Deleted with it in WP5.
 *
 * **The React side no longer uses this.** WP4 replaced `app-layout.tsx` with the Direction D shell,
 * which projects each workspace's `context` into the drawer, so `HandleInertiaRequests` stopped
 * sharing the flattened shape entirely. The one remaining consumer is
 * `layouts/partials/nav.blade.php`, which still renders a flat list of links and has no drawer to
 * project `context` into; it receives this through `ShellComposer` until WP5 replaces it with the
 * Blade shell partials. The projection is:
 *
 *   primary  — one entry per workspace, in canonical order.
 *   overflow — the contextual destinations that are not already reachable as a workspace entry,
 *              labelled "{Workspace} {Item}" so two workspaces' "Reports" stay distinguishable in
 *              one flat list.
 *
 * `overflow` is NOT the retired "Manage" group under another name. It is not derived from a
 * management concept, carries no label or heading, and is not audience-scoped: it is purely the
 * remainder that a shell without a drawer cannot otherwise reach. Its only job is that the reshape
 * removes no authorized destination from a shell it has not yet replaced. When WP5 renders the Blade
 * rail and drawer, this class and its one remaining view binding are deleted, and nothing in the
 * canonical contract changes.
 *
 * `Actions` items are excluded: "New project" is a workspace action, not a navigation destination,
 * and the pre-WP3 shell never offered it.
 */
final class LegacyShellNavigation
{
    /**
     * @param  array{currentWorkspace: string|null, workspaces: array<int, array<string, mixed>>}  $navigation
     * @return array<int, array{key: string, label: null, items: array<int, array<string, mixed>>}>
     */
    public static function groups(array $navigation): array
    {
        $primary = [];
        $overflow = [];

        foreach ($navigation['workspaces'] as $workspace) {
            $primary[] = self::link($workspace['key'], $workspace['label'], $workspace);

            foreach ($workspace['context'] as $section) {
                if ($section['kind'] === ContextKind::Actions->value) {
                    continue;
                }

                foreach ($section['items'] as $item) {
                    if ($item['href'] === $workspace['href']) {
                        continue;
                    }

                    $overflow[] = self::link(
                        $item['key'],
                        $workspace['label'].' '.$item['label'],
                        $item,
                    );
                }
            }
        }

        return array_values(array_filter([
            $primary === [] ? null : ['key' => 'primary', 'label' => null, 'items' => $primary],
            $overflow === [] ? null : ['key' => 'overflow', 'label' => null, 'items' => $overflow],
        ]));
    }

    /**
     * The legacy item shape, minus the three fields A1.9 proved dead (`method`, `activePatterns`,
     * `children`). Dropping `activePatterns` also stops route-name patterns being serialized to the
     * client, which the reshape removes from the canonical payload as well.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private static function link(string $key, string $label, array $source): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'href' => $source['href'],
            'visit' => $source['visit'],
            'isActive' => $source['isActive'],
        ];
    }
}
