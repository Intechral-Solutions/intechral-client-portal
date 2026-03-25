# ADR-002: Use Tailwind CSS v4 with CSS-Native Theming

**Date:** 2024-03-24
**Status:** Accepted

## Context

The UI requires a utility-first CSS framework with:
- Light/dark mode support
- Extensible theme system (custom themes in future)
- Good Blade template integration
- Active maintenance

## Decision

Use **Tailwind CSS v4** with CSS custom properties (`@theme`) for theming, built via **Vite**.

## Rationale

- Tailwind v4 moves theming to native CSS custom properties, removing the need for `tailwind.config.js` theme duplication
- `data-theme` attribute on `<html>` enables clean theme switching without JavaScript framework dependency
- CSS custom properties cascade naturally, making theme extension trivial
- Vite provides fast HMR in development and optimised builds for production

## Consequences

- Tailwind v4 has a different config format from v3 — the team must use `@import "tailwindcss"` and `@theme {}` blocks in CSS
- Some v3 plugins may not be compatible; community plugins should be verified before adoption
- PostCSS is replaced by the new Tailwind v4 Vite plugin (no `postcss.config.js` needed)
