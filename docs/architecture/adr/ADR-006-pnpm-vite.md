# ADR-006: Use PNPM as JavaScript Package Manager with Vite

**Date:** 2024-03-24
**Status:** Accepted

## Context

The project needs a JavaScript package manager and frontend build tool for Tailwind CSS v4 and any frontend JS components.

## Decision

Use **PNPM** as the package manager and **Vite** as the build tool (Laravel's default since Laravel 9).

## Rationale

- PNPM is significantly faster than npm due to its content-addressable store and hard-linking strategy
- PNPM's strict dependency resolution prevents phantom dependencies
- Vite provides near-instant HMR (hot module replacement) in development
- Vite's `@vitejs/plugin-react` and other plugins are compatible with the Tailwind v4 Vite plugin
- Laravel ships with `vite.config.js` and `@laravel/vite-plugin` out of the box
- PNPM is installable on the Docker image alongside Node.js 22 LTS

## Consequences

- `pnpm-lock.yaml` is committed (not `package-lock.json`)
- `node_modules/` is excluded from git
- `npm` commands in any documentation should be replaced with `pnpm`
- Docker image installs Node.js 22 LTS + PNPM globally
- cPanel deployment uses the Vite production build output (`public/build/`) — no Node.js required on the server
