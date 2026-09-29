{{--
    EPIC-013 §14.4 / §15.3 — the single pre-paint shell bootstrap, included by BOTH root views
    (layouts/app.blade.php for Blade, app.blade.php for Inertia). It replaces the two theme scripts the
    roots used to carry separately, and must sit before @vite so no stylesheet blocks it (A1.7 req. 2).

    The source is resources/js/shell/bootstrap.js, inlined verbatim (minus its explanatory header) so
    there is one implementation for both renderers and a Vitest suite can execute the exact same file.
    It reads the server-stamped html[data-workspace] / html[data-drawer-default] and localStorage, and
    sets html[data-theme] / html[data-drawer]. It carries no server data and no authentication state.
--}}
<script>{!! preg_replace('#^/\*.*?\*/\s*#s', '', (string) file_get_contents(resource_path('js/shell/bootstrap.js'))) !!}</script>
