# System Overview

## High-Level Architecture

```
┌──────────────────────────────────────────────────────────┐
│                     Browser / Client                      │
└───────────────────────────┬──────────────────────────────┘
                            │ HTTPS
┌───────────────────────────▼──────────────────────────────┐
│                  Nginx (reverse proxy)                    │
│              Serves static assets & PHP-FPM              │
└───────────────────────────┬──────────────────────────────┘
                            │ FastCGI
┌───────────────────────────▼──────────────────────────────┐
│              PHP-FPM 8.5 — Laravel 13                    │
│                                                          │
│  ┌─────────────┐  ┌──────────────┐  ┌────────────────┐  │
│  │   HTTP       │  │   Queue      │  │   Scheduler    │  │
│  │  (Routes +  │  │  Workers     │  │  (artisan      │  │
│  │  Middleware)│  │  (jobs/mail) │  │   schedule:run)│  │
│  └─────────────┘  └──────────────┘  └────────────────┘  │
└────┬──────────┬────────────┬────────────────────────────-┘
     │          │            │
┌────▼───┐ ┌───▼────┐ ┌─────▼──────┐
│MariaDB │ │ Redis  │ │  Storage   │
│  10    │ │(cache/ │ │(local disk │
│        │ │session/│ │ or S3)     │
│        │ │queues) │ │            │
└────────┘ └────────┘ └────────────┘
```

## Request Lifecycle

1. Browser sends HTTPS request to Nginx
2. Nginx routes PHP requests to PHP-FPM via FastCGI
3. Laravel's `public/index.php` bootstraps the framework
4. Middleware chain: `TrustProxies` → `HandleCors` → `Authenticate` → `Authorize` → controller
5. Controller calls services / repositories
6. Response returned (HTML via Blade or JSON via API)

## Key Design Principles

- **Thin controllers, fat services** — business logic lives in service classes, not controllers
- **Repository pattern for data access** — repositories abstract Eloquent queries
- **Queue everything async** — emails, PDF generation, and third-party API calls go through queues
- **Events & listeners for side effects** — e.g., `UserInvited` event triggers email queue job
- **Policy-based authorization** — Laravel Policies map to Spatie permissions

## Module Boundaries

Each platform module (tickets, projects, billing, etc.) is organized as a Laravel "module" under `app/Modules/`:

```
app/
  Modules/
    Auth/
    Tickets/
    Projects/
    Billing/
    TimeTracking/
    CRM/
    CMS/
  Shared/           ← cross-module utilities
```

This is not a micro-services architecture — it is a well-organized monolith with clear internal boundaries.

## cPanel Deployment

On cPanel:
- PHP 8.5 is the preferred runtime; on the Namecheap host it is selected **per domain** (MultiPHP Manager for the staging or production hostname only). The account-wide CloudLinux "Select PHP Version" extension and option toggles apply to every site on the account and are not used to prepare a release; the web and CLI extensions are verified directly, since Composer's generated platform check covers only the PHP version and architecture ([Staging First Install](../operations/staging-first-install.md#phase-1-staging-resources-g1-each-sub-gate-separate)). PHP 8.3 is the tested fallback (`composer.json` keeps `^8.3`; see [docker-setup.md](docker-setup.md#php-version)). OPcache is built into PHP 8.5, so the extension list does not include it
- Release 0.9 target host (Namecheap Stellar Plus): PHP 8.5.11 with the required extensions, verified over SSH by the owner. Hosting settings are not changed by the repository
- The document root is the `public/` directory of the release selected by the `current` symlink (`<deployment root>/current/public`); it is read back from the control panel rather than assumed ([Staging First Install, phase 8](../operations/staging-first-install.md#phase-8-select-the-release-and-serve-it-g5))
- MariaDB 11.4 database created via cPanel (Namecheap runs 11.4.13; development and CI run the same version). The host's server defaults are **not** the application's profile (MyISAM, latin1, non-strict `sql_mode`, an Eastern-time `SYSTEM` time zone were reported by the 2026-10-19 probe), so `config/database.php` fixes InnoDB and a `+00:00` session, `php artisan database:verify` checks the result, and a production migration refuses a database outside the profile: [Database Profile](database-profile.md)
- Background work (decided for Release 0.9): two five-minute cron entries (`*/5` and `2-59/5`) that call one versioned wrapper, `scripts/cron/portal-cron.sh`, which runs a **bounded** database-queue drain and one scheduler tick under `flock` and `timeout` with the explicit PHP 8.5 CLI. Namecheap Stellar Plus allows no more often than every 5 minutes and at most 5 concurrent cron jobs; there is no persistent worker. The old `php artisan schedule:run >> /dev/null 2>&1` line is **not** used (it discards failures). Design and operation: [Cron Execution](cron-execution.md); installation: [Staging First Install](../operations/staging-first-install.md)
- Release layout: an immutable release directory per build, selected by a `current` symlink (swapped with `mv -T`), with `.env`, `storage`, cron locks and logs in a shared directory. The release is built off-host (the account has no Composer or Node): [Release Build](../operations/release-build.md)
- `.env` configured with production credentials, kept once in a shared directory (mode 0600) and linked into each release; never committed and never part of a release archive
