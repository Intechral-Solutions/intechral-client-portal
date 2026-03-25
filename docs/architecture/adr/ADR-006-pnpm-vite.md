# ADR-006: Use npm as JavaScript Package Manager with Vite

**Date:** 2024-03-24
**Status:** Accepted (updated 2026-03-24)

## Context

The project needs a JavaScript package manager and frontend build tool for Tailwind CSS v4 and any frontend JS components.

## Decision

Use **npm** as the package manager and **Vite** as the build tool (Laravel's default since Laravel 9).

## Rationale

- npm ships with Node.js — no additional global install required in the Docker image or on developer machines
- Vite provides near-instant HMR (hot module replacement) in development
- The Tailwind CSS v4 Vite plugin (`@tailwindcss/vite`) works natively with npm
- Laravel ships with `vite.config.js` and `@laravel/vite-plugin` out of the box
- `package-lock.json` provides deterministic installs without an additional lockfile format

## Consequences

- `package-lock.json` is committed (not `pnpm-lock.yaml`)
- `node_modules/` is excluded from git
- Docker image installs Node.js 22 LTS; npm is included automatically
- cPanel deployment uses the Vite production build output (`public/build/`) — no Node.js required on the server
