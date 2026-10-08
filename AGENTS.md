# Agent guidance

Durable rules for coding agents working in this repository. The design contract is
[`docs/design/direction-d-design-system.md`](docs/design/direction-d-design-system.md); epics and the roadmap are in `docs/`.

## Checks

- After any change under `src/resources/js`, run the **frontend** lint from `src/`: `cd src && npm run lint`
  (or `./dev check --no-php` for the whole frontend gate). The repository-root `npm run lint` is Pint for PHP, not this.
- Blade views are guarded by `tests/Unit/Configuration/BladeThemeGuardTest.php`; run it after any `resources/views` change.
- `./dev check` is the full local gate. `./dev test:e2e` runs the browser suite.

## Design system (React)

- **Shared primitives own their visual styling.** Callers of `components/ui/*` add layout (margin, width, flex/grid placement),
  not colour, typography, spacing, shape, effects, or height/size. Need a different look? Use or add a **variant**.
- **Use the Direction D semantic utilities** (`text-text`, `text-danger`, `bg-surface`, `border-rule`, `bg-ink` …), never raw palette
  colours (`bg-red-500`) or literals.
- **Runtime values** (a measured width, a drag transform) are legitimate: set a CSS custom property inline and consume it from a
  static class (`w-(--progress-value)`), or use the narrow documented exception.
- Keep class names **static**: no `` `bg-${x}` ``. Tailwind only builds classes it can read.

## `shadcn/*` lint errors

`@shadcn/lint` enforces the rules above as ESLint errors (`shadcn/no-restyle`, `no-raw-colors`, `no-arbitrary-values`,
`no-inline-styles`, `no-unknown-classes`, `require-static-classes`). **Treat them as Direction D architecture signals, not noise.**
Read the message: it names the component's variants and the file that owns the look. Do not silence a rule, add a suppression, or
widen a contract without understanding the component or theme contract behind it, and say why in the change.

Existing accepted findings are recorded in `src/eslint-suppressions.json`. It must only shrink: fix a finding, then run
`npx eslint 'resources/js/**/*.{ts,tsx}' vite.config.ts playwright.config.ts --prune-suppressions` from `src/`. Never use broad
`eslint-disable` comments to make a new violation pass. Details: [`docs/testing/ci.md`](docs/testing/ci.md#react-design-system-lint-shadcnlint).
