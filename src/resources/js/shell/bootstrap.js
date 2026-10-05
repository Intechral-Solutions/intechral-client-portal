/*
 * EPIC-013 §14.4 / §15.3 — the single pre-paint shell bootstrap, shared by BOTH root views.
 *
 * Inlined verbatim, as the first thing in <head>, by layouts/partials/shell/bootstrap.blade.php, which
 * both the Blade root (layouts/app.blade.php) and the Inertia root (app.blade.php) include. It is a
 * classic script on purpose: a module script is deferred, so it could not run before first paint.
 * It must stay dependency-free, and use only syntax the supported browsers parse natively,
 * because nothing compiles it.
 *
 * It owns exactly two root attributes, and nothing else:
 *
 *   data-theme   'light' | 'dark' — localStorage['theme'] if it holds one of those two values,
 *                otherwise prefers-color-scheme. The same key the account menu's Appearance control
 *                writes on both renderers.
 *   data-drawer  'open' | 'collapsed' — the Operational panel state, resolved with EXACTLY the rules
 *                of resolvePanel() in hooks/use-panel-state.ts (parity asserted in
 *                shell/bootstrap.test.ts):
 *                  no server default (the workspace has no panel)  -> collapsed
 *                  XL (>= 1360px)  -> the remembered value, else the server default
 *                  L (1024-1359px) -> open if the workspace is pinned, else collapsed
 *                  M / S           -> collapsed (the pin and the remembered value are ignored)
 *                The remembered value is read under the surface key when the server stamped one
 *                (Direction D §5.3, EPIC-015 WP5: `projects.board`), else under the workspace key;
 *                the L pin is always per workspace and wins over any surface at L.
 *
 * The inputs are server-stamped on <html> — data-workspace (NavigationBuilder's currentWorkspace),
 * data-drawer-default (that workspace's presentation.operational.panel, absent when it has no panel)
 * and data-drawer-surface (presentation.operational.surface, absent unless the page is a surface with
 * its own default) — so this script never matches URLs or infers anything the server did not say
 * (L14). Geometry stays
 * in CSS; this only sets the attribute CSS reads. Authentication state is never read or written here.
 *
 * Every read is guarded: blocked storage, malformed JSON, a non-object map, unknown keys and values
 * outside the closed sets all fall through to the server default / the colour-scheme default. It
 * never writes storage: the renderers' runtime code owns writes.
 */
(function () {
    try {
        var root = document.documentElement;

        if (!root) {
            return;
        }

        var readItem = function (key) {
            try {
                return window.localStorage.getItem(key);
            } catch {
                return null;
            }
        };

        var readMap = function (key) {
            try {
                var parsed = JSON.parse(readItem(key) || '{}');

                return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
            } catch {
                return {};
            }
        };

        var matches = function (query) {
            try {
                return window.matchMedia(query).matches;
            } catch {
                return null;
            }
        };

        // ── Theme ──────────────────────────────────────────────────────────────────────────────
        var theme = readItem('theme');

        if (theme !== 'light' && theme !== 'dark') {
            theme = matches('(prefers-color-scheme: dark)') ? 'dark' : 'light';
        }

        root.setAttribute('data-theme', theme);

        // ── Operational panel ──────────────────────────────────────────────────────────────────
        var workspace = root.getAttribute('data-workspace');
        var serverDefault = root.getAttribute('data-drawer-default');
        var surface = root.getAttribute('data-drawer-surface');
        // panelPreferenceKey() in hooks/use-panel-state.ts.
        var preferenceKey = workspace ? (surface ?? workspace) : null;

        if (serverDefault !== 'open' && serverDefault !== 'collapsed') {
            serverDefault = null;
        }

        var drawer = 'collapsed';

        if (serverDefault !== null) {
            var pinned = workspace ? readMap('shell.operational.pin')[workspace] === true : false;
            // A browser that cannot answer is treated as XL (and so as L), as the React hook does.
            var extraLarge = matches('(min-width: 1360px)') !== false;
            var large = matches('(min-width: 1024px)') !== false;

            if (extraLarge) {
                var stored = preferenceKey
                    ? readMap('shell.operational.panel')[preferenceKey]
                    : null;

                drawer = stored === 'open' || stored === 'collapsed' ? stored : serverDefault;
            } else if (pinned && large) {
                // The L pin is Projects-wide: it docks every page of the workspace, whatever the
                // surface default or a surface choice made at XL says.
                drawer = 'open';
            }
        }

        root.setAttribute('data-drawer', drawer);
    } catch {
        // Never let a pre-paint convenience break the page.
    }
})();
