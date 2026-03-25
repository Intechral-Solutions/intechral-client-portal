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
│              PHP-FPM 8.3 — Laravel 11                    │
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
- PHP-FPM 8.3 is selected via the "Select PHP Version" tool
- `public/` is set as the document root
- MariaDB 10 database created via cPanel
- Cron job added: `* * * * * php /path/to/portal/artisan schedule:run >> /dev/null 2>&1`
- Queue worker managed via cPanel's "Cron Jobs" or a supervisor-equivalent
- `.env` configured with production credentials
